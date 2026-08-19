<?php

namespace justinholtweb\eat\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\elements\Asset;
use craft\fields\Assets as AssetsField;
use craft\helpers\App;
use craft\helpers\StringHelper;
use justinholtweb\eat\helpers\Value;
use justinholtweb\eat\models\Channel;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\models\Mapping;
use justinholtweb\eat\Plugin;

/**
 * Turning one product (and the variant standing in for it) into the values a channel asked for.
 *
 * Everything a merchant can reach without writing Twig lives in `attributeValue()`; the names in
 * that match are the documented vocabulary.
 */
class Resolver extends Component
{
    /** Field handles a product description is looked for in, in order, before falling back. */
    public const DESCRIPTION_HANDLES = ['description', 'productDescription', 'shortDescription', 'summary', 'excerpt', 'body', 'bodyContent'];

    /** @var array<string, array<int, Asset>> */
    private array $_imageCache = [];

    /** @var array<int, string> */
    private array $_typeNames = [];

    /**
     * Every value for one feed row.
     *
     * @return array<string, string|array> attribute key => value
     */
    public function resolve(Feed $feed, Product $product, ?Variant $variant): array
    {
        $channel = $feed->getChannelDefinition();
        $pro = Plugin::getInstance()->isPro();
        $values = [];

        foreach ($feed->getActiveMappings() as $mapping) {
            $value = $this->resolveMapping($feed, $channel, $mapping, $product, $variant);

            if ($pro && $mapping->modifiers) {
                $value = Value::applyModifiers($value, $mapping->modifiers);
            }

            if ($value === '' || $value === []) {
                continue;
            }

            $values[$mapping->attribute] = $value;
        }

        return $values;
    }

    public function resolveMapping(Feed $feed, ?Channel $channel, Mapping $mapping, Product $product, ?Variant $variant): string|array
    {
        switch ($mapping->source) {
            case Mapping::SOURCE_STATIC:
                return (string)($mapping->value ?? '');

            case Mapping::SOURCE_ATTRIBUTE:
                return $this->attributeValue((string)$mapping->value, $feed, $channel, $product, $variant);

            case Mapping::SOURCE_FIELD:
                return $this->fieldValue((string)$mapping->value, $product, $variant);

            case Mapping::SOURCE_TAXONOMY:
                return $this->taxonomyValue($feed, (string)($mapping->value ?: 'productType'), $product, $variant);

            case Mapping::SOURCE_TEMPLATE:
                // The escape hatch, and the only place a merchant's own code runs.
                if (!Plugin::getInstance()->isPro()) {
                    return '';
                }

                return $this->templateValue((string)$mapping->value, $product, $variant);
        }

        return '';
    }

    /**
     * The documented attribute vocabulary.
     */
    public function attributeValue(string $name, Feed $feed, ?Channel $channel, Product $product, ?Variant $variant): string|array
    {
        $options = $feed->getOptions();
        $variant ??= $product->getDefaultVariant();

        return match ($name) {
            'id' => (string)($variant?->id ?? $product->id),
            'productId' => (string)$product->id,
            'variantId' => (string)($variant?->id ?? ''),
            'uid' => (string)$product->uid,
            'sku' => (string)($variant?->getSku() ?? ''),
            'title', 'productTitle' => (string)$product->title,
            'variantTitle' => (string)($variant?->title ?? ''),
            'fullTitle' => $this->_fullTitle($product, $variant),
            'slug' => (string)$product->slug,
            'description' => $this->_description($product, $variant, false),
            'plainDescription' => $this->_description($product, $variant, true),
            'url' => $this->_url($feed, $product),
            'imageUrl' => $this->_imageUrls($feed, $product, $variant)[0] ?? '',
            'additionalImageUrls' => array_slice($this->_imageUrls($feed, $product, $variant), 1),
            'allImageUrls' => $this->_imageUrls($feed, $product, $variant),
            'imageCount' => (string)count($this->_imageUrls($feed, $product, $variant)),
            'price' => $this->_money($variant?->getPrice(), $options),
            'basePrice' => $this->_money($variant?->basePrice, $options),
            'promotionalPrice' => $this->_money($variant?->getPromotionalPrice(), $options),
            'salePrice' => $this->_money($variant?->getSalePrice(), $options),
            'priceWithCurrency' => $this->_withCurrency($this->_money($variant?->getPrice(), $options), $feed),
            'promotionalPriceWithCurrency' => $this->_withCurrency($this->_money($variant?->getPromotionalPrice(), $options), $feed),
            'salePriceWithCurrency' => $this->_withCurrency($this->_money($variant?->getSalePrice(), $options), $feed),
            'currency' => $this->currency($feed),
            'availability' => $this->_availability($channel, $variant),
            'stock' => $this->_stock($variant),
            'inStock' => $variant && ($variant->getHasUnlimitedStock() || $variant->getStock() > 0) ? 'yes' : 'no',
            'condition' => (string)($channel?->condition ?? 'new'),
            'brand' => $this->_brand(),
            'productType' => (string)$product->getType()->handle,
            'productTypeName' => $this->_typeName($product),
            'status' => (string)$product->getStatus(),
            'itemGroupId' => (string)$product->id,
            'weight' => $variant?->weight !== null ? (string)$variant->weight : '',
            'weightWithUnit' => $variant?->weight !== null ? trim($variant->weight . ' ' . $this->weightUnit()) : '',
            'length' => $variant?->length !== null ? (string)$variant->length : '',
            'width' => $variant?->width !== null ? (string)$variant->width : '',
            'height' => $variant?->height !== null ? (string)$variant->height : '',
            'dimensions' => $this->_dimensions($variant),
            'dateCreated' => $product->dateCreated?->format('Y-m-d\TH:i:sP') ?? '',
            'dateUpdated' => $product->dateUpdated?->format('Y-m-d\TH:i:sP') ?? '',
            'postDate' => $product->postDate?->format('Y-m-d\TH:i:sP') ?? '',
            'expiryDate' => $product->expiryDate?->format('Y-m-d\TH:i:sP') ?? '',
            'siteName' => (string)($feed->getSite()?->name ?? ''),
            'storeName' => $this->_storeName($feed),
            'taxCategory' => $this->_safe(static fn() => $variant?->getTaxCategory()?->name),
            'shippingCategory' => $this->_safe(static fn() => $variant?->getShippingCategory()?->name),
            'freeShipping' => $variant?->freeShipping ? 'yes' : 'no',
            'minQty' => (string)($variant?->minQty ?? ''),
            'maxQty' => (string)($variant?->maxQty ?? ''),
            'variantCount' => (string)$product->getVariants()->count(),
            default => '',
        };
    }

