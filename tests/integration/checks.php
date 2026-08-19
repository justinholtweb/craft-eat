<?php
/**
 * Eat integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-eat/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: every fixture it creates it deletes again, whether the run passes
 * or not, and the plugin edition and settings are put back the way they were found.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Json;
use justinholtweb\eat\channels\Registry;
use justinholtweb\eat\formats\XmlWriter;
use justinholtweb\eat\helpers\Attributes;
use justinholtweb\eat\helpers\Modifiers;
use justinholtweb\eat\helpers\Value;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\models\Mapping;
use justinholtweb\eat\models\Run;
use justinholtweb\eat\Plugin;
use justinholtweb\eat\services\Delivery;
use justinholtweb\eat\services\Feeds as FeedsService;
use justinholtweb\eat\twig\EatVariable;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

/** @var Plugin $plugin */
$plugin = Plugin::getInstance();
$originalEdition = $plugin->edition;
$createdProducts = [];
$createdFeeds = [];
$createdFiles = [];

function switchEdition(string $edition): void
{
    Craft::$app->getPlugins()->switchEdition(Plugin::HANDLE, $edition);
    Craft::$app->getProjectConfig()->flush();
}

// `craft-penny` (a sibling plugin in this shared harness) registers an
// Elements::EVENT_BEFORE_SAVE_ELEMENT handler typed `ModelEvent` while Craft passes an
// `ElementEvent`, so saving *any* element fatals while it is enabled. Nothing to do with Eat;
// detached in-process here (never persisted) so fixtures can be created.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

function makeProduct(string $sku, float $price, ?float $promo = null, bool $tracked = false, int $variants = 1): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Eat fixture $sku";
    $product->enabled = true;

    $list = [];

    for ($i = 0; $i < $variants; $i++) {
        $variant = new Variant();
        $variant->sku = $variants > 1 ? "$sku-$i" : $sku;
        $variant->title = $variants > 1 ? "Size $i" : null;
        $variant->basePrice = $price + $i;
        $variant->basePromotionalPrice = $promo;
        $variant->weight = 1.5;
        $variant->length = 10;
        $variant->width = 5;
        $variant->height = 4;
        $variant->inventoryTracked = $tracked;
        $variant->isDefault = $i === 0;
        $list[] = $variant;
    }

    $product->setVariants($list);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . Json::encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

function makeFeed(array $config = []): Feed
{
    global $createdFeeds, $plugin;

    $feed = new Feed(array_merge([
        'name' => 'Eat check feed',
        'handle' => 'eatcheck' . bin2hex(random_bytes(3)),
        'channel' => 'custom',
        'format' => 'csv',
        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
    ], $config));

    if (!$plugin->getFeeds()->saveFeed($feed)) {
        throw new RuntimeException('Could not save feed: ' . Json::encode($feed->getErrors()));
    }

    $createdFeeds[] = $feed;

    return $feed;
}

