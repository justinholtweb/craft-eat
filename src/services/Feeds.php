<?php

namespace justinholtweb\eat\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\eat\db\Table;
use justinholtweb\eat\jobs\GenerateFeed;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\models\Run;
use justinholtweb\eat\Plugin;
use justinholtweb\eat\records\FeedRecord;
use Throwable;
use yii\base\Exception;

/**
 * The feed library: everything that reads, writes, schedules or runs a feed.
 */
class Feeds extends Component
{
    /** How many feeds Lite may have. */
    public const LITE_FEED_LIMIT = 2;

    /** The channels Lite may use. */
    public const LITE_CHANNELS = ['google', 'meta'];

    /** @var Feed[]|null */
    private ?array $_feeds = null;

    /**
     * @return Feed[]
     */
    public function getAllFeeds(): array
    {
        if ($this->_feeds === null) {
            $this->_feeds = array_map(
                fn(array $row) => new Feed($row),
                $this->_createQuery()->all()
            );
        }

        return $this->_feeds;
    }

    /**
     * @return Feed[]
     */
    public function getEnabledFeeds(): array
    {
        return array_values(array_filter($this->getAllFeeds(), static fn(Feed $feed) => $feed->enabled));
    }

    public function getFeedById(int $id): ?Feed
    {
        foreach ($this->getAllFeeds() as $feed) {
            if ($feed->id === $id) {
                return $feed;
            }
        }

        return null;
    }

    public function getFeedByHandle(string $handle): ?Feed
    {
        foreach ($this->getAllFeeds() as $feed) {
            if ($feed->handle === $handle) {
                return $feed;
            }
        }

        return null;
    }

    /**
     * @return Feed[]
     */
    public function getDueFeeds(): array
    {
        return array_values(array_filter($this->getEnabledFeeds(), static fn(Feed $feed) => $feed->getIsDue()));
    }

    // Editions
    // -------------------------------------------------------------------------

    public function canAddFeed(): bool
    {
        if (Plugin::getInstance()->isPro()) {
            return true;
        }

        return count($this->getAllFeeds()) < self::LITE_FEED_LIMIT;
    }

    public function channelIsAllowed(string $channel): bool
    {
        return Plugin::getInstance()->isPro() || in_array($channel, self::LITE_CHANNELS, true);
    }

    // Writing
    // -------------------------------------------------------------------------

    public function saveFeed(Feed $feed, bool $runValidation = true): bool
    {
        $isNew = !$feed->id;

        if ($isNew) {
            $feed->uid = StringHelper::UUID();

            if ($feed->sortOrder === null) {
                $max = (new Query())->from(Table::FEEDS)->max('[[sortOrder]]');
                $feed->sortOrder = (int)$max + 1;
            }
        }

        if ($runValidation && !$feed->validate()) {
            Craft::info('Feed not saved due to validation errors.', __METHOD__);
            return false;
        }

        if ($isNew && !$this->canAddFeed()) {
            $feed->addError('name', Craft::t('eat', 'Eat Lite is limited to {n} feeds. Upgrade to Pro for as many as you like.', [
                'n' => self::LITE_FEED_LIMIT,
            ]));

            return false;
        }

        if (!$this->channelIsAllowed($feed->channel)) {
            $feed->addError('channel', Craft::t('eat', 'That channel needs Eat Pro.'));
            return false;
        }

        $record = $isNew ? new FeedRecord() : FeedRecord::findOne($feed->id);

        if ($record === null) {
            throw new Exception("No feed exists with the ID “{$feed->id}”");
        }

        $record->siteId = $feed->siteId;
        $record->storeId = $feed->storeId;
        $record->name = $feed->name;
        $record->handle = $feed->handle;
        $record->channel = $feed->channel;
        $record->format = $feed->format;
        $record->enabled = $feed->enabled;
        $record->variantMode = $feed->variantMode;
        $record->filters = Json::encode($feed->getFilters());
        $record->productCondition = Json::encode($feed->getProductCondition()->getConfig());
        $record->mappings = Json::encode(array_map(static fn($mapping) => $mapping->toConfig(), $feed->getMappings()));
        $record->options = Json::encode($feed->getOptions());
        $record->delivery = Json::encode($feed->getDelivery());
        $record->interval = $feed->interval;
        $record->regenerateOnSave = $feed->regenerateOnSave;
        $record->nextGenerateAt = $feed->nextGenerateAt ? Db::prepareDateForDb($feed->nextGenerateAt) : null;
        $record->lastGeneratedAt = $feed->lastGeneratedAt ? Db::prepareDateForDb($feed->lastGeneratedAt) : null;
        $record->sortOrder = $feed->sortOrder;

        if ($isNew) {
            $record->uid = $feed->uid;
        }

        $record->save(false);

        $feed->id = $record->id;
        $feed->uid = $record->uid;

        $this->clearCaches();

        return true;
    }

