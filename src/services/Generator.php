<?php

namespace justinholtweb\eat\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\db\ProductQuery;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\helpers\FileHelper;
use Generator as PhpGenerator;
use justinholtweb\eat\formats\Writers;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\Plugin;
use Throwable;

/**
 * The one place a product becomes feed rows.
 *
 * The CP preview, the live route, the scheduled write and `eat/feeds/generate` all iterate
 * `rows()`. There is deliberately no second path: a preview that is not the feed is worse than no
 * preview at all.
 */
class Generator extends Component
{
    /**
     * The product query a feed's filters describe.
     */
    public function query(Feed $feed): ProductQuery
    {
        $filters = $feed->getFilters();

        /** @var ProductQuery $query */
        $query = Product::find();
        $query->siteId($feed->siteId ?: Craft::$app->getSites()->getPrimarySite()->id);

        $statuses = array_values(array_filter((array)($filters['statuses'] ?? [])));
        $query->status($statuses ?: null);

        $types = array_values(array_filter((array)($filters['productTypes'] ?? [])));

        if ($types) {
            $query->type($types);
        }

        $excludeIds = array_values(array_filter(array_map('intval', (array)($filters['excludeIds'] ?? []))));

        if ($excludeIds) {
            $query->id(array_merge(['not'], $excludeIds));
        }

        if (Plugin::getInstance()->isPro() && $feed->hasProductCondition()) {
            $feed->getProductCondition()->modifyQuery($query);
        }

        $query->orderBy(['elements.id' => SORT_ASC]);

        return $query;
    }

    /**
     * Yield one row per feed item.
     *
     * @return PhpGenerator<int, array{values: array<string, string|array>, product: Product, variant: Variant|null}>
     */
    public function rows(Feed $feed, ?int $limit = null, ?array &$stats = null): PhpGenerator
    {
        $stats ??= [];
        $stats['skipped'] = 0;
        $stats['reasons'] = [];

        $filters = $feed->getFilters();
        $resolver = Plugin::getInstance()->getResolver();
        $channel = $feed->getChannelDefinition();
        $required = $channel ? $channel->getRequiredKeys() : [];
        $requiredMapped = [];

        foreach ($required as $key) {
            $mapping = $feed->getMapping($key);

            if ($mapping !== null && $mapping->isActive()) {
                $requiredMapped[] = $key;
            }
        }

        $skipIncomplete = (bool)($feed->getOption('skipIncomplete') ?? true);
        $excludeSkus = array_values(array_filter(array_map('strval', (array)($filters['excludeSkus'] ?? []))));
        $maxRows = $limit ?? ($filters['limit'] ? (int)$filters['limit'] : null);
        $batchSize = max(1, (int)($feed->getOption('batchSize') ?: Plugin::getInstance()->getSettings()->batchSize));
        $emitted = 0;

        foreach ($this->query($feed)->batch($batchSize) as $products) {
            /** @var Product $product */
            foreach ($products as $product) {
                foreach ($this->_variantsFor($feed, $product) as $variant) {
                    if ($maxRows !== null && $emitted >= $maxRows) {
                        return;
                    }

                    $reason = $this->_skipReason($feed, $filters, $excludeSkus, $product, $variant);

                    if ($reason !== null) {
                        $this->_countSkip($stats, $reason);
                        continue;
                    }

                    try {
                        $values = $resolver->resolve($feed, $product, $variant);
                    } catch (Throwable $e) {
                        Craft::warning("Eat could not resolve product {$product->id}: " . $e->getMessage(), 'eat');
                        $this->_countSkip($stats, 'error');
                        continue;
                    }

                    if ($skipIncomplete) {
                        $missing = [];

                        foreach ($requiredMapped as $key) {
                            $value = $values[$key] ?? '';

                            if ($value === '' || $value === []) {
                                $missing[] = $key;
                            }
                        }

                        if ($missing) {
                            $this->_countSkip($stats, 'missing:' . implode(',', $missing));
                            continue;
                        }
                    }

                    $emitted++;

                    yield ['values' => $values, 'product' => $product, 'variant' => $variant];
                }
            }

            // A batch's elements are the only thing holding memory across the loop.
            $resolver->clearCaches();
        }
    }

    /**
     * The first few rows, for the CP preview and `eat/feeds/preview`. Same generator, same values.
     *
     * @return array<int, array<string, string|array>>
     */
    public function preview(Feed $feed, int $limit = 10, ?array &$stats = null): array
    {
        $rows = [];

        foreach ($this->rows($feed, $limit, $stats) as $row) {
            $rows[] = $row['values'];
        }

        return $rows;
    }

