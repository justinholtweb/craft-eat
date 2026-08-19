<?php

namespace justinholtweb\eat;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Product;
use craft\events\ElementEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\eat\models\Settings;
use justinholtweb\eat\services\Delivery;
use justinholtweb\eat\services\Feeds;
use justinholtweb\eat\services\Generator;
use justinholtweb\eat\services\Merchant;
use justinholtweb\eat\services\Resolver;
use justinholtweb\eat\services\Runs;
use justinholtweb\eat\services\Taxonomy;
use justinholtweb\eat\twig\EatVariable;
use yii\base\Event;

/**
 * Eat — product feeds for Craft Commerce.
 *
 * @property-read Feeds $feeds
 * @property-read Generator $generator
 * @property-read Resolver $resolver
 * @property-read Delivery $delivery
 * @property-read Runs $runs
 * @property-read Taxonomy $taxonomy
 * @property-read Merchant $merchant
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'eat';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'feeds' => ['class' => Feeds::class],
                'generator' => ['class' => Generator::class],
                'resolver' => ['class' => Resolver::class],
                'delivery' => ['class' => Delivery::class],
                'runs' => ['class' => Runs::class],
                'taxonomy' => ['class' => Taxonomy::class],
                'merchant' => ['class' => Merchant::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();
        $this->_registerSiteRoutes();

        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerProductSaveHandler();
        $this->_registerRequestScheduler();
    }

    /**
     * Whether Commerce is here at all. Eat installs happily without it — it just has nothing to
     * feed on until Commerce is installed and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getFeeds(): Feeds
    {
        return $this->get('feeds');
    }

    public function getGenerator(): Generator
    {
        return $this->get('generator');
    }

    public function getResolver(): Resolver
    {
        return $this->get('resolver');
    }

    public function getDelivery(): Delivery
    {
        return $this->get('delivery');
    }

    public function getRuns(): Runs
    {
        return $this->get('runs');
    }

    public function getTaxonomy(): Taxonomy
    {
        return $this->get('taxonomy');
    }

    public function getMerchant(): Merchant
    {
        return $this->get('merchant');
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('eat/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('eat', 'Eat');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('eat-manageFeeds')) {
            $subNav['feeds'] = [
                'label' => Craft::t('eat', 'Feeds'),
                'url' => 'eat/feeds',
            ];
        }

        if ($this->isPro() && $user->checkPermission('eat-viewRuns')) {
            $subNav['runs'] = [
                'label' => Craft::t('eat', 'Runs'),
                'url' => 'eat/runs',
            ];
        }

        if ($user->checkPermission('eat-manageTaxonomy')) {
            $subNav['taxonomy'] = [
                'label' => Craft::t('eat', 'Taxonomy'),
                'url' => 'eat/taxonomy',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('eat', 'Settings'),
                'url' => 'settings/plugins/eat',
            ];
        }

        if (!$subNav) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('eat', EatVariable::class);
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('eat', 'Eat'),
                    'permissions' => [
                        'eat-manageFeeds' => [
                            'label' => Craft::t('eat', 'Manage product feeds'),
                            'nested' => [
                                'eat-generateFeeds' => ['label' => Craft::t('eat', 'Generate feeds')],
                            ],
                        ],
                        'eat-viewRuns' => ['label' => Craft::t('eat', 'View the run log')],
                        'eat-manageTaxonomy' => ['label' => Craft::t('eat', 'Manage taxonomy mapping')],
                    ],
                ];
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['eat'] = 'eat/feeds/index';
                $event->rules['eat/feeds'] = 'eat/feeds/index';
                $event->rules['eat/feeds/new'] = 'eat/feeds/edit';
                $event->rules['eat/feeds/<feedId:\d+>'] = 'eat/feeds/edit';
                $event->rules['eat/runs'] = 'eat/runs/index';
                $event->rules['eat/runs/<runId:\d+>'] = 'eat/runs/detail';
                $event->rules['eat/taxonomy'] = 'eat/taxonomy/index';
                $event->rules['eat/taxonomy/<channel:{handle}>'] = 'eat/taxonomy/index';
            }
        );
    }

    /**
     * The live feed route. Registered even when Commerce is missing so the URL 404s honestly
     * instead of falling through to a template.
     */
    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['eat/feed/<handle:[^\/]+>'] = 'eat/feed/index';
            }
        );
    }

    /**
     * A product save marks its feeds due. The handler is typed `ElementEvent` — Craft passes an
     * ElementEvent here, and a handler typed anything else fatals *every* element save on the site.
     */
    private function _registerProductSaveHandler(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            static function(ElementEvent $event) {
                $element = $event->element;

                if (!$element instanceof Product || $element->getIsDraft() || $element->getIsRevision()) {
                    return;
                }

                $plugin = Plugin::getInstance();

                if ($plugin === null || !$plugin->isPro()) {
                    return;
                }

                foreach ($plugin->getFeeds()->getEnabledFeeds() as $feed) {
                    if (!$feed->regenerateOnSave) {
                        continue;
                    }

                    // Queueing is debounced, so resaving 5,000 products makes one job per feed.
                    $plugin->getFeeds()->queue($feed, 'save');
                }
            }
        );
    }

    /**
     * For sites without cron: after a front-end request has been sent, queue anything due. Throttled
     * so it costs one cache read per request, not one query.
     */
    private function _registerRequestScheduler(): void
    {
        if (!Craft::$app instanceof WebApplication) {
            return;
        }

        Event::on(
            WebApplication::class,
            WebApplication::EVENT_AFTER_REQUEST,
            static function() {
                $plugin = Plugin::getInstance();

                if ($plugin === null || !$plugin->getSettings()->scheduleOnRequest) {
                    return;
                }

                $request = Craft::$app->getRequest();

                if ($request->getIsConsoleRequest() || $request->getIsCpRequest() || $request->getIsActionRequest()) {
                    return;
                }

                $cache = Craft::$app->getCache();

                if ($cache->get('eat:scheduled')) {
                    return;
                }

                $cache->set('eat:scheduled', true, 60);

                $mutex = Craft::$app->getMutex();

                if (!$mutex->acquire('eat:schedule', 0)) {
                    return;
                }

                try {
                    $plugin->getFeeds()->queueDue('schedule');
                } catch (\Throwable $e) {
                    Craft::warning('Eat could not queue due feeds: ' . $e->getMessage(), 'eat');
                } finally {
                    $mutex->release('eat:schedule');
                }
            }
        );
    }
}