    /**
     * `myField`, or `myField.0.url` to walk into it. Variant fields win over product fields, since
     * that is where a variant-specific override would live.
     */
    public function fieldValue(string $path, Product $product, ?Variant $variant): string|array
    {
        if ($path === '') {
            return '';
        }

        $segments = explode('.', $path);
        $handle = array_shift($segments);

        foreach ([$variant, $product] as $element) {
            if ($element === null || !$this->_hasField($element, $handle)) {
                continue;
            }

            try {
                $value = $element->getFieldValue($handle);
            } catch (\Throwable) {
                continue;
            }

            if ($segments) {
                $value = Value::traverse($value, $segments);
            }

            $flat = Value::stringify($value);

            if ($flat !== '' && $flat !== []) {
                return $flat;
            }
        }

        return '';
    }

    public function taxonomyValue(Feed $feed, string $sourceType, Product $product, ?Variant $variant): string
    {
        $key = match ($sourceType) {
            'productType' => (string)$product->getType()->handle,
            default => $this->fieldValue($sourceType, $product, $variant),
        };

        if (is_array($key)) {
            $key = $key[0] ?? '';
        }

        if ($key === '') {
            return '';
        }

        $mapped = Plugin::getInstance()->getTaxonomy()->lookup($feed->channel, $sourceType === 'productType' ? 'productType' : 'field', (string)$key);

        return (string)($mapped ?? '');
    }

    public function templateValue(string $template, Product $product, ?Variant $variant): string
    {
        if ($template === '') {
            return '';
        }

        $object = $variant ?? $product;

        try {
            return trim((string)Craft::$app->getView()->renderObjectTemplate($template, $object, [
                'product' => $product,
                'variant' => $variant,
            ]));
        } catch (\Throwable $e) {
            Craft::warning('Feed template failed: ' . $e->getMessage(), 'eat');
            return '';
        }
    }

    public function currency(Feed $feed): string
    {
        $override = $feed->getOption('currency');

        if ($override) {
            return strtoupper((string)$override);
        }

        try {
            $store = $this->_store($feed);

            return strtoupper((string)($store?->getCurrency()?->getCode() ?? 'USD'));
        } catch (\Throwable) {
            return 'USD';
        }
    }

    public function weightUnit(): string
    {
        try {
            return (string)Commerce::getInstance()->getSettings()->weightUnits;
        } catch (\Throwable) {
            return 'kg';
        }
    }

    public function dimensionUnit(): string
    {
        try {
            return (string)Commerce::getInstance()->getSettings()->dimensionUnits;
        } catch (\Throwable) {
            return 'cm';
        }
    }

    public function clearCaches(): void
    {
        $this->_imageCache = [];
        $this->_typeNames = [];
    }

    // Private
    // -------------------------------------------------------------------------

    private function _store(Feed $feed): ?\craft\commerce\models\Store
    {
        $stores = Commerce::getInstance()->getStores();

        if ($feed->storeId) {
            return $stores->getStoreById($feed->storeId);
        }

        if ($feed->siteId) {
            return $stores->getStoreBySiteId($feed->siteId);
        }

        return $stores->getPrimaryStore();
    }

