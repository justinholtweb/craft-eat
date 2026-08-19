<?php

namespace justinholtweb\eat\services;

use Craft;
use craft\base\Component;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\eat\db\Table;
use justinholtweb\eat\records\TaxonomyRecord;

/**
 * Store category → channel taxonomy.
 *
 * The map is per channel, not per feed: Google's taxonomy does not change because a merchant made
 * a second Google feed, and duplicating it per feed is how the two drift apart.
 */
class Taxonomy extends Component
{
    /** @var array<string, array<string, string>>|null channel => "sourceType:sourceKey" => value */
    private ?array $_map = null;

    public function lookup(string $channel, string $sourceType, string $sourceKey): ?string
    {
        $map = $this->_all();
        $value = $map[$channel][$sourceType . ':' . $sourceKey] ?? null;

        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, string> "sourceType:sourceKey" => value
     */
    public function getMap(string $channel): array
    {
        return $this->_all()[$channel] ?? [];
    }

    public function save(string $channel, string $sourceType, string $sourceKey, ?string $value): bool
    {
        $record = TaxonomyRecord::findOne([
            'channel' => $channel,
            'sourceType' => $sourceType,
            'sourceKey' => $sourceKey,
        ]);

        if ($value === null || trim($value) === '') {
            if ($record !== null) {
                $record->delete();
            }

            $this->clearCaches();

            return true;
        }

        if ($record === null) {
            $record = new TaxonomyRecord();
            $record->channel = $channel;
            $record->sourceType = $sourceType;
            $record->sourceKey = $sourceKey;
            $record->uid = StringHelper::UUID();
        }

        $record->targetValue = trim($value);
        $record->save(false);

        $this->clearCaches();

        return true;
    }

    /**
     * @param array<string, string> $values sourceKey => taxonomy value
     */
    public function saveMany(string $channel, string $sourceType, array $values): bool
    {
        foreach ($values as $key => $value) {
            $this->save($channel, $sourceType, (string)$key, $value === null ? null : (string)$value);
        }

        return true;
    }

    /**
     * The store's product types, which is what almost every merchant maps from.
     *
     * @return array<string, string> handle => name
     */
    public function productTypes(): array
    {
        $types = [];

        try {
            foreach (Commerce::getInstance()->getProductTypes()->getAllProductTypes() as $type) {
                $types[$type->handle] = $type->name;
            }
        } catch (\Throwable) {
            return [];
        }

        return $types;
    }

    public function clearCaches(): void
    {
        $this->_map = null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function _all(): array
    {
        if ($this->_map !== null) {
            return $this->_map;
        }

        $this->_map = [];

        $rows = (new Query())
            ->select(['channel', 'sourceType', 'sourceKey', 'targetValue'])
            ->from(Table::TAXONOMY)
            ->all();

        foreach ($rows as $row) {
            $this->_map[$row['channel']][$row['sourceType'] . ':' . $row['sourceKey']] = (string)$row['targetValue'];
        }

        return $this->_map;
    }
}