    /**
     * Render a feed into a temporary file.
     *
     * @return array{path: string, itemCount: int, skipped: int, reasons: array<string, int>, bytes: int}
     */
    public function write(Feed $feed, ?int $limit = null): array
    {
        $temp = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'eat_' . ($feed->handle ?: 'feed') . '_' . bin2hex(random_bytes(4));
        $handle = fopen($temp, 'wb');

        if ($handle === false) {
            throw new \RuntimeException("Could not open a temporary file for feed “{$feed->handle}”.");
        }

        $columns = [];

        foreach ($feed->getActiveMappings() as $mapping) {
            $columns[] = $mapping->attribute;
        }

        $writer = Writers::create($handle, $feed, $feed->getChannelDefinition(), $columns);
        $stats = [];

        try {
            $writer->open();

            foreach ($this->rows($feed, $limit, $stats) as $row) {
                $writer->write($row['values']);
            }

            $writer->close();
        } finally {
            fclose($handle);
        }

        if ($feed->getOption('compress')) {
            $temp = $this->_compress($temp);
        }

        return [
            'path' => $temp,
            'itemCount' => $writer->getWritten(),
            'skipped' => (int)($stats['skipped'] ?? 0),
            'reasons' => (array)($stats['reasons'] ?? []),
            'bytes' => (int)@filesize($temp),
        ];
    }

    /**
     * @return Variant[]|array<int, Variant|null>
     */
    private function _variantsFor(Feed $feed, Product $product): array
    {
        $filters = $feed->getFilters();
        $includeDisabled = (bool)($filters['includeDisabledVariants'] ?? false);

        return match ($feed->variantMode) {
            Feed::VARIANT_MODE_DEFAULT => array_filter([$product->getDefaultVariant($includeDisabled)]),
            Feed::VARIANT_MODE_PRODUCT => array_filter([$product->getCheapestVariant($includeDisabled) ?? $product->getDefaultVariant($includeDisabled)]),
            default => $product->getVariants($includeDisabled)->all(),
        };
    }

    /**
     * Why this row is not in the feed, or null if it is.
     */
    private function _skipReason(Feed $feed, array $filters, array $excludeSkus, Product $product, ?Variant $variant): ?string
    {
        if ($variant === null) {
            return 'no-variant';
        }

        $sku = $variant->getSku();

        foreach ($excludeSkus as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if (str_contains($pattern, '*') ? fnmatch($pattern, $sku) : $pattern === $sku) {
                return 'excluded-sku';
            }
        }

        if (($filters['inStockOnly'] ?? false) && $variant->inventoryTracked && !$variant->getHasUnlimitedStock() && $variant->getStock() <= 0) {
            return 'out-of-stock';
        }

        $price = $variant->getPrice();

        if (($filters['requirePrice'] ?? true) && ($price === null || $price <= 0)) {
            return 'no-price';
        }

        if ($filters['minPrice'] !== null && $filters['minPrice'] !== '' && (float)$price < (float)$filters['minPrice']) {
            return 'below-min-price';
        }

        if ($filters['maxPrice'] !== null && $filters['maxPrice'] !== '' && (float)$price > (float)$filters['maxPrice']) {
            return 'above-max-price';
        }

        if ($filters['requireImage'] ?? false) {
            $image = Plugin::getInstance()->getResolver()->attributeValue('imageUrl', $feed, $feed->getChannelDefinition(), $product, $variant);

            if ($image === '' || $image === []) {
                return 'no-image';
            }
        }

        return null;
    }

    private function _countSkip(array &$stats, string $reason): void
    {
        $stats['skipped'] = (int)($stats['skipped'] ?? 0) + 1;
        $stats['reasons'][$reason] = (int)($stats['reasons'][$reason] ?? 0) + 1;
    }

    /**
     * Gzip in a second pass rather than writing through a compressed stream: the writers stay
     * ordinary file writers, and a merchant can still be handed the uncompressed bytes.
     */
    private function _compress(string $path): string
    {
        $target = $path . '.gz';
        $in = fopen($path, 'rb');
        $out = gzopen($target, 'wb9');

        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }

            return $path;
        }

        while (!feof($in)) {
            $chunk = fread($in, 262144);

            if ($chunk === false) {
                break;
            }

            gzwrite($out, $chunk);
        }

        fclose($in);
        gzclose($out);
        FileHelper::unlink($path);

        return $target;
    }
}
