<?php

namespace justinholtweb\eat\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use craft\web\assets\admintable\AdminTableAsset;
use justinholtweb\eat\channels\Registry;
use justinholtweb\eat\helpers\Attributes;
use justinholtweb\eat\helpers\Modifiers;
use justinholtweb\eat\helpers\Value;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\models\Mapping;
use justinholtweb\eat\Plugin;
use justinholtweb\eat\services\Delivery;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Managing feeds.
 */
class FeedsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('eat-manageFeeds');

        if (!Plugin::commerceIsReady()) {
            throw new ForbiddenHttpException('Craft Commerce is not installed.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $feeds = $plugin->getFeeds()->getAllFeeds();
        $runs = $plugin->getRuns()->getLastRuns();
        $channels = Registry::all();
        $tableData = [];

        foreach ($feeds as $feed) {
            $run = $runs[$feed->id] ?? null;

            $tableData[] = [
                'id' => $feed->id,
                'title' => $feed->name,
                'url' => $feed->getCpEditUrl(),
                'channel' => $channels[$feed->channel]->name ?? $feed->channel,
                'format' => strtoupper($feed->format),
                'schedule' => $feed->getIntervalLabel(),
                'products' => $run ? (string)$run->itemCount : '—',
                'lastRun' => $run?->dateCreated ? Craft::$app->getFormatter()->asRelativeTime($run->dateCreated) : '—',
                'status' => $run?->status ?? 'never',
                'enabled' => (bool)$feed->enabled,
                'feedUrl' => $feed->getUrl(),
            ];
        }

        $this->getView()->registerAssetBundle(AdminTableAsset::class);
        $this->getView()->registerJs($this->_indexJs($tableData), View::POS_END);

        return $this->renderTemplate('eat/feeds/_index', [
            'feeds' => $feeds,
            'runs' => $runs,
            'channels' => $channels,
            'canAdd' => $plugin->getFeeds()->canAddFeed(),
            'isPro' => $plugin->isPro(),
            'newFeedUrl' => UrlHelper::cpUrl('eat/feeds/new'),
        ]);
    }

    public function actionEdit(?int $feedId = null, ?Feed $feed = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($feed === null) {
            if ($feedId !== null) {
                $feed = $plugin->getFeeds()->getFeedById($feedId);

                if ($feed === null) {
                    throw new NotFoundHttpException('Feed not found');
                }
            } else {
                if (!$plugin->getFeeds()->canAddFeed()) {
                    throw new ForbiddenHttpException(Craft::t('eat', 'Eat Lite is limited to {n} feeds.', [
                        'n' => \justinholtweb\eat\services\Feeds::LITE_FEED_LIMIT,
                    ]));
                }

                $feed = new Feed([
                    'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                    'channel' => 'google',
                    'format' => 'rss',
                ]);
            }
        }

        $channels = Registry::all();
        $channelOptions = [];

        foreach ($channels as $id => $channel) {
            $allowed = $plugin->getFeeds()->channelIsAllowed($id);

            $channelOptions[] = [
                'value' => $id,
                'label' => $channel->name . ($allowed ? '' : ' — ' . Craft::t('eat', 'Pro')),
                'disabled' => !$allowed,
            ];
        }

        $formatOptions = [];

        foreach (Feed::formats() as $value => $label) {
            $formatOptions[] = ['value' => $value, 'label' => $label];
        }

        $siteOptions = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteOptions[] = ['value' => $site->id, 'label' => $site->name];
        }

        $storeOptions = [['value' => '', 'label' => Craft::t('eat', 'The site’s store')]];

        foreach (Commerce::getInstance()->getStores()->getAllStores() as $store) {
            $storeOptions[] = ['value' => $store->id, 'label' => $store->getName()];
        }

        $typeOptions = [];

        foreach (Commerce::getInstance()->getProductTypes()->getAllProductTypes() as $type) {
            $typeOptions[] = ['value' => $type->handle, 'label' => $type->name];
        }

        $volumeOptions = [['value' => '', 'label' => Craft::t('eat', 'Choose a volume')]];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $volumeOptions[] = ['value' => $volume->id, 'label' => $volume->name];
        }

        $deliveryOptions = [];

        foreach (Delivery::modes() as $value => $label) {
            $pro = !in_array($value, Delivery::liteModes(), true);

            $deliveryOptions[] = [
                'value' => $value,
                'label' => $label . ($pro && !$plugin->isPro() ? ' — ' . Craft::t('eat', 'Pro') : ''),
                'disabled' => $pro && !$plugin->isPro(),
            ];
        }

        $sourceOptions = [];

        foreach (Mapping::sources() as $value => $label) {
            $pro = $value === Mapping::SOURCE_TEMPLATE;

            $sourceOptions[] = [
                'value' => $value,
                'label' => Craft::t('eat', $label),
                'disabled' => $pro && !$plugin->isPro(),
            ];
        }

        $mappingRows = [];

        foreach ($feed->getMappings() as $mapping) {
            $definition = $feed->getChannelDefinition()?->getAttributeDef($mapping->attribute);

            $mappingRows[] = [
                'attribute' => $mapping->attribute,
                'source' => $mapping->source,
                'value' => $mapping->value,
                'modifiers' => Modifiers::toString($mapping->modifiers),
                'enabled' => $mapping->enabled,
                'required' => !empty($definition['required']),
            ];
        }

        return $this->renderTemplate('eat/feeds/_edit', [
            'feed' => $feed,
            'isNew' => !$feed->id,
            'isPro' => $plugin->isPro(),
            'channel' => $feed->getChannelDefinition(),
            'channels' => $channels,
            'channelOptions' => $channelOptions,
            'formatOptions' => $formatOptions,
            'siteOptions' => $siteOptions,
            'storeOptions' => $storeOptions,
            'typeOptions' => $typeOptions,
            'volumeOptions' => $volumeOptions,
            'deliveryOptions' => $deliveryOptions,
            'sourceOptions' => $sourceOptions,
            'mappingRows' => $mappingRows,
            'variantModeOptions' => $this->_optionList(Feed::variantModes()),
            'intervalOptions' => $this->_optionList(Feed::intervals()),
            'statusOptions' => [
                ['value' => 'live', 'label' => Craft::t('app', 'Live')],
                ['value' => 'pending', 'label' => Craft::t('app', 'Pending')],
                ['value' => 'expired', 'label' => Craft::t('app', 'Expired')],
                ['value' => 'disabled', 'label' => Craft::t('app', 'Disabled')],
            ],
            'attributeGroups' => Attributes::grouped(),
            'modifierTypes' => Value::modifiers(),
            'lastRun' => $feed->id ? $plugin->getRuns()->getLastRun($feed->id) : null,
            'title' => $feed->id ? (string)$feed->name : Craft::t('eat', 'New feed'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $feeds = $plugin->getFeeds();
        $id = $this->request->getBodyParam('id');
        $feed = $id ? $feeds->getFeedById((int)$id) : new Feed();

        if ($feed === null) {
            throw new NotFoundHttpException('Feed not found');
        }

        $previousChannel = $feed->channel;
        $feed->name = $this->request->getBodyParam('name');
        $feed->handle = $this->request->getBodyParam('handle') ?: StringHelper::toKebabCase((string)$feed->name);
        $feed->channel = $this->request->getBodyParam('channel', $feed->channel);
        $feed->format = $this->request->getBodyParam('format', $feed->format);
        $feed->enabled = (bool)$this->request->getBodyParam('enabled', true);
        $feed->siteId = (int)$this->request->getBodyParam('siteId') ?: null;
        $feed->storeId = (int)$this->request->getBodyParam('storeId') ?: null;
        $feed->variantMode = $this->request->getBodyParam('variantMode', $feed->variantMode);
        $feed->interval = (int)$this->request->getBodyParam('interval', 0);
        $feed->regenerateOnSave = (bool)$this->request->getBodyParam('regenerateOnSave', false);

        $feed->setFilters($this->_filters());
        $feed->setOptions($this->_feedOptions());
        $feed->setDelivery($this->_delivery($feed));
        // Switching channel means switching vocabularies: `g:image_link` has no meaning on Awin.
        // The posted rows belong to the old channel, so the new channel's defaults replace them.
        if ($feed->id && $feed->channel !== $previousChannel) {
            $feed->setMappings(array_map(
                static fn($mapping) => $mapping->toConfig(),
                $feed->getChannelDefinition()?->defaultMappings() ?? []
            ));
        } else {
            $feed->setMappings($this->_mappings());
        }
        $feed->setProductCondition($this->request->getBodyParam('productCondition'));

        if (!$feeds->saveFeed($feed)) {
            return $this->asModelFailure($feed, Craft::t('eat', 'Couldn’t save feed.'), 'feed');
        }

        if ($this->request->getBodyParam('generate')) {
            $feeds->queue($feed, 'manual');
        }

        return $this->asModelSuccess($feed, Craft::t('eat', 'Feed saved.'), 'feed', ['id' => $feed->id]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)$this->request->getRequiredBodyParam('id');

        if (!Plugin::getInstance()->getFeeds()->deleteFeedById($id)) {
            return $this->asFailure(Craft::t('eat', 'Couldn’t delete feed.'));
        }

        return $this->asSuccess(Craft::t('eat', 'Feed deleted.'));
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = Json::decode($this->request->getRequiredBodyParam('ids'));
        Plugin::getInstance()->getFeeds()->reorderFeeds($ids);

        return $this->asSuccess(Craft::t('eat', 'Feeds reordered.'));
    }

    /**
     * Generate now: queued by default, in-request when the merchant asked for it or the queue is
     * turned off.
     */
    public function actionGenerate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('eat-generateFeeds');

        $plugin = Plugin::getInstance();
        $id = (int)$this->request->getRequiredBodyParam('id');
        $feed = $plugin->getFeeds()->getFeedById($id);

        if ($feed === null) {
            throw new NotFoundHttpException('Feed not found');
        }

        $now = (bool)$this->request->getBodyParam('now');

        if (!$now && $plugin->getSettings()->generateInQueue) {
            $plugin->getFeeds()->queue($feed, 'manual');

            return $this->asSuccess(Craft::t('eat', 'Generating “{name}” in the background.', ['name' => $feed->name]));
        }

        $run = $plugin->getFeeds()->run($feed, 'manual');

        if ($run->getIsError()) {
            return $this->asFailure((string)$run->message);
        }

        return $this->asSuccess(Craft::t('eat', '{n, plural, =0{No products} =1{1 product} other{# products}} written.', [
            'n' => $run->itemCount,
        ]), [
            'itemCount' => $run->itemCount,
            'skippedCount' => $run->skippedCount,
            'url' => $run->url,
        ]);
    }

    /**
     * The preview *is* the feed: it runs the same generator with a row limit, and shows the bytes
     * that would be written.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $id = $this->request->getBodyParam('id');
        $feed = $id ? $plugin->getFeeds()->getFeedById((int)$id) : null;

        if ($feed === null) {
            throw new NotFoundHttpException('Feed not found');
        }

        // Preview an unsaved edit too, so a merchant can try a mapping before committing to it.
        if ($this->request->getBodyParam('mappings') !== null) {
            $feed = clone $feed;
            $feed->setMappings($this->_mappings());
            $feed->setFilters($this->_filters());
            $feed->setOptions($this->_feedOptions());
            $feed->format = $this->request->getBodyParam('format', $feed->format);
            $feed->variantMode = $this->request->getBodyParam('variantMode', $feed->variantMode);
        }

        $limit = min(50, max(1, (int)$this->request->getBodyParam('limit', 5)));
        $stats = [];
        $rows = $plugin->getGenerator()->preview($feed, $limit, $stats);

        $written = $plugin->getGenerator()->write($feed, $limit);
        $raw = (string)@file_get_contents($written['path'], false, null, 0, 200000);
        @unlink($written['path']);

        $columns = [];

        foreach ($feed->getActiveMappings() as $mapping) {
            $columns[] = $mapping->attribute;
        }

        return $this->asJson([
            'success' => true,
            'columns' => $columns,
            'rows' => array_map(static function(array $row) {
                return array_map(static fn($value) => is_array($value) ? implode(', ', $value) : $value, $row);
            }, $rows),
            'skipped' => $stats['skipped'] ?? 0,
            'reasons' => $stats['reasons'] ?? [],
            'raw' => $raw,
        ]);
    }

    /**
     * Prove the Merchant Center credentials before a merchant waits for a whole run to find out.
     */
    public function actionTestMerchant(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Plugin::getInstance()->isPro()) {
            return $this->asFailure(Craft::t('eat', 'Content API push is a Pro feature.'));
        }

        $config = [
            'merchantId' => $this->request->getBodyParam('merchantId'),
            'serviceAccount' => $this->request->getBodyParam('serviceAccount'),
        ];

        $result = Plugin::getInstance()->getMerchant()->test($config);

        return $result['ok']
            ? $this->asSuccess((string)$result['message'])
            : $this->asFailure((string)$result['message']);
    }

    // Private
    // -------------------------------------------------------------------------

    private function _optionList(array $pairs): array
    {
        $options = [];

        foreach ($pairs as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    private function _filters(): array
    {
        $posted = (array)$this->request->getBodyParam('filters', []);
        $filters = Feed::defaultFilters();

        $filters['productTypes'] = array_values(array_filter((array)($posted['productTypes'] ?? [])));
        $filters['statuses'] = array_values(array_filter((array)($posted['statuses'] ?? [])));
        $filters['inStockOnly'] = (bool)($posted['inStockOnly'] ?? false);
        $filters['requireImage'] = (bool)($posted['requireImage'] ?? false);
        $filters['requirePrice'] = (bool)($posted['requirePrice'] ?? false);
        $filters['includeDisabledVariants'] = (bool)($posted['includeDisabledVariants'] ?? false);
        $filters['minPrice'] = $this->_numberOrNull($posted['minPrice'] ?? null);
        $filters['maxPrice'] = $this->_numberOrNull($posted['maxPrice'] ?? null);
        $filters['limit'] = $this->_numberOrNull($posted['limit'] ?? null);
        $filters['excludeSkus'] = $this->_lines($posted['excludeSkus'] ?? '');
        $filters['excludeIds'] = array_map('intval', $this->_lines($posted['excludeIds'] ?? ''));

        return $filters;
    }

    private function _feedOptions(): array
    {
        $posted = (array)$this->request->getBodyParam('options', []);
        $options = Feed::defaultOptions();

        foreach ($options as $key => $default) {
            if (!array_key_exists($key, $posted)) {
                continue;
            }

            $value = $posted[$key];

            if (is_bool($default)) {
                $options[$key] = (bool)$value;
                continue;
            }

            if (is_int($default)) {
                $options[$key] = (int)$value;
                continue;
            }

            $options[$key] = $value === '' ? null : $value;
        }

        $options['compress'] = (bool)($posted['compress'] ?? false);
        $options['includeHeader'] = (bool)($posted['includeHeader'] ?? false);
        $options['skipIncomplete'] = (bool)($posted['skipIncomplete'] ?? false);
        $options['liveRoute'] = (bool)($posted['liveRoute'] ?? false);

        return $options;
    }

    private function _delivery(Feed $feed): array
    {
        $posted = (array)$this->request->getBodyParam('delivery', []);
        $delivery = $feed->getDelivery();

        $delivery['mode'] = (string)($posted['mode'] ?? 'file');
        $delivery['path'] = ($posted['path'] ?? '') ?: null;
        $delivery['volumeId'] = (int)($posted['volumeId'] ?? 0) ?: null;
        $delivery['volumePath'] = (string)($posted['volumePath'] ?? '');

        foreach (['ftp', 'sftp', 'merchant'] as $group) {
            foreach ((array)($posted[$group] ?? []) as $key => $value) {
                if (!array_key_exists($key, $delivery[$group])) {
                    continue;
                }

                $delivery[$group][$key] = is_bool($delivery[$group][$key]) ? (bool)$value : $value;
            }
        }

        $delivery['ftp']['passive'] = (bool)($posted['ftp']['passive'] ?? false);
        $delivery['ftp']['secure'] = (bool)($posted['ftp']['secure'] ?? false);
        $delivery['ftp']['port'] = (int)($posted['ftp']['port'] ?? 21) ?: 21;
        $delivery['sftp']['port'] = (int)($posted['sftp']['port'] ?? 22) ?: 22;

        return $delivery;
    }

    private function _mappings(): array
    {
        $rows = $this->request->getBodyParam('mappings', []);
        $mappings = [];
        $pro = Plugin::getInstance()->isPro();

        foreach ((array)$rows as $row) {
            $attribute = trim((string)($row['attribute'] ?? ''));

            if ($attribute === '') {
                continue;
            }

            $mappings[] = [
                'attribute' => $attribute,
                'source' => (string)($row['source'] ?? Mapping::SOURCE_NONE),
                'value' => (string)($row['value'] ?? ''),
                'enabled' => (bool)($row['enabled'] ?? false),
                'modifiers' => $pro ? Modifiers::parse((string)($row['modifiers'] ?? '')) : [],
            ];
        }

        return $mappings;
    }

    /**
     * @return string[]
     */
    private function _lines(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', array_map('strval', $value))));
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)$value) ?: [])));
    }

    private function _numberOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '' || !is_numeric($value)) ? null : (string)$value;
    }

    private function _indexJs(array $tableData): string
    {
        $data = Json::encode($tableData);
        $empty = Json::encode(Craft::t('eat', 'No feeds yet.'));
        $channelLabel = Json::encode(Craft::t('eat', 'Channel'));
        $formatLabel = Json::encode(Craft::t('eat', 'Format'));
        $scheduleLabel = Json::encode(Craft::t('eat', 'Schedule'));
        $productsLabel = Json::encode(Craft::t('eat', 'Products'));
        $lastRunLabel = Json::encode(Craft::t('eat', 'Last run'));

        return <<<JS
new Craft.VueAdminTable({
    columns: [
        { name: '__slot:title', title: Craft.t('app', 'Name') },
        { name: 'channel', title: {$channelLabel} },
        { name: 'format', title: {$formatLabel} },
        { name: 'schedule', title: {$scheduleLabel} },
        { name: 'products', title: {$productsLabel} },
        {
            name: 'lastRun',
            title: {$lastRunLabel},
            callback: function(value, row) {
                if (!row) { return value; }
                var colour = row.status === 'error' ? '#cf1124' : (row.status === 'partial' ? '#b95000' : '#27ab83');
                return value === '—' ? '<span class="light">—</span>' : '<span style="color:' + colour + '">' + value + '</span>';
            }
        },
        {
            name: 'enabled',
            title: Craft.t('app', 'Enabled'),
            callback: function(value) {
                return value ? '<span data-icon="check"></span>' : '<span class="light">—</span>';
            }
        }
    ],
    container: '#eat-feeds',
    deleteAction: 'eat/feeds/delete',
    emptyMessage: {$empty},
    padded: true,
    reorderAction: 'eat/feeds/reorder',
    tableData: {$data}
});
JS;
    }
}
