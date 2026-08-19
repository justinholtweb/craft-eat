<?php

namespace justinholtweb\eat\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\eat\db\Table;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\models\Run;
use justinholtweb\eat\Plugin;
use justinholtweb\eat\records\RunRecord;

/**
 * The run log: what happened, every time a feed was generated.
 */
class Runs extends Component
{
    public function record(Run $run): Run
    {
        $record = new RunRecord();
        $record->feedId = (int)$run->feedId;
        $record->status = $run->status;
        $record->trigger = $run->trigger;
        $record->itemCount = $run->itemCount;
        $record->skippedCount = $run->skippedCount;
        $record->byteSize = $run->byteSize;
        $record->durationMs = $run->durationMs;
        $record->url = $run->url;
        $record->message = $run->message !== null ? StringHelper::safeTruncate($run->message, 2000) : null;
        $record->details = Json::encode($run->getDetails());
        $record->uid = StringHelper::UUID();
        $record->save(false);

        $run->id = $record->id;
        $run->uid = $record->uid;
        $run->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;

        $this->prune($record->feedId);

        return $run;
    }

    /**
     * @return Run[]
     */
    public function getRuns(?int $feedId = null, int $limit = 100, int $offset = 0): array
    {
        $query = $this->_createQuery()->limit($limit)->offset($offset);

        if ($feedId !== null) {
            $query->where(['feedId' => $feedId]);
        }

        return array_map(fn(array $row) => new Run($row), $query->all());
    }

    public function getTotalRuns(?int $feedId = null): int
    {
        $query = (new Query())->from(Table::RUNS);

        if ($feedId !== null) {
            $query->where(['feedId' => $feedId]);
        }

        return (int)$query->count('[[id]]');
    }

    public function getRunById(int $id): ?Run
    {
        $row = $this->_createQuery()->where(['id' => $id])->one();

        return $row ? new Run($row) : null;
    }

    public function getLastRun(int $feedId): ?Run
    {
        $row = $this->_createQuery()->where(['feedId' => $feedId])->one();

        return $row ? new Run($row) : null;
    }

    /**
     * @return array<int, Run> feed ID => its most recent run
     */
    public function getLastRuns(): array
    {
        $runs = [];

        foreach ($this->_createQuery()->limit(null)->all() as $row) {
            if (!isset($runs[$row['feedId']])) {
                $runs[$row['feedId']] = new Run($row);
            }
        }

        return $runs;
    }

    /**
     * Keep the most recent N runs for a feed, per the setting. 0 keeps everything.
     */
    public function prune(int $feedId): int
    {
        $keep = Plugin::getInstance()->getSettings()->runsToKeep;

        if ($keep <= 0) {
            return 0;
        }

        $ids = (new Query())
            ->select(['id'])
            ->from(Table::RUNS)
            ->where(['feedId' => $feedId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->offset($keep)
            ->limit(1000)
            ->column();

        if (!$ids) {
            return 0;
        }

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::RUNS, ['id' => $ids])
            ->execute();
    }

    public function pruneOlderThan(int $days): int
    {
        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::RUNS, ['<', 'dateCreated', Db::prepareDateForDb(new \DateTime("-$days days"))])
            ->execute();
    }

    public function deleteRunsForFeed(int $feedId): int
    {
        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::RUNS, ['feedId' => $feedId])
            ->execute();
    }

    private function _createQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'feedId',
                'status',
                'trigger',
                'itemCount',
                'skippedCount',
                'byteSize',
                'durationMs',
                'url',
                'message',
                'details',
                'dateCreated',
                'uid',
            ])
            ->from(Table::RUNS)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);
    }
}