    public function deleteFeedById(int $id): bool
    {
        $feed = $this->getFeedById($id);
        $record = FeedRecord::findOne($id);

        if ($record === null) {
            return true;
        }

        $deleted = (bool)$record->delete();

        // The generated file is the merchant's, not ours to keep: a deleted feed that keeps serving
        // yesterday's products from its URL is worse than a 404.
        if ($feed !== null) {
            FileHelper::unlink($feed->getFilePath());
        }

        $this->clearCaches();

        return $deleted;
    }

    /**
     * @param int[] $ids
     */
    public function reorderFeeds(array $ids): bool
    {
        foreach ($ids as $index => $id) {
            Craft::$app->getDb()->createCommand()
                ->update(Table::FEEDS, ['sortOrder' => $index + 1], ['id' => (int)$id])
                ->execute();
        }

        $this->clearCaches();

        return true;
    }

    public function clearCaches(): void
    {
        $this->_feeds = null;
    }

    // Running
    // -------------------------------------------------------------------------

    /**
     * Generate, deliver and record one feed. Never throws: a failed feed is a run row with an
     * error on it, because the console command, the queue job and the CP button all want the same
     * answer.
     */
    public function run(Feed $feed, string $trigger = 'manual'): Run
    {
        $started = microtime(true);
        $plugin = Plugin::getInstance();
        $run = new Run(['feedId' => $feed->id, 'trigger' => $trigger]);
        $temp = null;

        try {
            $result = $plugin->getGenerator()->write($feed);
            $temp = $result['path'];

            $delivery = $plugin->getDelivery()->deliver($feed, $temp);

            $run->itemCount = $result['itemCount'];
            $run->skippedCount = $result['skipped'];
            $run->byteSize = $result['bytes'];
            $run->url = $delivery['url'];
            $run->status = $delivery['ok'] ? Run::STATUS_SUCCESS : Run::STATUS_PARTIAL;
            $run->setDetails([
                'skippedReasons' => $result['reasons'],
                'delivery' => $delivery['results'],
                'channel' => $feed->channel,
                'format' => $feed->format,
            ]);

            if (!$delivery['ok']) {
                foreach ($delivery['results'] as $one) {
                    if (!($one['ok'] ?? false)) {
                        $run->message = ($one['mode'] ?? 'delivery') . ': ' . ($one['message'] ?? 'failed');
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $run->status = Run::STATUS_ERROR;
            $run->message = $e->getMessage();
            $run->setDetails(['exception' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            Craft::error("Eat could not generate feed “{$feed->handle}”: " . $e->getMessage(), 'eat');
        } finally {
            if ($temp !== null) {
                FileHelper::unlink($temp);
            }
        }

        $run->durationMs = (int)round((microtime(true) - $started) * 1000);

        $this->markGenerated($feed);

        return $plugin->getRuns()->record($run);
    }

    /**
     * Move the schedule on. Called whether the run worked or not — a feed that fails every time
     * must not turn into a queue job every second.
     */
    public function markGenerated(Feed $feed): void
    {
        $now = new DateTime();
        $next = $feed->interval > 0 ? (clone $now)->modify("+{$feed->interval} seconds") : null;

        Craft::$app->getDb()->createCommand()
            ->update(Table::FEEDS, [
                'lastGeneratedAt' => Db::prepareDateForDb($now),
                'nextGenerateAt' => $next ? Db::prepareDateForDb($next) : null,
            ], ['id' => $feed->id])
            ->execute();

        $feed->lastGeneratedAt = $now;
        $feed->nextGenerateAt = $next;

        $this->clearCaches();
    }

    /**
     * Mark a feed for regeneration as soon as the queue gets to it.
     */
    public function markDue(Feed $feed): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(Table::FEEDS, ['nextGenerateAt' => Db::prepareDateForDb(new DateTime())], ['id' => $feed->id])
            ->execute();

        $this->clearCaches();
    }

    /**
     * Push a queue job per due feed. The interval is advanced by the job, not here, so a queue that
     * is backed up does not queue the same feed twice.
     */
    public function queueDue(string $trigger = 'schedule'): int
    {
        $queued = 0;

        foreach ($this->getDueFeeds() as $feed) {
            if ($this->queue($feed, $trigger)) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Queue one feed, unless it is already queued.
     */
    public function queue(Feed $feed, string $trigger = 'manual'): bool
    {
        $cache = Craft::$app->getCache();
        $key = 'eat:queued:' . $feed->id;

        if ($cache->get($key)) {
            return false;
        }

        // Long enough that a bulk product resave queues one job, short enough that the next
        // scheduled run is never blocked by the flag.
        $cache->set($key, true, max(60, min(600, $feed->interval ?: 300)));

        Craft::$app->getQueue()->push(new GenerateFeed([
            'feedId' => $feed->id,
            'feedName' => (string)$feed->name,
            'trigger' => $trigger,
        ]));

        return true;
    }

    // Portability
    // -------------------------------------------------------------------------

    public function toConfig(Feed $feed): array
    {
        return [
            'name' => $feed->name,
            'handle' => $feed->handle,
            'channel' => $feed->channel,
            'format' => $feed->format,
            'enabled' => $feed->enabled,
            'siteId' => $feed->siteId,
            'storeId' => $feed->storeId,
            'variantMode' => $feed->variantMode,
            'interval' => $feed->interval,
            'regenerateOnSave' => $feed->regenerateOnSave,
            'sortOrder' => $feed->sortOrder,
            'filters' => $feed->getFilters(),
            'productCondition' => $feed->getProductCondition()->getConfig(),
            'mappings' => array_map(static fn($mapping) => $mapping->toConfig(), $feed->getMappings()),
            'options' => $feed->getOptions(),
            'delivery' => $feed->getDelivery(),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function fromConfig(array $config, ?Feed $feed = null): Feed
    {
        $feed ??= new Feed();

        foreach (['name', 'handle', 'channel', 'format', 'variantMode'] as $key) {
            if (isset($config[$key])) {
                $feed->$key = (string)$config[$key];
            }
        }

        foreach (['siteId', 'storeId', 'interval', 'sortOrder'] as $key) {
            if (isset($config[$key])) {
                $feed->$key = $config[$key] === null ? null : (int)$config[$key];
            }
        }

        foreach (['enabled', 'regenerateOnSave'] as $key) {
            if (isset($config[$key])) {
                $feed->$key = (bool)$config[$key];
            }
        }

        $feed->setFilters($config['filters'] ?? []);
        $feed->setOptions($config['options'] ?? []);
        $feed->setDelivery($config['delivery'] ?? []);
        $feed->setMappings($config['mappings'] ?? []);
        $feed->setProductCondition($config['productCondition'] ?? []);

        return $feed;
    }

    private function _createQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'siteId',
                'storeId',
                'name',
                'handle',
                'channel',
                'format',
                'enabled',
                'variantMode',
                'filters',
                'productCondition',
                'mappings',
                'options',
                'delivery',
                'interval',
                'regenerateOnSave',
                'nextGenerateAt',
                'lastGeneratedAt',
                'sortOrder',
                'dateCreated',
                'dateUpdated',
                'uid',
            ])
            ->from(Table::FEEDS)
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC]);
    }
}
