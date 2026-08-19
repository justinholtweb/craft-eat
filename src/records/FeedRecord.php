<?php

namespace justinholtweb\eat\records;

use craft\db\ActiveRecord;
use justinholtweb\eat\db\Table;

/**
 * @property int $id
 * @property int|null $siteId
 * @property int|null $storeId
 * @property string $name
 * @property string $handle
 * @property string $channel
 * @property string $format
 * @property bool $enabled
 * @property string $variantMode
 * @property string|null $filters
 * @property string|null $productCondition
 * @property string|null $mappings
 * @property string|null $options
 * @property string|null $delivery
 * @property int $interval
 * @property bool $regenerateOnSave
 * @property string|null $nextGenerateAt
 * @property string|null $lastGeneratedAt
 * @property int|null $sortOrder
 */
class FeedRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::FEEDS;
    }
}
