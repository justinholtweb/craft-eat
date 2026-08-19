<?php

namespace justinholtweb\eat\helpers;

use Craft;

/**
 * The documented product attribute vocabulary, for the mapping screen's source picker.
 */
abstract class Attributes
{
    /**
     * @return array<string, array<string, string>> group => name => label
     */
    public static function grouped(): array
    {
        return [
            Craft::t('eat', 'Identity') => [
                'sku' => Craft::t('eat', 'Variant SKU'),
                'id' => Craft::t('eat', 'Variant ID'),
                'productId' => Craft::t('eat', 'Product ID'),
                'variantId' => Craft::t('eat', 'Variant ID'),
                'uid' => Craft::t('eat', 'Product UID'),
                'itemGroupId' => Craft::t('eat', 'Item group ID (product ID)'),
                'slug' => Craft::t('eat', 'Slug'),
            ],
            Craft::t('eat', 'Text') => [
                'title' => Craft::t('eat', 'Product title'),
                'variantTitle' => Craft::t('eat', 'Variant title'),
                'fullTitle' => Craft::t('eat', 'Product + variant title'),
                'description' => Craft::t('eat', 'Description (as written)'),
                'plainDescription' => Craft::t('eat', 'Description (plain text)'),
                'productType' => Craft::t('eat', 'Product type handle'),
                'productTypeName' => Craft::t('eat', 'Product type name'),
                'brand' => Craft::t('eat', 'Brand'),
                'condition' => Craft::t('eat', 'Condition'),
                'status' => Craft::t('eat', 'Status'),
                'siteName' => Craft::t('eat', 'Site name'),
                'storeName' => Craft::t('eat', 'Store name'),
            ],
            Craft::t('eat', 'Links & images') => [
                'url' => Craft::t('eat', 'Product URL'),
                'imageUrl' => Craft::t('eat', 'First image URL'),
                'additionalImageUrls' => Craft::t('eat', 'Additional image URLs'),
                'allImageUrls' => Craft::t('eat', 'All image URLs'),
                'imageCount' => Craft::t('eat', 'Image count'),
            ],
            Craft::t('eat', 'Money') => [
                'price' => Craft::t('eat', 'Price'),
                'basePrice' => Craft::t('eat', 'Base price (before catalog pricing)'),
                'promotionalPrice' => Craft::t('eat', 'Promotional price (empty when not on sale)'),
                'salePrice' => Craft::t('eat', 'Price to pay today'),
                'priceWithCurrency' => Craft::t('eat', 'Price with currency'),
                'promotionalPriceWithCurrency' => Craft::t('eat', 'Promotional price with currency'),
                'salePriceWithCurrency' => Craft::t('eat', 'Price to pay today, with currency'),
                'currency' => Craft::t('eat', 'Currency code'),
            ],
            Craft::t('eat', 'Stock') => [
                'availability' => Craft::t('eat', 'Availability (channel’s wording)'),
                'stock' => Craft::t('eat', 'Stock'),
                'inStock' => Craft::t('eat', 'In stock (yes/no)'),
                'minQty' => Craft::t('eat', 'Minimum quantity'),
                'maxQty' => Craft::t('eat', 'Maximum quantity'),
                'variantCount' => Craft::t('eat', 'Variant count'),
            ],
            Craft::t('eat', 'Shipping') => [
                'weight' => Craft::t('eat', 'Weight'),
                'weightWithUnit' => Craft::t('eat', 'Weight with unit'),
                'length' => Craft::t('eat', 'Length'),
                'width' => Craft::t('eat', 'Width'),
                'height' => Craft::t('eat', 'Height'),
                'dimensions' => Craft::t('eat', 'Dimensions'),
                'freeShipping' => Craft::t('eat', 'Free shipping'),
                'shippingCategory' => Craft::t('eat', 'Shipping category'),
                'taxCategory' => Craft::t('eat', 'Tax category'),
            ],
            Craft::t('eat', 'Dates') => [
                'dateCreated' => Craft::t('eat', 'Date created'),
                'dateUpdated' => Craft::t('eat', 'Date updated'),
                'postDate' => Craft::t('eat', 'Post date'),
                'expiryDate' => Craft::t('eat', 'Expiry date'),
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>> option list for a select field
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::grouped() as $group => $attributes) {
            $options[] = ['optgroup' => $group];

            foreach ($attributes as $name => $label) {
                $options[] = ['value' => $name, 'label' => $label];
            }
        }

        return $options;
    }

    /**
     * @return string[]
     */
    public static function names(): array
    {
        $names = [];

        foreach (self::grouped() as $attributes) {
            foreach (array_keys($attributes) as $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