    private function _storeName(Feed $feed): string
    {
        try {
            return (string)($this->_store($feed)?->getName() ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function _fullTitle(Product $product, ?Variant $variant): string
    {
        $title = (string)$product->title;
        $variantTitle = (string)($variant?->title ?? '');

        if ($variantTitle === '' || $variantTitle === $title) {
            return $title;
        }

        return $title . ' - ' . $variantTitle;
    }

    private function _description(Product $product, ?Variant $variant, bool $plain): string
    {
        $value = '';

        foreach (self::DESCRIPTION_HANDLES as $handle) {
            $candidate = $this->fieldValue($handle, $product, $variant);

            if (is_array($candidate)) {
                $candidate = implode(' ', $candidate);
            }

            if ($candidate !== '') {
                $value = $candidate;
                break;
            }
        }

        if ($value === '') {
            $value = (string)$product->title;
        }

        if (!$plain) {
            return $value;
        }

        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }

    private function _url(Feed $feed, Product $product): string
    {
        $url = (string)($product->getUrl() ?? '');

        if ($url === '') {
            return '';
        }

        $options = $feed->getOptions();

        return Value::appendQuery($url, [
            'utm_source' => $options['utmSource'] ?? null,
            'utm_medium' => $options['utmMedium'] ?? null,
            'utm_campaign' => $options['utmCampaign'] ?? null,
            'utm_term' => $options['utmTerm'] ?? null,
            'utm_content' => $options['utmContent'] ?? null,
        ]);
    }

    /**
     * @return string[]
     */
    private function _imageUrls(Feed $feed, Product $product, ?Variant $variant): array
    {
        $cacheKey = $feed->id . ':' . $product->id . ':' . ($variant?->id ?? 0);

        if (isset($this->_imageCache[$cacheKey])) {
            return $this->_imageCache[$cacheKey];
        }

        $transform = $feed->getOption('imageTransform') ?: Plugin::getInstance()->getSettings()->imageTransform;
        $transform = $transform !== '' ? $transform : null;
        $urls = [];

        foreach ([$variant, $product] as $element) {
            if ($element === null) {
                continue;
            }

            foreach ($this->_assetFields($element) as $handle) {
                try {
                    $value = $element->getFieldValue($handle);
                } catch (\Throwable) {
                    continue;
                }

                $assets = $value instanceof \craft\elements\db\AssetQuery ? $value->all() : (is_array($value) ? $value : []);

                foreach ($assets as $asset) {
                    if (!$asset instanceof Asset) {
                        continue;
                    }

                    try {
                        $url = (string)$asset->getUrl($transform);
                    } catch (\Throwable) {
                        $url = (string)$asset->getUrl();
                    }

                    if ($url !== '' && !in_array($url, $urls, true)) {
                        $urls[] = $url;
                    }
                }
            }
        }

        return $this->_imageCache[$cacheKey] = $urls;
    }

    /**
     * @return string[]
     */
    private function _assetFields(ElementInterface $element): array
    {
        $handles = [];
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return $handles;
        }

        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof AssetsField) {
                $handles[] = $field->handle;
            }
        }

        return $handles;
    }

    private function _hasField(ElementInterface $element, string $handle): bool
    {
        $layout = $element->getFieldLayout();

        return $layout !== null && $layout->getFieldByHandle($handle) !== null;
    }

    private function _money(?float $amount, array $options): string
    {
        if ($amount === null) {
            return '';
        }

        $multiplier = $options['priceMultiplier'] ?? null;

        if ($multiplier !== null && $multiplier !== '' && is_numeric($multiplier)) {
            $amount *= (float)$multiplier;
        }

        return number_format($amount, 2, '.', '');
    }

    private function _withCurrency(string $amount, Feed $feed): string
    {
        if ($amount === '') {
            return '';
        }

        return $amount . ' ' . $this->currency($feed);
    }

    private function _availability(?Channel $channel, ?Variant $variant): string
    {
        $state = 'out';

        if ($variant !== null) {
            if ($variant->getHasUnlimitedStock() || !$variant->inventoryTracked || $variant->getStock() > 0) {
                $state = 'in';
            } elseif ($variant->getIsOutOfStockPurchasingAllowed()) {
                $state = 'backorder';
            }
        }

        return $channel?->availabilityWord($state) ?? $state;
    }

    private function _stock(?Variant $variant): string
    {
        if ($variant === null) {
            return '0';
        }

        if ($variant->getHasUnlimitedStock() || !$variant->inventoryTracked) {
            return '999';
        }

        return (string)max(0, $variant->getStock());
    }

    private function _brand(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $brand = trim((string)App::parseEnv($settings->defaultBrand));

        if ($brand !== '') {
            return $brand;
        }

        return (string)(Craft::$app->getSystemName() ?: '');
    }

    private function _typeName(Product $product): string
    {
        $type = $product->getType();

        return $this->_typeNames[$type->id] ??= (string)$type->name;
    }

    private function _dimensions(?Variant $variant): string
    {
        if ($variant === null || $variant->length === null || $variant->width === null || $variant->height === null) {
            return '';
        }

        $unit = $this->dimensionUnit();

        return sprintf('%sx%sx%s %s', $variant->length, $variant->width, $variant->height, $unit);
    }

    private function _safe(callable $callback): string
    {
        try {
            return (string)($callback() ?? '');
        } catch (\Throwable) {
            return '';
        }
    }
}
