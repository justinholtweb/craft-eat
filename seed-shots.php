<?php
/** Seed realistic Eat content in the plugin-testing harness for screenshots. */
require getcwd() . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\Plugin as Commerce;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\Plugin;

$plugin = Plugin::getInstance();
$feeds = $plugin->getFeeds();
$siteId = Craft::$app->getSites()->getPrimarySite()->id;
$typeHandle = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0]->handle;

$link = '{{ object.product.url ?? "https://plugin-testing.ddev.site/shop/" ~ object.product.slug }}';
$image = '{{ "https://plugin-testing.ddev.site/images/products/" ~ object.sku|lower ~ ".jpg" }}';

function mapping(string $attribute, string $source, ?string $value, array $modifiers = []): array
{
    return ['attribute' => $attribute, 'source' => $source, 'value' => $value, 'enabled' => true, 'modifiers' => $modifiers];
}

$definitions = [
    [
        'name' => 'Google Shopping',
        'handle' => 'google-shopping',
        'channel' => 'google',
        'format' => 'rss',
        'interval' => 86400,
        'filters' => ['statuses' => ['live'], 'requirePrice' => true],
        'options' => ['utmSource' => 'google', 'utmMedium' => 'cpc', 'utmCampaign' => 'shopping'],
        'mappings' => [
            mapping('id', 'attribute', 'sku'),
            mapping('title', 'attribute', 'fullTitle'),
            mapping('description', 'attribute', 'plainDescription', [['type' => 'truncate', 'a' => '5000']]),
            mapping('link', 'template', $link),
            mapping('image_link', 'template', $image),
            mapping('availability', 'attribute', 'availability'),
            mapping('price', 'attribute', 'priceWithCurrency'),
            mapping('sale_price', 'attribute', 'promotionalPriceWithCurrency'),
            mapping('brand', 'attribute', 'brand'),
            mapping('mpn', 'attribute', 'sku'),
            mapping('condition', 'attribute', 'condition'),
            mapping('google_product_category', 'taxonomy', 'productType'),
            mapping('product_type', 'attribute', 'productTypeName'),
            mapping('item_group_id', 'attribute', 'itemGroupId'),
            mapping('shipping_weight', 'attribute', 'weightWithUnit'),
            mapping('custom_label_0', 'static', 'core-range'),
        ],
    ],
    [
        'name' => 'Meta catalog',
        'handle' => 'meta-catalog',
        'channel' => 'meta',
        'format' => 'csv',
        'interval' => 21600,
        'filters' => ['statuses' => ['live'], 'requirePrice' => true, 'inStockOnly' => true, 'minPrice' => 20],
        'options' => ['utmSource' => 'facebook', 'utmMedium' => 'paid-social'],
        'mappings' => [
            mapping('id', 'attribute', 'sku'),
            mapping('title', 'attribute', 'fullTitle'),
            mapping('description', 'attribute', 'plainDescription'),
            mapping('availability', 'attribute', 'availability'),
            mapping('condition', 'attribute', 'condition'),
            mapping('price', 'attribute', 'priceWithCurrency'),
            mapping('link', 'template', $link),
            mapping('image_link', 'template', $image),
            mapping('brand', 'attribute', 'brand'),
            mapping('quantity_to_sell_on_facebook', 'attribute', 'stock'),
            mapping('item_group_id', 'attribute', 'itemGroupId'),
            mapping('google_product_category', 'taxonomy', 'productType'),
        ],
    ],
    [
        'name' => 'TikTok catalog',
        'handle' => 'tiktok-catalog',
        'channel' => 'tiktok',
        'format' => 'csv',
        'interval' => 86400,
        'filters' => ['statuses' => ['live'], 'requirePrice' => true],
        'options' => ['compress' => true],
        'mappings' => [
            mapping('sku_id', 'attribute', 'sku'),
            mapping('title', 'attribute', 'fullTitle', [['type' => 'truncate', 'a' => '80', 'b' => '…']]),
            mapping('description', 'attribute', 'plainDescription'),
            mapping('availability', 'attribute', 'availability'),
            mapping('condition', 'attribute', 'condition'),
            mapping('price', 'attribute', 'priceWithCurrency'),
            mapping('link', 'template', $link),
            mapping('image_link', 'template', $image),
            mapping('brand', 'attribute', 'brand'),
            mapping('item_group_id', 'attribute', 'itemGroupId'),
        ],
    ],
    [
        'name' => 'Awin UK datafeed',
        'handle' => 'awin-uk',
        'channel' => 'awin',
        'format' => 'csv',
        'interval' => 604800,
        'variantMode' => Feed::VARIANT_MODE_DEFAULT,
        'filters' => ['statuses' => ['live'], 'requirePrice' => true],
        'options' => [],
        'mappings' => [
            mapping('merchant_product_id', 'attribute', 'sku'),
            mapping('product_name', 'attribute', 'fullTitle'),
            mapping('description', 'attribute', 'plainDescription'),
            mapping('merchant_category', 'attribute', 'productTypeName'),
            mapping('aw_deep_link', 'template', $link),
            mapping('aw_image_url', 'template', $image),
            mapping('search_price', 'attribute', 'salePrice'),
            mapping('rrp_price', 'attribute', 'price'),
            mapping('brand_name', 'attribute', 'brand'),
            mapping('in_stock', 'attribute', 'availability'),
            mapping('stock_quantity', 'attribute', 'stock'),
            mapping('currency', 'attribute', 'currency'),
        ],
    ],
];

foreach ($definitions as $definition) {
    $existing = $feeds->getFeedByHandle($definition['handle']);
    $feed = $existing ?? new Feed();
    $feed->name = $definition['name'];
    $feed->handle = $definition['handle'];
    $feed->channel = $definition['channel'];
    $feed->format = $definition['format'];
    $feed->siteId = $siteId;
    $feed->enabled = true;
    $feed->interval = $definition['interval'];
    $feed->variantMode = $definition['variantMode'] ?? Feed::VARIANT_MODE_VARIANT;
    $feed->setFilters($definition['filters']);
    $feed->setOptions($definition['options']);
    $feed->setMappings($definition['mappings']);
    $feed->setDelivery(['mode' => 'file']);

    if (!$feeds->saveFeed($feed)) {
        echo "! could not save {$definition['handle']}: " . json_encode($feed->getErrors()) . "\n";
        continue;
    }

    echo "saved {$feed->handle}\n";
}

$plugin->getTaxonomy()->save('google', 'productType', $typeHandle, 'Hardware > Building Consumables > Hardware Accessories');
$plugin->getTaxonomy()->save('meta', 'productType', $typeHandle, 'Hardware > Building Consumables');

$feeds->clearCaches();

foreach ($feeds->getAllFeeds() as $feed) {
    $run = $feeds->run($feed, 'schedule');
    echo str_pad($feed->handle, 20) . "{$run->status}: {$run->itemCount} products, {$run->skippedCount} skipped, {$run->getSizeLabel()}, {$run->durationMs}ms\n";
}

// A second, older-looking run for the two busiest feeds, so the log is not all one timestamp.
foreach (['google-shopping', 'meta-catalog'] as $handle) {
    $feed = $feeds->getFeedByHandle($handle);
    $feeds->run($feed, 'console');
}
