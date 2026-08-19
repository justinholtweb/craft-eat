<?php

namespace justinholtweb\eat\models;

use craft\base\Model;

/**
 * One line of the attribute map: where a channel attribute's value comes from, and what happens to
 * it on the way out.
 */
class Mapping extends Model
{
    public const SOURCE_NONE = 'none';
    public const SOURCE_ATTRIBUTE = 'attribute';
    public const SOURCE_FIELD = 'field';
    public const SOURCE_STATIC = 'static';
    public const SOURCE_TAXONOMY = 'taxonomy';
    public const SOURCE_TEMPLATE = 'template';

    /** The channel attribute this fills, e.g. `image_link`. */
    public string $attribute = '';

    public string $source = self::SOURCE_NONE;

    /** What the source needs: an attribute name, a field handle, a literal, a Twig template. */
    public ?string $value = null;

    public bool $enabled = true;

    /**
     * @var array<int, array<string, mixed>> Output modifiers, applied in order.
     */
    public array $modifiers = [];

    public static function sources(): array
    {
        return [
            self::SOURCE_ATTRIBUTE => 'Product attribute',
            self::SOURCE_FIELD => 'Custom field',
            self::SOURCE_STATIC => 'Static value',
            self::SOURCE_TAXONOMY => 'Taxonomy map',
            self::SOURCE_TEMPLATE => 'Twig template',
            self::SOURCE_NONE => 'Leave out',
        ];
    }

    public function isActive(): bool
    {
        return $this->enabled && $this->source !== self::SOURCE_NONE;
    }

    public function toConfig(): array
    {
        return [
            'attribute' => $this->attribute,
            'source' => $this->source,
            'value' => $this->value,
            'enabled' => $this->enabled,
            'modifiers' => array_values($this->modifiers),
        ];
    }
}
