<?php

namespace justinholtweb\eat\records;

use craft\db\ActiveRecord;
use justinholtweb\eat\db\Table;

/**
 * @property int $id
 * @property int $feedId
 * @property string $status
 * @property string $trigger
 * @property int $itemCount
 * @property int $skippedCount
 * @property int $byteSize
 * @property int $durationMs
 * @property string|null $url
 * @property string|null $message
 * @property string|null $details
 */
class RunRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::RUNS;
    }
}
