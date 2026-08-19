<?php

namespace justinholtweb\eat\models;

use craft\base\Model;

/**
 * A channel template — one merchant's idea of what a product feed looks like.
 */
class Channel extends Model
{
    public string $id = '';
    public string $name = '';
    public ?string $description = null;
    public ?string $docsUrl = null;

    /** @var string[] */
    public array $formats = ['csv'];

    public string $defaultFormat = 'csv';

    /** Whether the XML shape is RSS 2.0 (`rss > channel > item`) rather than a bare document. */
    public bool $rss = false;

    public string $xmlRoot = 'products';
    public string $xmlItem = 'product';

    /** @var array<string, string> prefix => URI */
    public array $namespaces = [];

    /** The namespace prefix namespaced attributes are written with. */
    public ?string $prefix = null;

    /** @var array<string, string> in|out|preorder|backorder => the merchant's word for it */
    public array $availability = [];

    public string $condition = 'new';

    /** The attribute key that carries the channel's taxonomy value, if it has one. */
    public ?string $taxonomyAttribute = null;

    /** Whether the merchant defines the attributes rather than the channel. */
    public bool $custom = false;

    /** @var array<int, array<string, mixed>> */
    public array $attributes = [];

    public function getAttributeDefs(): array
    {
        return $this->attributes;
    }

    public function getAttributeDef(string $key): ?array
    {
        foreach ($this->attributes as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function getRequiredKeys(): array
    {
        return array_values(array_map(
            static fn(array $definition) => $definition['key'],
            array_filter($this->attributes, static fn(array $definition) => !empty($definition['required']))
        ));
    }

    public function supportsFormat(string $format): bool
    {
        return in_array($format, $this->formats, true);
    }

    /**
     * The word this channel uses for a stock state.
     */
    public function availabilityWord(string $state): string
    {
        return $this->availability[$state] ?? $state;
    }

    /**
     * The mapping list a brand new feed on this channel starts with: every attribute the channel
     * knows about, pre-pointed at the Commerce data it almost certainly means.
     *
     * @return Mapping[]
     */
    public function defaultMappings(): array
    {
        $mappings = [];

        foreach ($this->attributes as $definition) {
            $mappings[] = new Mapping([
                'attribute' => $definition['key'],
                'source' => $definition['source'] ?? 'none',
                'value' => $definition['value'] ?? null,
                'enabled' => ($definition['source'] ?? 'none') !== 'none',
            ]);
        }

        return $mappings;
    }

    /**
     * Whether an attribute is written inside the channel's XML namespace. RSS's own three
     * elements (title, link, description) never are — Google rejects `<g:title>`.
     */
    public function isNamespaced(string $key): bool
    {
        if ($this->prefix === null) {
            return false;
        }

        $definition = $this->getAttributeDef($key);

        return $definition === null ? true : (bool)($definition['ns'] ?? true);
    }
}
