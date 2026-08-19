<?php

namespace justinholtweb\eat\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\eat\db\Table;

/**
 * Install migration.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::FEEDS)) {
            $this->createTable(Table::FEEDS, [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer(),
                'storeId' => $this->integer(),
                'name' => $this->string()->notNull(),
                'handle' => $this->string()->notNull(),
                'channel' => $this->string(64)->notNull(),
                'format' => $this->string(16)->notNull()->defaultValue('rss'),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'variantMode' => $this->string(16)->notNull()->defaultValue('variant'),
                'filters' => $this->text(),
                'productCondition' => $this->text(),
                'mappings' => $this->mediumText(),
                'options' => $this->text(),
                'delivery' => $this->text(),
                'interval' => $this->integer()->notNull()->defaultValue(0),
                'regenerateOnSave' => $this->boolean()->notNull()->defaultValue(false),
                'nextGenerateAt' => $this->dateTime(),
                'lastGeneratedAt' => $this->dateTime(),
                'sortOrder' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, Table::FEEDS, ['handle'], true);
            $this->createIndex(null, Table::FEEDS, ['enabled', 'nextGenerateAt'], false);

            $this->addForeignKey(null, Table::FEEDS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);
        }

        if (!$this->db->tableExists(Table::RUNS)) {
            $this->createTable(Table::RUNS, [
                'id' => $this->primaryKey(),
                'feedId' => $this->integer()->notNull(),
                'status' => $this->string(16)->notNull()->defaultValue('success'),
                'trigger' => $this->string(16)->notNull()->defaultValue('manual'),
                'itemCount' => $this->integer()->notNull()->defaultValue(0),
                'skippedCount' => $this->integer()->notNull()->defaultValue(0),
                'byteSize' => $this->bigInteger()->notNull()->defaultValue(0),
                'durationMs' => $this->integer()->notNull()->defaultValue(0),
                'url' => $this->text(),
                'message' => $this->text(),
                'details' => $this->mediumText(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, Table::RUNS, ['feedId', 'dateCreated'], false);
            $this->addForeignKey(null, Table::RUNS, ['feedId'], Table::FEEDS, ['id'], 'CASCADE', null);
        }

        if (!$this->db->tableExists(Table::TAXONOMY)) {
            $this->createTable(Table::TAXONOMY, [
                'id' => $this->primaryKey(),
                'channel' => $this->string(64)->notNull(),
                'sourceType' => $this->string(32)->notNull()->defaultValue('productType'),
                'sourceKey' => $this->string()->notNull(),
                'targetValue' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, Table::TAXONOMY, ['channel', 'sourceType', 'sourceKey'], true);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::RUNS);
        $this->dropTableIfExists(Table::TAXONOMY);
        $this->dropTableIfExists(Table::FEEDS);

        return true;
    }
}
