<?php

namespace justinholtweb\eat\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\eat\channels\Registry;
use justinholtweb\eat\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Mapping store categories onto a channel's taxonomy.
 */
class TaxonomyController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('eat-manageTaxonomy');

        if (!Plugin::commerceIsReady()) {
            throw new ForbiddenHttpException('Craft Commerce is not installed.');
        }

        return true;
    }

    public function actionIndex(?string $channel = null): Response
    {
        $plugin = Plugin::getInstance();
        $channels = Registry::all();

        // Only channels that actually have a taxonomy attribute are worth mapping.
        $mappable = array_filter($channels, static fn($definition) => $definition->taxonomyAttribute !== null);
        $channel ??= array_key_first($mappable) ?: 'google';

        if (!isset($mappable[$channel])) {
            $channel = (string)array_key_first($mappable);
        }

        $productTypes = $plugin->getTaxonomy()->productTypes();
        $map = $plugin->getTaxonomy()->getMap($channel);
        $rows = [];

        foreach ($productTypes as $handle => $name) {
            $rows[] = [
                'handle' => $handle,
                'name' => $name,
                'value' => $map['productType:' . $handle] ?? '',
            ];
        }

        return $this->renderTemplate('eat/taxonomy/_index', [
            'channel' => $channel,
            'channels' => $mappable,
            'rows' => $rows,
            'usedBy' => array_values(array_filter(
                $plugin->getFeeds()->getAllFeeds(),
                static fn($feed) => $feed->channel === $channel
            )),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $channel = (string)$this->request->getRequiredBodyParam('channel');
        $values = (array)$this->request->getBodyParam('values', []);

        Plugin::getInstance()->getTaxonomy()->saveMany($channel, 'productType', array_map('strval', $values));

        return $this->asSuccess(Craft::t('eat', 'Taxonomy saved.'), [], "eat/taxonomy/$channel");
    }
}
