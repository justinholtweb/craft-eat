<?php

namespace justinholtweb\eat\channels;

use Craft;
use justinholtweb\eat\models\Channel;

/**
 * The built-in channel templates.
 *
 * A channel is a *definition*, never code: what a merchant calls their attributes, which of them
 * they insist on, what shape the file takes and what vocabulary they use for availability. Adding
 * a channel means adding an entry here.
 */
abstract class Registry
{
    /** Attributes every Google-family channel shares, in Google's documented order. */
    private const GOOGLE_NS = 'http://base.google.com/ns/1.0';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'google' => self::_google(),
            'google_local' => self::_googleLocal(),
            'meta' => self::_meta(),
            'pinterest' => self::_pinterest(),
            'bing' => self::_bing(),
            'tiktok' => self::_tiktok(),
            'snapchat' => self::_snapchat(),
            'criteo' => self::_criteo(),
            'awin' => self::_awin(),
            'idealo' => self::_idealo(),
            'kelkoo' => self::_kelkoo(),
            'pricerunner' => self::_priceRunner(),
            'shopzilla' => self::_shopzilla(),
            'rakuten' => self::_rakuten(),
            'custom' => self::_custom(),
        ];
    }

    /**
     * @return Channel[]
     */
    public static function all(): array
    {
        $channels = [];

        foreach (self::definitions() as $id => $definition) {
            $channels[$id] = new Channel(['id' => $id] + $definition);
        }

        return $channels;
    }

    public static function get(string $id): ?Channel
    {
        return self::all()[$id] ?? null;
    }

    /**
     * One attribute definition.
     *
     * `$source` is what the attribute maps to out of the box — the whole point of a channel
     * template is that a merchant does not have to know that `g:id` wants the SKU.
     */
    private static function attr(
        string $key,
        string $label,
        bool $required = false,
        string $source = 'attribute',
        ?string $value = null,
        bool $ns = true,
        string $description = '',
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'required' => $required,
            'source' => $value === null ? 'none' : $source,
            'value' => $value,
            'ns' => $ns,
            'description' => $description,
        ];
    }

    private static function _google(): array
    {
        return [
            'name' => 'Google Shopping',
            'description' => 'Google Merchant Center primary product feed.',
            'docsUrl' => 'https://support.google.com/merchants/answer/7052112',
            'formats' => ['rss', 'xml', 'csv', 'tsv', 'txt', 'json'],
            'defaultFormat' => 'rss',
            'rss' => true,
            'xmlRoot' => 'rss',
            'xmlItem' => 'item',
            'namespaces' => ['g' => self::GOOGLE_NS],
            'prefix' => 'g',
            'availability' => ['in' => 'in_stock', 'out' => 'out_of_stock', 'preorder' => 'preorder', 'backorder' => 'backorder'],
            'condition' => 'new',
            'taxonomyAttribute' => 'google_product_category',
            'attributes' => [
                self::attr('id', 'ID', true, 'attribute', 'sku', true, 'Unique, stable, and never reused.'),
                self::attr('title', 'Title', true, 'attribute', 'title', false),
                self::attr('description', 'Description', true, 'attribute', 'plainDescription', false),
                self::attr('link', 'Link', true, 'attribute', 'url', false),
                self::attr('image_link', 'Image link', true, 'attribute', 'imageUrl'),
                self::attr('additional_image_link', 'Additional image links', false, 'attribute', 'additionalImageUrls'),
                self::attr('availability', 'Availability', true, 'attribute', 'availability'),
                self::attr('availability_date', 'Availability date'),
                self::attr('price', 'Price', true, 'attribute', 'priceWithCurrency'),
                self::attr('sale_price', 'Sale price', false, 'attribute', 'promotionalPriceWithCurrency'),
                self::attr('sale_price_effective_date', 'Sale price effective date'),
                self::attr('brand', 'Brand', true, 'attribute', 'brand'),
                self::attr('gtin', 'GTIN'),
                self::attr('mpn', 'MPN', false, 'attribute', 'sku'),
                self::attr('identifier_exists', 'Identifier exists'),
                self::attr('condition', 'Condition', false, 'attribute', 'condition'),
                self::attr('google_product_category', 'Google product category', false, 'taxonomy', 'productType'),
                self::attr('product_type', 'Product type', false, 'attribute', 'productTypeName'),
                self::attr('item_group_id', 'Item group ID', false, 'attribute', 'itemGroupId'),
                self::attr('color', 'Color'),
                self::attr('size', 'Size'),
                self::attr('material', 'Material'),
                self::attr('pattern', 'Pattern'),
                self::attr('age_group', 'Age group'),
                self::attr('gender', 'Gender'),
                self::attr('multipack', 'Multipack'),
                self::attr('is_bundle', 'Is bundle'),
                self::attr('shipping_weight', 'Shipping weight', false, 'attribute', 'weightWithUnit'),
                self::attr('shipping_length', 'Shipping length'),
                self::attr('shipping_width', 'Shipping width'),
                self::attr('shipping_height', 'Shipping height'),
                self::attr('shipping', 'Shipping'),
                self::attr('tax', 'Tax'),
                self::attr('mobile_link', 'Mobile link'),
                self::attr('expiration_date', 'Expiration date'),
                self::attr('custom_label_0', 'Custom label 0'),
                self::attr('custom_label_1', 'Custom label 1'),
                self::attr('custom_label_2', 'Custom label 2'),
                self::attr('custom_label_3', 'Custom label 3'),
                self::attr('custom_label_4', 'Custom label 4'),
            ],
        ];
    }

    private static function _googleLocal(): array
    {
        return [
            'name' => 'Google Local Inventory',
            'description' => 'Local inventory ads — per-store availability and price.',
            'docsUrl' => 'https://support.google.com/merchants/answer/3061342',
            'formats' => ['rss', 'xml', 'csv', 'tsv', 'txt', 'json'],
            'defaultFormat' => 'csv',
            'rss' => true,
            'xmlRoot' => 'rss',
            'xmlItem' => 'item',
            'namespaces' => ['g' => self::GOOGLE_NS],
            'prefix' => 'g',
            'availability' => ['in' => 'in_stock', 'out' => 'out_of_stock', 'preorder' => 'preorder', 'backorder' => 'limited availability'],
            'condition' => 'new',
            'taxonomyAttribute' => null,
            'attributes' => [
                self::attr('store_code', 'Store code', true),
                self::attr('id', 'ID', true, 'attribute', 'sku'),
                self::attr('quantity', 'Quantity', false, 'attribute', 'stock'),
                self::attr('price', 'Price', true, 'attribute', 'priceWithCurrency'),
                self::attr('sale_price', 'Sale price', false, 'attribute', 'promotionalPriceWithCurrency'),
                self::attr('sale_price_effective_date', 'Sale price effective date'),
                self::attr('availability', 'Availability', true, 'attribute', 'availability'),
                self::attr('pickup_method', 'Pickup method'),
                self::attr('pickup_sla', 'Pickup SLA'),
            ],
        ];
    }

    private static function _meta(): array
    {
        return [
            'name' => 'Meta (Facebook & Instagram)',
            'description' => 'Meta Commerce Manager catalog feed.',
            'docsUrl' => 'https://www.facebook.com/business/help/120325381656392',
            'formats' => ['rss', 'xml', 'csv', 'tsv', 'txt', 'json'],
            'defaultFormat' => 'csv',
            'rss' => true,
            'xmlRoot' => 'rss',
            'xmlItem' => 'item',
            'namespaces' => ['g' => self::GOOGLE_NS],
            'prefix' => 'g',
            'availability' => ['in' => 'in stock', 'out' => 'out of stock', 'preorder' => 'preorder', 'backorder' => 'available for order'],
            'condition' => 'new',
            'taxonomyAttribute' => 'google_product_category',
            'attributes' => [
                self::attr('id', 'ID', true, 'attribute', 'sku'),
                self::attr('title', 'Title', true, 'attribute', 'title', false),
                self::attr('description', 'Description', true, 'attribute', 'plainDescription', false),
                self::attr('availability', 'Availability', true, 'attribute', 'availability'),
                self::attr('condition', 'Condition', true, 'attribute', 'condition'),
                self::attr('price', 'Price', true, 'attribute', 'priceWithCurrency'),
                self::attr('link', 'Link', true, 'attribute', 'url', false),
                self::attr('image_link', 'Image link', true, 'attribute', 'imageUrl'),
                self::attr('brand', 'Brand', true, 'attribute', 'brand'),
                self::attr('additional_image_link', 'Additional image links', false, 'attribute', 'additionalImageUrls'),
                self::attr('quantity_to_sell_on_facebook', 'Quantity to sell on Facebook', false, 'attribute', 'stock'),
                self::attr('sale_price', 'Sale price', false, 'attribute', 'promotionalPriceWithCurrency'),
                self::attr('sale_price_effective_date', 'Sale price effective date'),
                self::attr('item_group_id', 'Item group ID', false, 'attribute', 'itemGroupId'),
                self::attr('google_product_category', 'Google product category', false, 'taxonomy', 'productType'),
                self::attr('fb_product_category', 'Facebook product category'),
                self::attr('product_type', 'Product type', false, 'attribute', 'productTypeName'),
                self::attr('gtin', 'GTIN'),
                self::attr('mpn', 'MPN', false, 'attribute', 'sku'),
                self::attr('color', 'Color'),
                self::attr('size', 'Size'),
                self::attr('material', 'Material'),
                self::attr('pattern', 'Pattern'),
                self::attr('age_group', 'Age group'),
                self::attr('gender', 'Gender'),
                self::attr('shipping', 'Shipping'),
                self::attr('shipping_weight', 'Shipping weight', false, 'attribute', 'weightWithUnit'),
                self::attr('custom_label_0', 'Custom label 0'),
                self::attr('custom_label_1', 'Custom label 1'),
                self::attr('custom_label_2', 'Custom label 2'),
                self::attr('custom_label_3', 'Custom label 3'),
                self::attr('custom_label_4', 'Custom label 4'),
            ],
        ];
    }

    private static function _pinterest(): array
    {
        return [
            'name' => 'Pinterest',
            'description' => 'Pinterest catalogs product feed.',
            'docsUrl' => 'https://help.pinterest.com/en/business/article/data-source-ingestion',
            'formats' => ['rss', 'xml', 'csv', 'tsv', 'txt', 'json'],
            'defaultFormat' => 'rss',
            'rss' => true,
            'xmlRoot' => 'rss',
            'xmlItem' => 'item',
            'namespaces' => ['g' => self::GOOGLE_NS],
            'prefix' => 'g',
            'availability' => ['in' => 'in stock', 'out' => 'out of stock', 'preorder' => 'preorder', 'backorder' => 'out of stock'],
            'condition' => 'new',
            'taxonomyAttribute' => 'google_product_category',
            'attributes' => [
                self::attr('id', 'ID', true, 'attribute', 'sku'),
                self::attr('title', 'Title', true, 'attribute', 'title', false),
                self::attr('description', 'Description', true, 'attribute', 'plainDescription', false),
                self::attr('link', 'Link', true, 'attribute', 'url', false),
                self::attr('image_link', 'Image link', true, 'attribute', 'imageUrl'),
                self::attr('price', 'Price', true, 'attribute', 'priceWithCurrency'),
                self::attr('availability', 'Availability', true, 'attribute', 'availability'),
                self::attr('sale_price', 'Sale price', false, 'attribute', 'promotionalPriceWithCurrency'),
                self::attr('brand', 'Brand', false, 'attribute', 'brand'),
                self::attr('condition', 'Condition', false, 'attribute', 'condition'),
                self::attr('google_product_category', 'Google product category', false, 'taxonomy', 'productType'),
                self::attr('product_type', 'Product type', false, 'attribute', 'productTypeName'),
                self::attr('item_group_id', 'Item group ID', false, 'attribute', 'itemGroupId'),
                self::attr('additional_image_link', 'Additional image links', false, 'attribute', 'additionalImageUrls'),
                self::attr('gtin', 'GTIN'),
                self::attr('mpn', 'MPN', false, 'attribute', 'sku'),
                self::attr('color', 'Color'),
                self::attr('size', 'Size'),
                self::attr('material', 'Material'),
                self::attr('pattern', 'Pattern'),
                self::attr('age_group', 'Age group'),
                self::attr('gender', 'Gender'),
                self::attr('shipping', 'Shipping'),
                self::attr('tax', 'Tax'),
                self::attr('ad_link', 'Ad link'),
                self::attr('custom_label_0', 'Custom label 0'),
                self::attr('custom_label_1', 'Custom label 1'),
                self::attr('custom_label_2', 'Custom label 2'),
                self::attr('custom_label_3', 'Custom label 3'),
                self::attr('custom_label_4', 'Custom label 4'),
            ],
        ];
    }

    private static function _bing(): array
    {
        return [
            'name' => 'Microsoft (Bing) Shopping',
            'description' => 'Microsoft Merchant Center catalog feed.',
            'docsUrl' => 'https://help.ads.microsoft.com/#apex/ads/en/56895',
            'formats' => ['rss', 'xml', 'csv', 'tsv', 'txt', 'json'],
            'defaultFormat' => 'tsv',
            'rss' => true,
            'xmlRoot' => 'rss',
            'xmlItem' => 'item',
            'namespaces' => ['g' => self::GOOGLE_NS],
            'prefix' => 'g',
            'availability' => ['in' => 'in stock', 'out' => 'out of stock', 'preorder' => 'preorder', 'backorder' => 'out of stock'],
            'condition' => 'new',
            'taxonomyAttribute' => 'google_product_category',
            'attributes' => [
                self::attr('id', 'ID', true, 'attribute', 'sku'),
                self::attr('title', 'Title', true, 'attribute', 'title', false),
                self::attr('description', 'Description', true, 'attribute', 'plainDescription', false),
                self::attr('link', 'Link', true, 'attribute', 'url', false),
                self::attr('image_link', 'Image link', true, 'attribute', 'imageUrl'),
                self::attr('price', 'Price', true, 'attribute', 'priceWithCurrency'),
                self::attr('availability', 'Availability', true, 'attribute', 'availability'),
                self::attr('condition', 'Condition', false, 'attribute', 'condition'),
                self::attr('brand', 'Brand', true, 'attribute', 'brand'),
                self::attr('gtin', 'GTIN'),
                self::attr('mpn', 'MPN', false, 'attribute', 'sku'),
                self::attr('sale_price', 'Sale price', false, 'attribute', 'promotionalPriceWithCurrency'),
                self::attr('google_product_category', 'Google product category', false, 'taxonomy', 'productType'),
                self::attr('product_type', 'Product type', false, 'attribute', 'productTypeName'),
                self::attr('item_group_id', 'Item group ID', false, 'attribute', 'itemGroupId'),
                self::attr('mobile_link', 'Mobile link'),
                self::attr('shipping', 'Shipping'),
                self::attr('shipping_weight', 'Shipping weight', false, 'attribute', 'weightWithUnit'),
                self::attr('expiration_date', 'Expiration date'),
                self::attr('seller_name', 'Seller name'),
                self::attr('custom_label_0', 'Custom label 0'),
                self::attr('custom_label_1', 'Custom label 1'),
                self::attr('custom_label_2', 'Custom label 2'),
                self::attr('custom_label_3', 'Custom label 3'),
                self::attr('custom_label_4', 'Custom label 4'),
            ],
        ];
    }

    private static function _tiktok(): array
    {
        return [
            'name' => 'TikTok',
            'description' => 'TikTok Shopping catalog feed.',
            'docsUrl' => 'https://ads.tiktok.com/help/article/product-catalog-feed-specifications',
            'formats' => ['rss', 'xml', 'csv', 'tsv', 'txt', 'json'],
            'defaultFormat' => 'csv',
            'rss' => true,
            'xmlRoot' => 'rss',
            'xmlItem' => 'item',
            'namespaces' => ['g' => self::GOOGLE_NS],
            'prefix' => 'g',
            'availability' => ['in' => 'in stock', 'out' => 'out of stock', 'preorder' => 'preorder', 'backorder' => 'available for order'],
            'condition' => 'new',
            'taxonomyAttribute' => 'google_product_category',
            'attributes' => [
                self::attr('sku_id', 'SKU ID', true, 'attribute', 'sku'),
                self::attr('title', 'Title', true, 'attribute', 'title', false),
                self::attr('description', 'Description', true, 'attribute', 'plainDescription', false),
                self::attr('availability', 'Availability', true, 'attribute', 'availability'),
                self::attr('condition', 'Condition', true, 'attribute', 'condition'),
                self::attr('price', 'Price', true, 'attribute', 'priceWithCurrency'),
                self::attr('link', 'Link', true, 'attribute', 'url', false),
                self::attr('image_link', 'Image link', true, 'attribute', 'imageUrl'),
                self::attr('brand', 'Brand', true, 'attribute', 'brand'),
                self::attr('google_product_category', 'Google product category', false, 'taxonomy', 'productType'),
                self::attr('product_type', 'Product type', false, 'attribute', 'productTypeName'),
                self::attr('item_group_id', 'Item group ID', false, 'attribute', 'itemGroupId'),
                self::attr('sale_price', 'Sale price', false, 'attribute', 'promotionalPriceWithCurrency'),
                self::attr('sale_price_effective_date', 'Sale price effective date'),
                self::attr('additional_image_link', 'Additional image links', false, 'attribute', 'additionalImageUrls'),
                self::attr('video_link', 'Video link'),
                self::attr('gtin', 'GTIN'),
                self::attr('mpn', 'MPN', false, 'attribute', 'sku'),
                self::attr('color', 'Color'),
                self::attr('size', 'Size'),
                self::attr('material', 'Material'),
                self::attr('pattern', 'Pattern'),
                self::attr('age_group', 'Age group'),
                self::attr('gender', 'Gender'),
                self::attr('shipping', 'Shipping'),
                self::attr('shipping_weight', 'Shipping weight', false, 'attribute', 'weightWithUnit'),
                self::attr('tax', 'Tax'),
                self::attr('custom_label_0', 'Custom label 0'),
                self::attr('custom_label_1', 'Custom label 1'),
                self::attr('custom_label_2', 'Custom label 2'),
                self::attr('custom_label_3', 'Custom label 3'),
                self::attr('custom_label_4', 'Custom label 4'),
            ],
        ];
    }

    private static function _snapchat(): array
    {
        return [
            'name' => 'Snapchat',
            'description' => 'Snapchat Catalogs product feed.',
            'docsUrl' => 'https://businesshelp.snapchat.com/s/article/product-catalog',
            'formats' => ['csv', 'tsv', 'txt', 'rss', 'xml', 'json'],
            'defaultFormat' => 'csv',
            'rss' => true,
            'xmlRoot' => 'rss',
            'xmlItem' => 'item',
            'namespaces' => ['g' => self::GOOGLE_NS],
            'prefix' => 'g',
            'availability' => ['in' => 'in stock', 'out' => 'out of stock', 'preorder' => 'preorder', 'backorder' => 'available for order'],
            'condition' => 'new',
            'taxonomyAttribute' => 'google_product_category',
            'attributes' => [
                self::attr('id', 'ID', true, 'attribute', 'sku'),
                self::attr('title', 'Title', true, 'attribute', 'title', false),
                self::attr('description', 'Description', true, 'attribute', 'plainDescription', false),
                self::attr('availability', 'Availability', true, 'attribute', 'availability'),
                self::attr('condition', 'Condition', true, 'attribute', 'condition'),
                self::attr('price', 'Price', true, 'attribute', 'priceWithCurrency'),
                self::attr('link', 'Link', true, 'attribute', 'url', false),
                self::attr('image_link', 'Image link', true, 'attribute', 'imageUrl'),
                self::attr('brand', 'Brand', true, 'attribute', 'brand'),
                self::attr('sale_price', 'Sale price', false, 'attribute', 'promotionalPriceWithCurrency'),
                self::attr('item_group_id', 'Item group ID', false, 'attribute', 'itemGroupId'),
                self::attr('google_product_category', 'Google product category', false, 'taxonomy', 'productType'),
                self::attr('additional_image_link', 'Additional image links', false, 'attribute', 'additionalImageUrls'),
                self::attr('gtin', 'GTIN'),
                self::attr('mpn', 'MPN', false, 'attribute', 'sku'),
                self::attr('color', 'Color'),
                self::attr('size', 'Size'),
                self::attr('age_group', 'Age group'),
                self::attr('gender', 'Gender'),
                self::attr('custom_label_0', 'Custom label 0'),
                self::attr('custom_label_1', 'Custom label 1'),
                self::attr('custom_label_2', 'Custom label 2'),
                self::attr('custom_label_3', 'Custom label 3'),
                self::attr('custom_label_4', 'Custom label 4'),
            ],
        ];
    }

    private static function _criteo(): array
    {
        return [
            'name' => 'Criteo',
            'description' => 'Criteo retargeting catalogue.',
            'docsUrl' => 'https://help.criteo.com/kb/guide/en/product-feed-specifications-DTSTGBMkNK/',
            'formats' => ['xml', 'csv', 'tsv', 'txt', 'json'],
            'defaultFormat' => 'xml',
            'rss' => false,
            'xmlRoot' => 'products',
            'xmlItem' => 'product',
            'namespaces' => [],
            'prefix' => null,
            'availability' => ['in' => 'true', 'out' => 'false', 'preorder' => 'true', 'backorder' => 'false'],
            'condition' => 'new',
            'taxonomyAttribute' => null,
            'attributes' => [
                self::attr('id', 'ID', true, 'attribute', 'sku'),
                self::attr('name', 'Name', true, 'attribute', 'title'),
                self::attr('description', 'Description', false, 'attribute', 'plainDescription'),
                self::attr('producturl', 'Product URL', true, 'attribute', 'url'),
                self::attr('bigimage', 'Large image', true, 'attribute', 'imageUrl'),
                self::attr('smallimage', 'Small image'),
                self::attr('price', 'Price', true, 'attribute', 'salePrice'),
                self::attr('retailprice', 'Retail price', false, 'attribute', 'price'),
                self::attr('instock', 'In stock', true, 'attribute', 'availability'),
                self::attr('category', 'Category', false, 'attribute', 'productTypeName'),
                self::attr('brand', 'Brand', false, 'attribute', 'brand'),
                self::attr('recommendable', 'Recommendable'),
                self::attr('extra_gtin', 'GTIN'),
            ],
        ];
    }

    private static function _awin(): array
    {
        return [
            'name' => 'Awin',
            'description' => 'Awin (Affiliate Window) product datafeed.',
            'docsUrl' => 'https://wiki.awin.com/index.php/Product_Feed_Specification',
            'formats' => ['csv', 'tsv', 'txt', 'xml', 'json'],
            'defaultFormat' => 'csv',
            'rss' => false,
            'xmlRoot' => 'products',
            'xmlItem' => 'product',
            'namespaces' => [],
            'prefix' => null,
            'availability' => ['in' => '1', 'out' => '0', 'preorder' => '1', 'backorder' => '0'],
            'condition' => 'new',
            'taxonomyAttribute' => null,
            'attributes' => [
                self::attr('merchant_product_id', 'Product ID', true, 'attribute', 'sku'),
                self::attr('product_name', 'Product name', true, 'attribute', 'title'),
                self::attr('description', 'Description', true, 'attribute', 'plainDescription'),
                self::attr('merchant_category', 'Category', false, 'attribute', 'productTypeName'),
                self::attr('aw_deep_link', 'Deep link', true, 'attribute', 'url'),
                self::attr('aw_image_url', 'Image URL', true, 'attribute', 'imageUrl'),
                self::attr('search_price', 'Price', true, 'attribute', 'salePrice'),
                self::attr('rrp_price', 'RRP', false, 'attribute', 'price'),
                self::attr('brand_name', 'Brand', false, 'attribute', 'brand'),
                self::attr('in_stock', 'In stock', false, 'attribute', 'availability'),
                self::attr('stock_quantity', 'Stock quantity', false, 'attribute', 'stock'),
                self::attr('currency', 'Currency', false, 'attribute', 'currency'),
                self::attr('ean', 'EAN'),
                self::attr('mpn', 'MPN', false, 'attribute', 'sku'),
                self::attr('delivery_cost', 'Delivery cost'),
                self::attr('colour', 'Colour'),
                self::attr('size', 'Size'),
            ],
        ];
    }

    private static function _idealo(): array
    {
        return [
            'name' => 'idealo',
            'description' => 'idealo price comparison feed.',
            'docsUrl' => 'https://www.idealo.de/',
            'formats' => ['csv', 'tsv', 'txt', 'xml', 'json'],
            'defaultFormat' => 'csv',
            'rss' => false,
            'xmlRoot' => 'products',
            'xmlItem' => 'product',
            'namespaces' => [],
            'prefix' => null,
            'availability' => ['in' => 'auf Lager', 'out' => 'nicht auf Lager', 'preorder' => 'auf Lager', 'backorder' => 'nicht auf Lager'],
            'condition' => 'neu',
            'taxonomyAttribute' => null,
            'attributes' => [
                self::attr('id', 'ID', true, 'attribute', 'sku'),
                self::attr('title', 'Title', true, 'attribute', 'title'),
                self::attr('price', 'Price', true, 'attribute', 'salePrice'),
                self::attr('deeplink', 'Deeplink', true, 'attribute', 'url'),
                self::attr('image_url', 'Image URL', true, 'attribute', 'imageUrl'),
                self::attr('brand', 'Brand', false, 'attribute', 'brand'),
                self::attr('ean', 'EAN'),
                self::attr('mpn', 'MPN', false, 'attribute', 'sku'),
                self::attr('description', 'Description', false, 'attribute', 'plainDescription'),
                self::attr('category', 'Category', false, 'attribute', 'productTypeName'),
                self::attr('availability', 'Availability', false, 'attribute', 'availability'),
                self::attr('delivery_time', 'Delivery time'),
                self::attr('delivery_costs', 'Delivery costs'),
            ],
        ];
    }

    private static function _kelkoo(): array
    {
        return [
            'name' => 'Kelkoo',
            'description' => 'Kelkoo shopping comparison feed.',
            'docsUrl' => 'https://www.kelkoogroup.com/',
            'formats' => ['xml', 'csv', 'tsv', 'txt', 'json'],
            'defaultFormat' => 'xml',
            'rss' => false,
            'xmlRoot' => 'products',
            'xmlItem' => 'product',
            'namespaces' => [],
            'prefix' => null,
            'availability' => ['in' => 'in stock', 'out' => 'out of stock', 'preorder' => 'pre-order', 'backorder' => 'out of stock'],
            'condition' => 'new',
            'taxonomyAttribute' => null,
            'attributes' => [
                self::attr('offer-id', 'Offer ID', true, 'attribute', 'sku'),
                self::attr('title', 'Title', true, 'attribute', 'title'),
                self::attr('description', 'Description', true, 'attribute', 'plainDescription'),
                self::attr('price', 'Price', true, 'attribute', 'salePrice'),
                self::attr('product-url', 'Product URL', true, 'attribute', 'url'),
                self::attr('image-url', 'Image URL', true, 'attribute', 'imageUrl'),
                self::attr('category', 'Category', false, 'attribute', 'productTypeName'),
                self::attr('brand', 'Brand', false, 'attribute', 'brand'),
                self::attr('availability', 'Availability', false, 'attribute', 'availability'),
                self::attr('shipping-cost', 'Shipping cost'),
                self::attr('ean', 'EAN'),
                self::attr('mpn', 'MPN', false, 'attribute', 'sku'),
            ],
        ];
    }

    private static function _priceRunner(): array
    {
        return [
            'name' => 'PriceRunner',
            'description' => 'PriceRunner merchant feed.',
            'docsUrl' => 'https://www.pricerunner.com/',
            'formats' => ['tsv', 'csv', 'txt', 'xml', 'json'],
            'defaultFormat' => 'tsv',
            'rss' => false,
            'xmlRoot' => 'products',
            'xmlItem' => 'product',
            'namespaces' => [],
            'prefix' => null,
            'availability' => ['in' => 'In Stock', 'out' => 'Out of Stock', 'preorder' => 'Pre Order', 'backorder' => 'Out of Stock'],
            'condition' => 'New',
            'taxonomyAttribute' => null,
            'attributes' => [
                self::attr('SKU', 'SKU', true, 'attribute', 'sku'),
                self::attr('Product name', 'Product name', true, 'attribute', 'title'),
                self::attr('Category', 'Category', false, 'attribute', 'productTypeName'),
                self::attr('Description', 'Description', false, 'attribute', 'plainDescription'),
                self::attr('Price', 'Price', true, 'attribute', 'salePrice'),
                self::attr('Product URL', 'Product URL', true, 'attribute', 'url'),
                self::attr('Image URL', 'Image URL', true, 'attribute', 'imageUrl'),
                self::attr('Shipping cost', 'Shipping cost'),
                self::attr('Stock status', 'Stock status', false, 'attribute', 'availability'),
                self::attr('Manufacturer', 'Manufacturer', false, 'attribute', 'brand'),
                self::attr('EAN', 'EAN'),
                self::attr('MPN', 'MPN', false, 'attribute', 'sku'),
            ],
        ];
    }

    private static function _shopzilla(): array
    {
        return [
            'name' => 'Shopzilla / Connexity',
            'description' => 'Connexity (Shopzilla, Bizrate) merchant feed.',
            'docsUrl' => 'https://www.connexity.com/',
            'formats' => ['tsv', 'csv', 'txt', 'xml', 'json'],
            'defaultFormat' => 'tsv',
            'rss' => false,
            'xmlRoot' => 'products',
            'xmlItem' => 'product',
            'namespaces' => [],
            'prefix' => null,
            'availability' => ['in' => 'in stock', 'out' => 'out of stock', 'preorder' => 'pre-order', 'backorder' => 'out of stock'],
            'condition' => 'new',
            'taxonomyAttribute' => null,
            'attributes' => [
                self::attr('Unique ID', 'Unique ID', true, 'attribute', 'sku'),
                self::attr('Manufacturer', 'Manufacturer', false, 'attribute', 'brand'),
                self::attr('Manufacturer Part Number', 'MPN', false, 'attribute', 'sku'),
                self::attr('Product Name', 'Product name', true, 'attribute', 'title'),
                self::attr('Product URL', 'Product URL', true, 'attribute', 'url'),
                self::attr('Description', 'Description', true, 'attribute', 'plainDescription'),
                self::attr('Category', 'Category', false, 'attribute', 'productTypeName'),
                self::attr('Price', 'Price', true, 'attribute', 'salePrice'),
                self::attr('Availability', 'Availability', false, 'attribute', 'availability'),
                self::attr('Image URL', 'Image URL', true, 'attribute', 'imageUrl'),
                self::attr('Shipping Cost', 'Shipping cost'),
                self::attr('Condition', 'Condition', false, 'attribute', 'condition'),
            ],
        ];
    }

    private static function _rakuten(): array
    {
        return [
            'name' => 'Rakuten Advertising',
            'description' => 'Rakuten Advertising product catalog.',
            'docsUrl' => 'https://rakutenadvertising.com/',
            'formats' => ['tsv', 'csv', 'txt', 'xml', 'json'],
            'defaultFormat' => 'tsv',
            'rss' => false,
            'xmlRoot' => 'products',
            'xmlItem' => 'product',
            'namespaces' => [],
            'prefix' => null,
            'availability' => ['in' => 'in stock', 'out' => 'out of stock', 'preorder' => 'pre-order', 'backorder' => 'out of stock'],
            'condition' => 'new',
            'taxonomyAttribute' => null,
            'attributes' => [
                self::attr('SKU', 'SKU', true, 'attribute', 'sku'),
                self::attr('Product Name', 'Product name', true, 'attribute', 'title'),
                self::attr('Product URL', 'Product URL', true, 'attribute', 'url'),
                self::attr('Image URL', 'Image URL', true, 'attribute', 'imageUrl'),
                self::attr('Price', 'Price', true, 'attribute', 'salePrice'),
                self::attr('Retail Price', 'Retail price', false, 'attribute', 'price'),
                self::attr('Category', 'Category', false, 'attribute', 'productTypeName'),
                self::attr('Description', 'Description', true, 'attribute', 'plainDescription'),
                self::attr('Manufacturer', 'Manufacturer', false, 'attribute', 'brand'),
                self::attr('MPN', 'MPN', false, 'attribute', 'sku'),
                self::attr('Availability', 'Availability', false, 'attribute', 'availability'),
                self::attr('Currency', 'Currency', false, 'attribute', 'currency'),
            ],
        ];
    }

    private static function _custom(): array
    {
        return [
            'name' => 'Custom',
            'description' => 'Nothing pre-mapped — name every column or element yourself.',
            'docsUrl' => null,
            'formats' => ['rss', 'xml', 'csv', 'tsv', 'txt', 'json'],
            'defaultFormat' => 'csv',
            'rss' => false,
            'xmlRoot' => 'products',
            'xmlItem' => 'product',
            'namespaces' => [],
            'prefix' => null,
            'availability' => ['in' => 'in stock', 'out' => 'out of stock', 'preorder' => 'preorder', 'backorder' => 'backorder'],
            'condition' => 'new',
            'taxonomyAttribute' => null,
            'custom' => true,
            'attributes' => [
                self::attr('id', 'ID', false, 'attribute', 'sku'),
                self::attr('title', 'Title', false, 'attribute', 'title'),
                self::attr('link', 'Link', false, 'attribute', 'url'),
                self::attr('price', 'Price', false, 'attribute', 'price'),
            ],
        ];
    }
}