try {
    switchEdition(Plugin::EDITION_PRO);

    // ---------------------------------------------------------------- Channels
    section('Channel registry');

    $channels = Registry::all();

    check('every advertised channel is defined', fn() => count($channels) === 15 ?: 'got ' . count($channels));

    check('each definition is complete', function() use ($channels) {
        foreach ($channels as $id => $channel) {
            if ($channel->name === '' || $channel->attributes === [] || $channel->formats === []) {
                return "$id is incomplete";
            }

            if (!$channel->supportsFormat($channel->defaultFormat)) {
                return "$id does not support its own default format";
            }

            foreach ($channel->attributes as $definition) {
                foreach (['key', 'label', 'required', 'source', 'ns'] as $key) {
                    if (!array_key_exists($key, $definition)) {
                        return "$id attribute {$definition['key']} has no $key";
                    }
                }
            }
        }

        return true;
    });

    check('attribute keys are unique within a channel', function() use ($channels) {
        foreach ($channels as $id => $channel) {
            $keys = array_column($channel->attributes, 'key');

            if (count($keys) !== count(array_unique($keys))) {
                return "$id repeats an attribute";
            }
        }

        return true;
    });

    check('every default source names a real attribute', function() use ($channels) {
        $known = Attributes::names();

        foreach ($channels as $id => $channel) {
            foreach ($channel->attributes as $definition) {
                if (($definition['source'] ?? '') !== 'attribute') {
                    continue;
                }

                if (!in_array($definition['value'], $known, true)) {
                    return "$id maps {$definition['key']} to unknown attribute {$definition['value']}";
                }
            }
        }

        return true;
    });

    check('Google requires the six attributes Google requires', function() use ($channels) {
        $required = $channels['google']->getRequiredKeys();

        foreach (['id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'brand'] as $key) {
            if (!in_array($key, $required, true)) {
                return "google does not require $key";
            }
        }

        return true;
    });

    check('RSS’s own elements are never namespaced', function() use ($channels) {
        foreach (['google', 'meta', 'pinterest', 'bing', 'tiktok'] as $id) {
            foreach (['title', 'link', 'description'] as $key) {
                if ($channels[$id]->isNamespaced($key)) {
                    return "$id would write <g:$key>";
                }
            }

            if (!$channels[$id]->isNamespaced('image_link')) {
                return "$id would write a bare <image_link>";
            }
        }

        return true;
    });

    check('each channel has its own availability vocabulary', function() use ($channels) {
        return $channels['google']->availabilityWord('in') === 'in_stock'
            && $channels['meta']->availabilityWord('in') === 'in stock'
            && $channels['criteo']->availabilityWord('out') === 'false'
            ?: 'availability wording is wrong';
    });

    check('default mappings cover every attribute', function() use ($channels) {
        $mappings = $channels['google']->defaultMappings();

        return count($mappings) === count($channels['google']->attributes) ?: 'mapping count differs';
    });

    check('unmapped attributes start switched off', function() use ($channels) {
        foreach ($channels['google']->defaultMappings() as $mapping) {
            if ($mapping->source === Mapping::SOURCE_NONE && $mapping->enabled) {
                return "{$mapping->attribute} is on with no source";
            }
        }

        return true;
    });

    // ------------------------------------------------------------ Value helper
    section('Values and modifiers');

    check('scalars stringify', fn() => Value::stringify(12.5) === '12.5' && Value::stringify(true) === 'yes');

    check('arrays stay arrays and drop empties', function() {
        $result = Value::stringify(['a', '', 'b']);

        return $result === ['a', 'b'] ?: Json::encode($result);
    });

    check('an element becomes its title, not its attributes', function() {
        global $createdProducts;

        $product = makeProduct('EAT-STRINGIFY-' . bin2hex(random_bytes(2)), 10);

        return Value::stringify($product) === $product->title ?: 'got ' . Json::encode(Value::stringify($product));
    });

    check('strip_tags and collapse_whitespace clean a description', function() {
        $value = Value::applyModifiers("<p>Hello   <b>world</b></p>\n\n<p>Again</p>", [
            ['type' => 'strip_tags'],
            ['type' => 'collapse_whitespace'],
        ]);

        return $value === 'Hello world Again' ?: "got “$value”";
    });

    check('truncate keeps the suffix inside the limit', function() {
        $value = Value::applyModifier(str_repeat('a', 200), ['type' => 'truncate', 'a' => '20', 'b' => '…']);

        return mb_strlen($value) === 20 ?: 'length ' . mb_strlen($value);
    });

    check('prefix and suffix leave an empty value alone', function() {
        return Value::applyModifier('', ['type' => 'prefix', 'a' => 'X']) === ''
            && Value::applyModifier('y', ['type' => 'suffix', 'a' => 'Z']) === 'yZ';
    });

    check('multiply and number format prices', function() {
        return Value::applyModifier('10', ['type' => 'multiply', 'a' => '1.2']) === '12'
            && Value::applyModifier('12', ['type' => 'number', 'a' => '2']) === '12.00';
    });

    check('map swaps a channel’s vocabulary', function() {
        return Value::applyModifier('red', ['type' => 'map', 'a' => 'red=Rot|blue=Blau']) === 'Rot'
            && Value::applyModifier('green', ['type' => 'map', 'a' => 'red=Rot']) === 'green';
    });

    check('default only fills an empty value', function() {
        return Value::applyModifier('', ['type' => 'default', 'a' => 'n/a']) === 'n/a'
            && Value::applyModifier('x', ['type' => 'default', 'a' => 'n/a']) === 'x';
    });

    check('a broken regex passes the value through instead of emptying it', function() {
        $value = Value::regexReplace('keep me', '/[unclosed', 'x');

        return $value === 'keep me' ?: "got “$value”";
    });

    check('modifiers apply to every item of a repeated value', function() {
        $value = Value::applyModifiers(['a', 'b'], [['type' => 'upper']]);

        return $value === ['A', 'B'] ?: Json::encode($value);
    });

    check('UTM tags append without clobbering an existing query', function() {
        $url = Value::appendQuery('https://example.com/p?ref=x#frag', ['utm_source' => 'google', 'utm_medium' => null]);

        return str_contains($url, 'ref=x') && str_contains($url, 'utm_source=google') && str_ends_with($url, '#frag')
            ?: "got $url";
    });

    check('the modifier DSL round-trips', function() {
        $parsed = Modifiers::parse('strip_tags | truncate:150:… | replace:Ltd:Limited');

        if (count($parsed) !== 3 || $parsed[1]['a'] !== '150' || $parsed[2]['b'] !== 'Limited') {
            return Json::encode($parsed);
        }

        return Modifiers::toString($parsed) === 'strip_tags | truncate:150:… | replace:Ltd:Limited'
            ?: Modifiers::toString($parsed);
    });

    check('an unknown modifier is dropped, not written', function() {
        return Modifiers::parse('nonsense | upper') === [['type' => 'upper']] ?: Json::encode(Modifiers::parse('nonsense | upper'));
    });

    // -------------------------------------------------------------- Feed model
    section('Feed model');

    check('a new feed inherits the channel’s attributes', function() {
        $feed = new Feed(['channel' => 'google', 'format' => 'rss']);

        return count($feed->getMappings()) === count(Registry::get('google')->attributes) ?: 'wrong mapping count';
    });

    check('file name follows the handle and the format', function() {
        $feed = new Feed(['handle' => 'shop', 'channel' => 'google', 'format' => 'csv']);

        if ($feed->getFileName() !== 'shop.csv') {
            return $feed->getFileName();
        }

        $feed->setOptions(['compress' => true]);

        return $feed->getFileName() === 'shop.csv.gz' && $feed->getMimeType() === 'application/gzip'
            ?: $feed->getFileName() . ' / ' . $feed->getMimeType();
    });

    check('a format the channel refuses fails validation', function() {
        $feed = new Feed(['name' => 'x', 'handle' => 'x' . bin2hex(random_bytes(3)), 'channel' => 'criteo', 'format' => 'rss']);
        $feed->validate();

        return $feed->hasErrors('format') ?: 'criteo accepted an RSS feed';
    });

    check('an unknown channel fails validation', function() {
        $feed = new Feed(['name' => 'x', 'handle' => 'x' . bin2hex(random_bytes(3)), 'channel' => 'myspace', 'format' => 'csv']);
        $feed->validate();

        return $feed->hasErrors('channel') ?: 'myspace was accepted';
    });

    check('a manual feed is never due; an hourly one that has never run is', function() {
        $manual = new Feed(['interval' => 0, 'enabled' => true]);
        $hourly = new Feed(['interval' => 3600, 'enabled' => true]);
        $future = new Feed(['interval' => 3600, 'enabled' => true, 'nextGenerateAt' => new DateTime('+1 hour')]);

        return !$manual->getIsDue() && $hourly->getIsDue() && !$future->getIsDue() ?: 'due logic is wrong';
    });

    // --------------------------------------------------------------- Fixtures
    section('Fixtures');

    $productA = makeProduct('EAT-A', 19.99, null, false, 1);
    $productB = makeProduct('EAT-B', 5.00, 3.50, false, 2);
    $productC = makeProduct('EAT-C', 42.00, null, true, 1);

    check('fixture products saved', fn() => $productA->id && $productB->id && $productC->id ?: 'a product has no id');

    check('the multi-variant fixture really has two variants', fn() => $productB->getVariants()->count() === 2 ?: 'got ' . $productB->getVariants()->count());

    $fixtureIds = [$productA->id, $productB->id, $productC->id];

    /**
     * Narrow a feed down to the fixture products by excluding everything else in the harness.
     */
    $limitToFixtures = function(Feed $feed) use ($fixtureIds) {
        $others = (new craft\db\Query())
            ->select(['elements.id'])
            ->from(['elements' => craft\db\Table::ELEMENTS])
            ->where(['elements.type' => Product::class])
            ->andWhere(['not', ['elements.id' => $fixtureIds]])
            ->column();

        $filters = $feed->getFilters();
        $filters['excludeIds'] = array_map('intval', $others);
        $feed->setFilters($filters);
    };

    // ------------------------------------------------------------- Generation
    section('Generation');

    $csvFeed = makeFeed(['channel' => 'custom', 'format' => 'csv']);
    $csvFeed->setMappings([
        ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
        ['attribute' => 'title', 'source' => 'attribute', 'value' => 'title', 'enabled' => true],
        ['attribute' => 'price', 'source' => 'attribute', 'value' => 'price', 'enabled' => true],
        ['attribute' => 'availability', 'source' => 'attribute', 'value' => 'availability', 'enabled' => true],
    ]);
    $limitToFixtures($csvFeed);
    $plugin->getFeeds()->saveFeed($csvFeed);

    check('one row per variant by default', function() use ($plugin, $csvFeed) {
        $rows = $plugin->getGenerator()->preview($csvFeed, 100);

        return count($rows) === 4 ?: 'got ' . count($rows) . ' rows';
    });

    check('rows carry the values the mapping asked for', function() use ($plugin, $csvFeed) {
        $rows = $plugin->getGenerator()->preview($csvFeed, 100);
        $skus = array_column($rows, 'id');
        sort($skus);

        return $skus === ['EAT-A', 'EAT-B-0', 'EAT-B-1', 'EAT-C'] ?: Json::encode($skus);
    });

    check('“one row per product” collapses the variants', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->variantMode = Feed::VARIANT_MODE_DEFAULT;
        $rows = $plugin->getGenerator()->preview($feed, 100);

        return count($rows) === 3 ?: 'got ' . count($rows);
    });

    check('prices are formatted to two decimals', function() use ($plugin, $csvFeed) {
        $rows = $plugin->getGenerator()->preview($csvFeed, 100);

        foreach ($rows as $row) {
            if ($row['id'] === 'EAT-A') {
                return $row['price'] === '19.99' ?: 'got ' . $row['price'];
            }
        }

        return 'EAT-A was not in the feed';
    });

    check('a price multiplier moves every price', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setOptions(array_merge($feed->getOptions(), ['priceMultiplier' => 2]));
        $rows = $plugin->getGenerator()->preview($feed, 100);

        foreach ($rows as $row) {
            if ($row['id'] === 'EAT-A') {
                return $row['price'] === '39.98' ?: 'got ' . $row['price'];
            }
        }

        return 'EAT-A was not in the feed';
    });

    check('availability uses the channel’s own wording', function() use ($plugin, $csvFeed) {
        $google = clone $csvFeed;
        $google->channel = 'google';
        $words = array_values(array_unique(array_column($plugin->getGenerator()->preview($google, 100), 'availability')));
        sort($words);

        $meta = clone $csvFeed;
        $meta->channel = 'meta';
        $metaWords = array_values(array_unique(array_column($plugin->getGenerator()->preview($meta, 100), 'availability')));
        sort($metaWords);

        return $words === ['in_stock', 'out_of_stock'] && $metaWords === ['in stock', 'out of stock']
            ?: Json::encode([$words, $metaWords]);
    });

    check('a tracked variant with no inventory reads as out of stock', function() use ($plugin, $csvFeed) {
        $rows = $plugin->getGenerator()->preview($csvFeed, 100);

        foreach ($rows as $row) {
            if ($row['id'] === 'EAT-C') {
                return $row['availability'] === 'out of stock' ?: 'got ' . $row['availability'];
            }
        }

        return 'EAT-C was not in the feed';
    });

    check('in-stock-only leaves the tracked one out', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setFilters(array_merge($feed->getFilters(), ['inStockOnly' => true]));
        $stats = [];
        $rows = $plugin->getGenerator()->preview($feed, 100, $stats);

        return count($rows) === 3 && ($stats['reasons']['out-of-stock'] ?? 0) === 1
            ?: count($rows) . ' rows, reasons ' . Json::encode($stats['reasons'] ?? []);
    });

    check('a SKU wildcard excludes a whole family', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setFilters(array_merge($feed->getFilters(), ['excludeSkus' => ['EAT-B-*']]));
        $stats = [];
        $rows = $plugin->getGenerator()->preview($feed, 100, $stats);

        return count($rows) === 2 && ($stats['reasons']['excluded-sku'] ?? 0) === 2
            ?: count($rows) . ' rows, reasons ' . Json::encode($stats['reasons'] ?? []);
    });

    check('a price floor filters', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setFilters(array_merge($feed->getFilters(), ['minPrice' => 19]));
        $rows = $plugin->getGenerator()->preview($feed, 100);

        return count($rows) === 2 ?: 'got ' . count($rows);
    });

    check('requiring an image skips products that have none', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setFilters(array_merge($feed->getFilters(), ['requireImage' => true]));
        $stats = [];
        $rows = $plugin->getGenerator()->preview($feed, 100, $stats);

        return count($rows) === 0 && ($stats['reasons']['no-image'] ?? 0) === 4
            ?: count($rows) . ' rows, reasons ' . Json::encode($stats['reasons'] ?? []);
    });

    check('a row limit stops the generator', function() use ($plugin, $csvFeed) {
        return count($plugin->getGenerator()->preview($csvFeed, 2)) === 2;
    });

    check('a missing required attribute skips the row and says which', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->channel = 'google';
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
            ['attribute' => 'title', 'source' => 'attribute', 'value' => 'title', 'enabled' => true],
            ['attribute' => 'image_link', 'source' => 'attribute', 'value' => 'imageUrl', 'enabled' => true],
        ]);
        $stats = [];
        $rows = $plugin->getGenerator()->preview($feed, 100, $stats);

        if ($rows !== []) {
            return 'rows survived without an image';
        }

        foreach ($stats['reasons'] as $reason => $count) {
            if (str_starts_with($reason, 'missing:') && str_contains($reason, 'image_link')) {
                return true;
            }
        }

        return Json::encode($stats['reasons']);
    });

    check('turning off skipIncomplete keeps the row', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->channel = 'google';
        $feed->setOptions(array_merge($feed->getOptions(), ['skipIncomplete' => false]));
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
            ['attribute' => 'image_link', 'source' => 'attribute', 'value' => 'imageUrl', 'enabled' => true],
        ]);

        return count($plugin->getGenerator()->preview($feed, 100)) === 4 ?: 'rows were still skipped';
    });

    // --------------------------------------------------------------- Resolver
    section('Attribute resolution');

    check('a static source writes the same value in every row', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
            ['attribute' => 'condition', 'source' => 'static', 'value' => 'refurbished', 'enabled' => true],
        ]);
        $rows = $plugin->getGenerator()->preview($feed, 100);

        return array_unique(array_column($rows, 'condition')) === ['refurbished'] ?: 'static value did not stick';
    });

    check('a Twig template source can reach the product', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
            ['attribute' => 'custom', 'source' => 'template', 'value' => '{{ object.product.title|upper }}', 'enabled' => true],
        ]);
        $rows = $plugin->getGenerator()->preview($feed, 1);

        return str_starts_with($rows[0]['custom'] ?? '', 'EAT FIXTURE') ?: Json::encode($rows[0] ?? []);
    });

    check('a modifier chain runs in order', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true, 'modifiers' => [
                ['type' => 'lower'],
                ['type' => 'prefix', 'a' => 'sku-'],
            ]],
        ]);
        $rows = $plugin->getGenerator()->preview($feed, 100);
        $ids = array_column($rows, 'id');
        sort($ids);

        return $ids === ['sku-eat-a', 'sku-eat-b-0', 'sku-eat-b-1', 'sku-eat-c'] ?: Json::encode($ids);
    });

    check('promotional price is empty when nothing is on promotion', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
            ['attribute' => 'sale_price', 'source' => 'attribute', 'value' => 'promotionalPriceWithCurrency', 'enabled' => true],
        ]);
        $rows = $plugin->getGenerator()->preview($feed, 100);

        foreach ($rows as $row) {
            if ($row['id'] === 'EAT-A' && isset($row['sale_price'])) {
                return 'EAT-A has a sale price it should not have';
            }
        }

        return true;
    });

    check('price with currency carries the store’s currency code', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setMappings([
            ['attribute' => 'price', 'source' => 'attribute', 'value' => 'priceWithCurrency', 'enabled' => true],
        ]);
        $rows = $plugin->getGenerator()->preview($feed, 1);

        return (bool)preg_match('/^\d+\.\d{2} [A-Z]{3}$/', $rows[0]['price'] ?? '') ?: Json::encode($rows[0] ?? []);
    });

    check('brand falls back to the system name', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setMappings([['attribute' => 'brand', 'source' => 'attribute', 'value' => 'brand', 'enabled' => true]]);
        $rows = $plugin->getGenerator()->preview($feed, 1);

        return ($rows[0]['brand'] ?? '') === Craft::$app->getSystemName() ?: Json::encode($rows[0] ?? []);
    });

    check('item_group_id is the product, so variants group', function() use ($plugin, $csvFeed, $productB) {
        $feed = clone $csvFeed;
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
            ['attribute' => 'item_group_id', 'source' => 'attribute', 'value' => 'itemGroupId', 'enabled' => true],
        ]);
        $rows = $plugin->getGenerator()->preview($feed, 100);
        $groups = [];

        foreach ($rows as $row) {
            if (str_starts_with($row['id'], 'EAT-B')) {
                $groups[] = $row['item_group_id'];
            }
        }

        return count($groups) === 2 && count(array_unique($groups)) === 1 && $groups[0] === (string)$productB->id
            ?: Json::encode($groups);
    });

    check('an unknown attribute name resolves to nothing rather than throwing', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
            ['attribute' => 'nonsense', 'source' => 'attribute', 'value' => 'whatIsThis', 'enabled' => true],
        ]);
        $rows = $plugin->getGenerator()->preview($feed, 1);

        return !isset($rows[0]['nonsense']) ?: 'a value appeared from nowhere';
    });

    check('every documented attribute resolves without an error', function() use ($plugin, $csvFeed, $productA) {
        $resolver = $plugin->getResolver();
        $channel = Registry::get('google');
        $variant = $productA->getDefaultVariant();

        foreach (Attributes::names() as $name) {
            $resolver->attributeValue($name, $csvFeed, $channel, $productA, $variant);
        }

        return true;
    });

    // ---------------------------------------------------------------- Writers
    section('Writers');

    check('an RSS feed is well-formed XML with the g: namespace bound', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->channel = 'google';
        $feed->format = 'rss';
        $feed->setOptions(array_merge($feed->getOptions(), ['skipIncomplete' => false]));
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
            ['attribute' => 'title', 'source' => 'attribute', 'value' => 'title', 'enabled' => true],
            ['attribute' => 'price', 'source' => 'attribute', 'value' => 'priceWithCurrency', 'enabled' => true],
        ]);

        $result = $plugin->getGenerator()->write($feed);
        $xml = file_get_contents($result['path']);
        @unlink($result['path']);

        $document = @simplexml_load_string($xml);

        if ($document === false) {
            return 'the XML did not parse';
        }

        $namespaces = $document->getNamespaces(true);

        if (($namespaces['g'] ?? '') !== 'http://base.google.com/ns/1.0') {
            return 'the g: namespace is not bound';
        }

        $items = $document->channel->item;

        if (count($items) !== 4) {
            return 'got ' . count($items) . ' items';
        }

        // <title> is RSS's own element; <g:id> is Google's.
        $google = $items[0]->children('http://base.google.com/ns/1.0');

        return (string)$items[0]->title !== '' && (string)$google->id !== '' ?: 'the elements are in the wrong namespace';
    });

    check('a value containing ]]> cannot end the CDATA section early', function() use ($csvFeed) {
        $handle = fopen('php://memory', 'r+b');
        $writer = new XmlWriter($handle, $csvFeed, Registry::get('custom'), ['description']);
        $writer->open();
        $writer->write(['description' => 'danger ]]> <b>here</b>']);
        $writer->close();
        rewind($handle);
        $xml = stream_get_contents($handle);
        fclose($handle);

        $document = @simplexml_load_string($xml);

        if ($document === false) {
            return 'the XML did not parse';
        }

        return (string)$document->product->description === 'danger ]]> <b>here</b>' ?: 'the value came back mangled';
    });

    check('a merchant’s odd attribute name becomes a legal element name', function() use ($csvFeed) {
        $writer = new XmlWriter(fopen('php://memory', 'r+b'), $csvFeed, Registry::get('pricerunner'), []);

        return $writer->sanitiseName('Product name') === 'Product_name'
            && $writer->sanitiseName('9lives') === 'attr_9lives'
            ?: $writer->sanitiseName('Product name') . ' / ' . $writer->sanitiseName('9lives');
    });

    check('a repeated value becomes repeated elements', function() use ($csvFeed) {
        $handle = fopen('php://memory', 'r+b');
        $writer = new XmlWriter($handle, $csvFeed, Registry::get('custom'), ['image']);
        $writer->open();
        $writer->write(['image' => ['a.jpg', 'b.jpg']]);
        $writer->close();
        rewind($handle);
        $xml = stream_get_contents($handle);
        fclose($handle);

        return substr_count($xml, '<image>') === 2 ?: $xml;
    });

    check('CSV writes a header and quotes what needs quoting', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
            ['attribute' => 'title', 'source' => 'static', 'value' => 'Comma, quote " and all', 'enabled' => true],
        ]);

        $result = $plugin->getGenerator()->write($feed, 1);
        $csv = file_get_contents($result['path']);
        @unlink($result['path']);

        $lines = array_values(array_filter(explode("\n", $csv)));

        if ($lines[0] !== 'id,title') {
            return 'header is ' . $lines[0];
        }

        $parsed = str_getcsv($lines[1]);

        return $parsed[1] === 'Comma, quote " and all' ?: Json::encode($parsed);
    });

    check('TSV flattens newlines instead of quoting them', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->format = 'tsv';
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
            ['attribute' => 'description', 'source' => 'static', 'value' => "two\nlines\tand a tab", 'enabled' => true],
        ]);

        $result = $plugin->getGenerator()->write($feed, 1);
        $tsv = file_get_contents($result['path']);
        @unlink($result['path']);

        $lines = array_values(array_filter(explode("\n", $tsv)));

        return count($lines) === 2 && substr_count($lines[1], "\t") === 1 ?: Json::encode($lines);
    });

    check('JSON output parses and is wrapped', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->format = 'json';

        $result = $plugin->getGenerator()->write($feed);
        $json = file_get_contents($result['path']);
        @unlink($result['path']);

        $decoded = Json::decodeIfJson($json);

        return is_array($decoded) && count($decoded['products'] ?? []) === 4 ?: substr($json, 0, 120);
    });

    check('a gzipped feed really is gzip, and unzips to the feed', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setOptions(array_merge($feed->getOptions(), ['compress' => true]));

        $result = $plugin->getGenerator()->write($feed);
        $bytes = file_get_contents($result['path']);
        $plain = (string)gzdecode($bytes);
        @unlink($result['path']);

        return str_starts_with($bytes, "\x1f\x8b") && str_contains($plain, 'EAT-A') ?: 'gzip output is wrong';
    });

    check('the writer reports exactly what it wrote', function() use ($plugin, $csvFeed) {
        $result = $plugin->getGenerator()->write($csvFeed);
        $bytes = filesize($result['path']);
        @unlink($result['path']);

        return $result['itemCount'] === 4 && $result['bytes'] === $bytes ?: Json::encode($result);
    });

    // --------------------------------------------------------------- Delivery
    section('Delivery');

    check('a run writes the file, records the run and returns a URL', function() use ($plugin, $csvFeed) {
        global $createdFiles;

        $run = $plugin->getFeeds()->run($csvFeed, 'test');
        $createdFiles[] = $csvFeed->getFilePath();

        if ($run->getIsError()) {
            return 'run failed: ' . $run->message;
        }

        return is_file($csvFeed->getFilePath())
            && $run->itemCount === 4
            && $run->byteSize > 0
            && $run->url === $csvFeed->getUrl()
            ?: Json::encode(['path' => $csvFeed->getFilePath(), 'run' => $run->toArray()]);
    });

    check('no temporary file is left behind', function() {
        $temp = Craft::$app->getPath()->getTempPath();
        $leftovers = glob($temp . '/eat_*') ?: [];

        return $leftovers === [] ?: Json::encode($leftovers);
    });

    check('the run advanced the schedule', function() use ($plugin, $csvFeed) {
        $feed = makeFeed(['channel' => 'custom', 'format' => 'csv', 'interval' => 3600]);
        $before = $feed->getIsDue();
        $plugin->getFeeds()->run($feed, 'test');
        $fresh = $plugin->getFeeds()->getFeedById($feed->id);

        return $before && !$fresh->getIsDue() && $fresh->lastGeneratedAt !== null
            ?: 'due before: ' . var_export($before, true) . ', due after: ' . var_export($fresh->getIsDue(), true);
    });

    check('a feed the channel fetches is served over HTTP', function() use ($plugin, $csvFeed) {
        $url = $csvFeed->getUrl();

        if ($url === null) {
            return 'the feed has no URL';
        }

        $client = Craft::createGuzzleClient(['timeout' => 20, 'verify' => false]);
        $response = $client->get($url, ['http_errors' => false]);
        $body = (string)$response->getBody();

        return $response->getStatusCode() === 200 && str_contains($body, 'EAT-A')
            ?: 'HTTP ' . $response->getStatusCode() . ': ' . substr($body, 0, 120);
    });

    check('the live route serves the feed too', function() use ($plugin, $csvFeed) {
        $liveFeed = makeFeed(['channel' => 'custom', 'format' => 'json']);
        $liveFeed->setOptions(array_merge($liveFeed->getOptions(), ['liveRoute' => true]));
        $liveFeed->setMappings([['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true]]);
        $plugin->getFeeds()->saveFeed($liveFeed);

        $client = Craft::createGuzzleClient(['timeout' => 30, 'verify' => false]);
        $response = $client->get($liveFeed->getUrl(), ['http_errors' => false]);
        $body = (string)$response->getBody();

        return $response->getStatusCode() === 200 && Json::decodeIfJson($body) !== null
            ?: 'HTTP ' . $response->getStatusCode() . ': ' . substr($body, 0, 200);
    });

    check('a volume delivery with no volume fails loudly instead of silently', function() use ($plugin) {
        $feed = makeFeed(['channel' => 'custom', 'format' => 'csv']);
        $feed->setDelivery(['mode' => 'volume', 'volumeId' => null]);
        $plugin->getFeeds()->saveFeed($feed);

        $run = $plugin->getFeeds()->run($feed, 'test');

        if ($run->status !== Run::STATUS_PARTIAL) {
            return 'status is ' . $run->status;
        }

        return str_contains((string)$run->message, 'volume') ?: 'message is ' . $run->message;
    });

    check('the local file is still written when the remote destination fails', function() use ($plugin) {
        $feed = makeFeed(['channel' => 'custom', 'format' => 'csv']);
        $feed->setDelivery(['mode' => 'ftp', 'ftp' => ['host' => 'ftp.invalid.example', 'port' => 21]]);
        $plugin->getFeeds()->saveFeed($feed);

        $run = $plugin->getFeeds()->run($feed, 'test');

        return is_file($feed->getFilePath()) && $run->status === Run::STATUS_PARTIAL
            ?: 'status ' . $run->status . ', file ' . var_export(is_file($feed->getFilePath()), true);
    });

    check('deleting a feed takes its generated file with it', function() use ($plugin) {
        $feed = makeFeed(['channel' => 'custom', 'format' => 'csv']);
        $plugin->getFeeds()->run($feed, 'test');
        $path = $feed->getFilePath();

        if (!is_file($path)) {
            return 'the file was never written';
        }

        $plugin->getFeeds()->deleteFeedById($feed->id);

        return !is_file($path) ?: 'the file outlived the feed';
    });

    // ------------------------------------------------------------- Run log
    section('Run log');

    check('runs are recorded against their feed', function() use ($plugin, $csvFeed) {
        $runs = $plugin->getRuns()->getRuns($csvFeed->id, 10);

        return count($runs) >= 1 && $runs[0]->feedId === $csvFeed->id ?: 'no runs found';
    });

    check('a failed run keeps the reason', function() use ($plugin) {
        $run = new Run([
            'feedId' => $GLOBALS['createdFeeds'][0]->id,
            'status' => Run::STATUS_ERROR,
            'message' => 'a deliberate failure',
        ]);
        $run->setDetails(['exception' => 'RuntimeException']);
        $plugin->getRuns()->record($run);

        $fresh = $plugin->getRuns()->getRunById($run->id);

        return $fresh->getIsError()
            && $fresh->message === 'a deliberate failure'
            && ($fresh->getDetails()['exception'] ?? '') === 'RuntimeException'
            ?: 'the run came back wrong';
    });

    check('the size label is human readable', function() {
        return (new Run(['byteSize' => 900]))->getSizeLabel() === '900 B'
            && (new Run(['byteSize' => 2048]))->getSizeLabel() === '2 KB'
            && (new Run(['byteSize' => 3145728]))->getSizeLabel() === '3 MB'
            ?: 'sizes are wrong';
    });

    check('pruning keeps the newest runs', function() use ($plugin) {
        $feed = makeFeed(['channel' => 'custom', 'format' => 'csv']);
        $original = $plugin->getSettings()->runsToKeep;
        $plugin->getSettings()->runsToKeep = 3;

        for ($i = 0; $i < 6; $i++) {
            $plugin->getRuns()->record(new Run(['feedId' => $feed->id, 'itemCount' => $i]));
        }

        $kept = $plugin->getRuns()->getRuns($feed->id, 50);
        $plugin->getSettings()->runsToKeep = $original;

        return count($kept) === 3 ?: 'kept ' . count($kept);
    });

    // -------------------------------------------------------------- Taxonomy
    section('Taxonomy');

    $productTypeHandle = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0]->handle;

    check('a mapping saves and reads back', function() use ($plugin, $productTypeHandle) {
        $plugin->getTaxonomy()->save('google', 'productType', $productTypeHandle, 'Apparel & Accessories > Clothing');

        return $plugin->getTaxonomy()->lookup('google', 'productType', $productTypeHandle) === 'Apparel & Accessories > Clothing'
            ?: 'lookup failed';
    });

    check('the map is per channel', function() use ($plugin, $productTypeHandle) {
        return $plugin->getTaxonomy()->lookup('meta', 'productType', $productTypeHandle) === null ?: 'channels share a map';
    });

    check('a taxonomy source fills the attribute', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->channel = 'google';
        $feed->setOptions(array_merge($feed->getOptions(), ['skipIncomplete' => false]));
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
            ['attribute' => 'google_product_category', 'source' => 'taxonomy', 'value' => 'productType', 'enabled' => true],
        ]);
        $rows = $plugin->getGenerator()->preview($feed, 1);

        return ($rows[0]['google_product_category'] ?? '') === 'Apparel & Accessories > Clothing'
            ?: Json::encode($rows[0] ?? []);
    });

    check('clearing a mapping removes it', function() use ($plugin, $productTypeHandle) {
        $plugin->getTaxonomy()->save('google', 'productType', $productTypeHandle, '');

        return $plugin->getTaxonomy()->lookup('google', 'productType', $productTypeHandle) === null ?: 'it survived';
    });

    // ---------------------------------------------------------- Portability
    section('Export and import');

    check('a feed round-trips through its config', function() use ($plugin, $csvFeed) {
        $config = $plugin->getFeeds()->toConfig($csvFeed);
        $config['handle'] = 'eatimported' . bin2hex(random_bytes(3));
        $config['name'] = 'Imported';

        $imported = $plugin->getFeeds()->fromConfig($config);

        if (!$plugin->getFeeds()->saveFeed($imported)) {
            return 'import failed: ' . Json::encode($imported->getErrors());
        }

        $GLOBALS['createdFeeds'][] = $imported;

        $a = Json::encode(array_map(static fn($m) => $m->toConfig(), $csvFeed->getMappings()));
        $b = Json::encode(array_map(static fn($m) => $m->toConfig(), $imported->getMappings()));

        return $a === $b && $imported->getFilters() === $csvFeed->getFilters() ?: 'the copy differs';
    });

    check('importing over an existing handle updates rather than duplicates', function() use ($plugin, $csvFeed) {
        $before = count($plugin->getFeeds()->getAllFeeds());
        $config = $plugin->getFeeds()->toConfig($csvFeed);
        $config['name'] = 'Renamed by import';

        $feed = $plugin->getFeeds()->fromConfig($config, $plugin->getFeeds()->getFeedByHandle($csvFeed->handle));
        $plugin->getFeeds()->saveFeed($feed);

        $after = count($plugin->getFeeds()->getAllFeeds());

        return $before === $after && $plugin->getFeeds()->getFeedByHandle($csvFeed->handle)->name === 'Renamed by import'
            ?: "$before feeds became $after";
    });

    check('two feeds cannot share a handle', function() use ($plugin, $csvFeed) {
        $feed = new Feed(['name' => 'Clash', 'handle' => $csvFeed->handle, 'channel' => 'custom', 'format' => 'csv']);

        return !$plugin->getFeeds()->saveFeed($feed) && $feed->hasErrors('handle') ?: 'the handle was reused';
    });

    // ---------------------------------------------------------- Merchant API
    section('Merchant Center mapping');

    check('feed attributes become Content API fields', function() use ($plugin) {
        $resource = $plugin->getMerchant()->productResource([
            'id' => 'SKU-1',
            'title' => 'A shirt',
            'link' => 'https://example.com/shirt',
            'image_link' => 'https://example.com/shirt.jpg',
            'additional_image_link' => ['https://example.com/2.jpg'],
            'price' => '19.99 USD',
            'sale_price' => '9.99 USD',
            'availability' => 'in_stock',
            'is_bundle' => 'yes',
            'product_type' => 'Shirts',
        ], ['targetCountry' => 'gb', 'contentLanguage' => 'EN']);

        if (($resource['offerId'] ?? '') !== 'SKU-1' || ($resource['imageLink'] ?? '') === '') {
            return Json::encode($resource);
        }

        if (($resource['price']['value'] ?? '') !== '19.99' || ($resource['price']['currency'] ?? '') !== 'USD') {
            return 'price is ' . Json::encode($resource['price'] ?? null);
        }

        if (($resource['salePrice']['value'] ?? '') !== '9.99') {
            return 'sale price is ' . Json::encode($resource['salePrice'] ?? null);
        }

        if (!is_array($resource['additionalImageLinks'] ?? null) || !is_array($resource['productTypes'] ?? null)) {
            return 'repeated fields are not arrays';
        }

        if (($resource['isBundle'] ?? null) !== true) {
            return 'isBundle is not a boolean';
        }

        return ($resource['targetCountry'] ?? '') === 'GB' && ($resource['contentLanguage'] ?? '') === 'en'
            ?: 'country/language casing is wrong';
    });

    check('unknown attributes are left out of the API payload', function() use ($plugin) {
        $resource = $plugin->getMerchant()->productResource(['id' => 'x', 'made_up' => 'y'], []);

        return !isset($resource['made_up']) ?: 'an unknown attribute was sent to Google';
    });

    check('base64url leaves no padding or unsafe characters', function() use ($plugin) {
        $encoded = $plugin->getMerchant()->base64Url(random_bytes(64));

        return !str_contains($encoded, '=') && !str_contains($encoded, '+') && !str_contains($encoded, '/')
            ?: $encoded;
    });

    check('bad credentials are rejected before any request is made', function() use ($plugin) {
        try {
            $plugin->getMerchant()->serviceAccount(['serviceAccount' => '{"not":"a key"}']);
        } catch (Throwable $e) {
            return true;
        }

        return 'nonsense credentials were accepted';
    });

    // ----------------------------------------------------------------- Twig
    section('Twig API');

    check('craft.eat.feed() finds a feed by handle', function() use ($csvFeed) {
        $variable = new EatVariable();

        return $variable->feed($csvFeed->handle)?->id === $csvFeed->id ?: 'not found';
    });

    check('craft.eat.url() gives the URL to hand the channel', function() use ($csvFeed) {
        $variable = new EatVariable();

        return $variable->url($csvFeed->handle) === $csvFeed->getUrl() ?: 'urls differ';
    });

    check('craft.eat.items() renders rows for a template', function() use ($csvFeed) {
        $variable = new EatVariable();
        $items = $variable->items($csvFeed->handle, 2);

        return count($items) === 2 ?: 'got ' . count($items);
    });

    check('craft.eat.lastRun() reports the last run', function() use ($csvFeed) {
        $variable = new EatVariable();

        return $variable->lastRun($csvFeed->handle) !== null ?: 'no run reported';
    });

    // -------------------------------------------------------------- Console
    section('Console commands');

    // Craft's console controllers write straight to STDOUT, which `ob_start()` cannot capture —
    // so these assert what the commands *did*, not what they printed.
    check('eat/feeds lists the feeds', function() {
        return Craft::$app->runAction('eat/feeds/index') === 0 ?: 'the command failed';
    });

    check('eat/feeds/generate runs one feed', function() use ($plugin, $csvFeed) {
        $exit = Craft::$app->runAction('eat/feeds/generate', [$csvFeed->handle]);
        $run = $plugin->getRuns()->getLastRun($csvFeed->id);

        return $exit === 0 && $run->trigger === 'console' && $run->itemCount === 4
            ?: "exit $exit, run " . Json::encode([$run?->trigger, $run?->itemCount]);
    });

    check('eat/feeds/generate refuses an unknown handle', function() {
        return Craft::$app->runAction('eat/feeds/generate', ['no-such-feed']) !== 0 ?: 'it pretended to work';
    });

    check('eat/feeds/preview writes nothing', function() use ($csvFeed) {
        $path = $csvFeed->getFilePath();
        @unlink($path);

        $exit = Craft::$app->runAction('eat/feeds/preview', [$csvFeed->handle]);

        return $exit === 0 && !is_file($path) ?: 'preview wrote a file';
    });

    check('eat/feeds/export and import round-trip through a file', function() use ($plugin) {
        $file = Craft::$app->getPath()->getTempPath() . '/eat-export-test.json';

        Craft::$app->runAction('eat/feeds/export', ['file' => $file]);

        if (!is_file($file)) {
            return 'nothing was exported';
        }

        $config = Json::decodeIfJson((string)file_get_contents($file));
        $isArray = is_array($config) && $config !== [];

        $exit = Craft::$app->runAction('eat/feeds/import', [$file]);

        @unlink($file);

        return $isArray && $exit === 0 ?: 'export/import failed';
    });

    check('eat/runs lists runs', function() {
        return Craft::$app->runAction('eat/runs/index') === 0 ?: 'the command failed';
    });

    // ------------------------------------------------------------ Scheduling
    section('Scheduling');

    // Craft pushes its own jobs (search index, propagation) on every element save, so counting
    // rows in the queue table proves nothing — only Eat's own jobs are counted here.
    $eatJobs = static function(): int {
        return (int)(new craft\db\Query())
            ->from(craft\db\Table::QUEUE)
            ->where(['like', 'description', 'product feed'])
            ->count('[[id]]');
    };

    check('due feeds are queued, and only once', function() use ($plugin, $eatJobs) {
        $feed = makeFeed(['channel' => 'custom', 'format' => 'csv', 'interval' => 3600]);
        $plugin->getFeeds()->clearCaches();

        $before = $eatJobs();
        $first = $plugin->getFeeds()->queueDue('test');
        $second = $plugin->getFeeds()->queueDue('test');
        $after = $eatJobs();

        Craft::$app->getCache()->delete('eat:queued:' . $feed->id);

        return $first >= 1 && $second === 0 && $after === $before + $first
            ?: "queued $first then $second, Eat jobs went from $before to $after";
    });

    check('saving a product queues a regenerate-on-save feed', function() use ($plugin, $eatJobs, $productA) {
        $feed = makeFeed(['channel' => 'custom', 'format' => 'csv', 'regenerateOnSave' => true]);
        $plugin->getFeeds()->clearCaches();
        Craft::$app->getCache()->delete('eat:queued:' . $feed->id);

        $before = $eatJobs();
        Craft::$app->getElements()->saveElement($productA);
        $after = $eatJobs();

        Craft::$app->getCache()->delete('eat:queued:' . $feed->id);

        return $after > $before ?: 'nothing was queued';
    });

    check('a second product save does not queue a second job', function() use ($plugin, $eatJobs, $productA, $productB) {
        $feed = makeFeed(['channel' => 'custom', 'format' => 'csv', 'regenerateOnSave' => true]);
        $plugin->getFeeds()->clearCaches();

        Craft::$app->getElements()->saveElement($productA);
        $between = $eatJobs();
        Craft::$app->getElements()->saveElement($productB);
        $after = $eatJobs();

        Craft::$app->getCache()->delete('eat:queued:' . $feed->id);

        return $after === $between ?: "Eat jobs went from $between to $after on the second save";
    });

    check('the queue job generates the feed it was given', function() use ($plugin) {
        $feed = makeFeed(['channel' => 'custom', 'format' => 'csv']);
        $job = new justinholtweb\eat\jobs\GenerateFeed(['feedId' => $feed->id, 'feedName' => (string)$feed->name]);
        $job->execute(Craft::$app->getQueue());

        $run = $plugin->getRuns()->getLastRun($feed->id);
        @unlink($feed->getFilePath());

        return $run !== null && $run->trigger === 'schedule' ?: 'the job recorded nothing';
    });

    check('switching channel gives the feed the new channel’s attributes', function() {
        $googleKeys = array_map(static fn($m) => $m->attribute, Registry::get('google')->defaultMappings());
        $awinKeys = array_map(static fn($m) => $m->attribute, Registry::get('awin')->defaultMappings());

        return in_array('merchant_product_id', $awinKeys, true) && !in_array('merchant_product_id', $googleKeys, true)
            ?: 'the channels share an attribute vocabulary';
    });

    // -------------------------------------------------------------- Editions
    section('Lite');

    switchEdition(Plugin::EDITION_LITE);
    $plugin->getFeeds()->clearCaches();

    check('Lite is not Pro', fn() => !$plugin->isPro() ?: 'still Pro');

    check('Lite refuses a third feed', function() use ($plugin) {
        $feed = new Feed([
            'name' => 'Third',
            'handle' => 'eatthird' . bin2hex(random_bytes(3)),
            'channel' => 'google',
            'format' => 'rss',
        ]);

        return !$plugin->getFeeds()->saveFeed($feed) && $feed->hasErrors('name') ?: 'Lite took a third feed';
    });

    check('Lite allows Google and Meta but not TikTok', function() use ($plugin) {
        return $plugin->getFeeds()->channelIsAllowed('google')
            && $plugin->getFeeds()->channelIsAllowed('meta')
            && !$plugin->getFeeds()->channelIsAllowed('tiktok')
            ?: 'the channel gate is wrong';
    });

    check('Lite ignores output modifiers', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true, 'modifiers' => [['type' => 'lower']]],
        ]);
        $rows = $plugin->getGenerator()->preview($feed, 1);

        return ($rows[0]['id'] ?? '') === strtoupper($rows[0]['id'] ?? '') ?: 'a modifier ran on Lite';
    });

    check('Lite ignores a Twig template source', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setMappings([
            ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => true],
            ['attribute' => 'custom', 'source' => 'template', 'value' => '{{ object.sku }}', 'enabled' => true],
        ]);
        $rows = $plugin->getGenerator()->preview($feed, 1);

        return !isset($rows[0]['custom']) ?: 'a template ran on Lite';
    });

    check('Lite ignores the product condition', function() use ($plugin, $csvFeed) {
        $feed = clone $csvFeed;
        $feed->setProductCondition([
            'conditionRules' => [
                ['class' => \craft\elements\conditions\SlugConditionRule::class, 'value' => 'nothing-matches-this'],
            ],
        ]);

        return count($plugin->getGenerator()->preview($feed, 100)) === 4 ?: 'the condition was applied on Lite';
    });

    check('Lite falls back to file delivery', function() use ($plugin) {
        $feed = $plugin->getFeeds()->getAllFeeds()[0];
        $feed->setDelivery(['mode' => 'sftp', 'sftp' => ['host' => 'sftp.invalid.example']]);

        $result = $plugin->getDelivery()->deliver($feed, $feed->getFilePath());
        $modes = array_column($result['results'], 'mode');

        return $modes === ['file'] ?: Json::encode($modes);
    });

    check('Pro-only channels can still be read, so an upgrade loses nothing', function() {
        return Registry::get('tiktok') !== null ?: 'the channel definition disappeared';
    });

    switchEdition(Plugin::EDITION_PRO);
} finally {
    echo "\nCleaning up\n";

    switchEdition($originalEdition);

    foreach ($createdFeeds as $feed) {
        try {
            if ($feed->id) {
                $path = $feed->getFilePath();
                Plugin::getInstance()->getFeeds()->deleteFeedById($feed->id);
                @unlink($path);
            }
        } catch (Throwable $e) {
            echo "  ! could not delete feed {$feed->handle}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $product) {
        try {
            Craft::$app->getElements()->deleteElement($product, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$product->id}: {$e->getMessage()}\n";
        }
    }

    foreach (glob(Craft::$app->getPath()->getTempPath() . '/eat_*') ?: [] as $leftover) {
        @unlink($leftover);
    }

    Craft::$app->getDb()->createCommand()
        ->delete(justinholtweb\eat\db\Table::TAXONOMY, ['channel' => ['google', 'meta']])
        ->execute();

    echo "  cleaned\n";
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
