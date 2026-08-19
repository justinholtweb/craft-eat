<?php

namespace justinholtweb\eat\records;

use craft\db\ActiveRecord;
use justinholtweb\eat\db\Table;

/**
 * @property int $id
 * @property string $channel
 * @property string $sourceType
 * @property string $sourceKey
 * @property string|null $targetValue
 */
class TaxonomyRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::TAXONOMY;
    }
}
