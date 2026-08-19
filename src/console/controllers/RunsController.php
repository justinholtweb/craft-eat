<?php

namespace justinholtweb\eat\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\eat\Plugin;
use yii\console\ExitCode;

/**
 * The run log from the command line.
 */
class RunsController extends Controller
{
    public $defaultAction = 'index';

    /** @var int How many runs to show. */
    public int $limit = 20;

    /** @var int Delete runs older than this many days. */
    public int $days = 30;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'index' => array_merge($options, ['limit']),
            'prune' => array_merge($options, ['days']),
            default => $options,
        };
    }

    /**
     * Recent runs.
     */
    public function actionIndex(?string $handle = null): int
    {
        $plugin = Plugin::getInstance();
        $feedId = null;

        if ($handle !== null) {
            $feed = $plugin->getFeeds()->getFeedByHandle($handle);

            if ($feed === null) {
                $this->stderr("No feed with the handle “{$handle}”.\n", Console::FG_RED);
                return ExitCode::DATAERR;
            }

            $feedId = $feed->id;
        }

        $feeds = [];

        foreach ($plugin->getFeeds()->getAllFeeds() as $feed) {
            $feeds[$feed->id] = $feed->handle;
        }

        foreach ($plugin->getRuns()->getRuns($feedId, $this->limit) as $run) {
            $this->stdout(str_pad($run->dateCreated?->format('Y-m-d H:i:s') ?? '', 21));
            $this->stdout(str_pad((string)($feeds[$run->feedId] ?? $run->feedId), 24));
            $this->stdout(str_pad($run->status, 10), $run->getIsError() ? Console::FG_RED : Console::FG_GREEN);
            $this->stdout(str_pad($run->itemCount . ' products', 18));
            $this->stdout(str_pad($run->getSizeLabel(), 10));
            $this->stdout($run->trigger);
            $this->stdout("\n");

            if ($run->message) {
                $this->stdout('  ' . $run->message . "\n", Console::FG_YELLOW);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Delete old runs.
     */
    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->getRuns()->pruneOlderThan($this->days);
        $this->stdout("$deleted runs deleted.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
