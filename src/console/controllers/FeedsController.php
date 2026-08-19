<?php

namespace justinholtweb\eat\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\eat\Plugin;
use yii\console\ExitCode;

/**
 * Product feeds from the command line — which is where a real schedule lives.
 *
 *     php craft eat/feeds
 *     php craft eat/feeds/generate my-google-feed
 *     php craft eat/feeds/generate --due
 */
class FeedsController extends Controller
{
    public $defaultAction = 'index';

    /** @var bool Generate every feed. */
    public bool $all = false;

    /** @var bool Generate only the feeds whose schedule is up. */
    public bool $due = false;

    /** @var bool Queue the work instead of doing it here. */
    public bool $queue = false;

    /** @var int How many rows to show in a preview. */
    public int $limit = 5;

    /** @var string|null Where an export is written, or an import read from. */
    public ?string $file = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'generate' => array_merge($options, ['all', 'due', 'queue']),
            'preview' => array_merge($options, ['limit']),
            'export', 'import' => array_merge($options, ['file']),
            default => $options,
        };
    }

    /**
     * List the feeds.
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $feeds = $plugin->getFeeds()->getAllFeeds();

        if (!$feeds) {
            $this->stdout("No feeds.\n");
            return ExitCode::OK;
        }

        $runs = $plugin->getRuns()->getLastRuns();

        foreach ($feeds as $feed) {
            $run = $runs[$feed->id] ?? null;

            $this->stdout(str_pad((string)$feed->handle, 24), Console::FG_CYAN);
            $this->stdout(str_pad($feed->channel . '/' . $feed->format, 18));
            $this->stdout(str_pad($feed->enabled ? 'enabled' : 'disabled', 10));
            $this->stdout(str_pad($feed->getIntervalLabel(), 14));
            $this->stdout($run ? $run->itemCount . ' products, ' . $run->status : 'never run');
            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    /**
     * Generate one feed, every feed, or everything that is due.
     */
    public function actionGenerate(?string $handle = null): int
    {
        $plugin = Plugin::getInstance();
        $feeds = $plugin->getFeeds();

        if ($this->due) {
            $targets = $feeds->getDueFeeds();
        } elseif ($this->all || $handle === null) {
            $targets = $feeds->getEnabledFeeds();
        } else {
            $feed = $feeds->getFeedByHandle($handle);

            if ($feed === null) {
                $this->stderr("No feed with the handle “{$handle}”.\n", Console::FG_RED);
                return ExitCode::DATAERR;
            }

            $targets = [$feed];
        }

        if (!$targets) {
            $this->stdout("Nothing to generate.\n");
            return ExitCode::OK;
        }

        $failed = 0;

        foreach ($targets as $feed) {
            if ($this->queue) {
                $feeds->queue($feed, 'console');
                $this->stdout("Queued {$feed->handle}\n");
                continue;
            }

            $this->stdout(str_pad((string)$feed->handle, 24));
            $run = $feeds->run($feed, 'console');

            if ($run->getIsError()) {
                $failed++;
                $this->stdout("failed: {$run->message}\n", Console::FG_RED);
                continue;
            }

            $colour = $run->status === 'success' ? Console::FG_GREEN : Console::FG_YELLOW;
            $this->stdout("{$run->itemCount} products", $colour);
            $this->stdout(", {$run->skippedCount} skipped, {$run->getSizeLabel()}, {$run->durationMs}ms\n");

            if ($run->message) {
                $this->stdout("  {$run->message}\n", Console::FG_YELLOW);
            }
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Show the first few rows of a feed without writing or delivering anything.
     */
    public function actionPreview(string $handle): int
    {
        $plugin = Plugin::getInstance();
        $feed = $plugin->getFeeds()->getFeedByHandle($handle);

        if ($feed === null) {
            $this->stderr("No feed with the handle “{$handle}”.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $stats = [];
        $rows = $plugin->getGenerator()->preview($feed, $this->limit, $stats);

        foreach ($rows as $index => $row) {
            $this->stdout("--- row " . ($index + 1) . " ---\n", Console::FG_CYAN);

            foreach ($row as $key => $value) {
                $this->stdout(str_pad((string)$key, 30) . (is_array($value) ? implode(', ', $value) : $value) . "\n");
            }
        }

        $this->stdout("\n" . count($rows) . " rows, " . ($stats['skipped'] ?? 0) . " skipped\n");

        foreach ((array)($stats['reasons'] ?? []) as $reason => $count) {
            $this->stdout("  $reason: $count\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Write every feed definition to JSON, so feeds move between environments.
     */
    public function actionExport(): int
    {
        $plugin = Plugin::getInstance();
        $config = [];

        foreach ($plugin->getFeeds()->getAllFeeds() as $feed) {
            $config[] = $plugin->getFeeds()->toConfig($feed);
        }

        $json = Json::encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($this->file) {
            file_put_contents($this->file, $json);
            $this->stdout(count($config) . " feeds written to {$this->file}\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout($json . "\n");

        return ExitCode::OK;
    }

    /**
     * Read feed definitions back in. Matching handles are updated, not duplicated.
     */
    public function actionImport(?string $file = null): int
    {
        $file ??= $this->file;

        if ($file === null || !is_file($file)) {
            $this->stderr("Pass a readable JSON file.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $config = Json::decodeIfJson((string)file_get_contents($file));

        if (!is_array($config)) {
            $this->stderr("That file is not a feed export.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $plugin = Plugin::getInstance();
        $saved = 0;

        foreach ($config as $one) {
            if (!is_array($one) || empty($one['handle'])) {
                continue;
            }

            $existing = $plugin->getFeeds()->getFeedByHandle((string)$one['handle']);
            $feed = $plugin->getFeeds()->fromConfig($one, $existing);

            if ($plugin->getFeeds()->saveFeed($feed)) {
                $saved++;
                $this->stdout("Saved {$feed->handle}\n", Console::FG_GREEN);
                continue;
            }

            $this->stderr("Could not save {$one['handle']}: " . Json::encode($feed->getErrors()) . "\n", Console::FG_RED);
        }

        $this->stdout("$saved feeds imported.\n");

        return ExitCode::OK;
    }
}
