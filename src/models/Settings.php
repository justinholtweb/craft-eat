<?php

namespace justinholtweb\eat\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\UrlHelper;

/**
 * Eat settings.
 */
class Settings extends Model
{
    /**
     * @var string Directory the generated feed files are written to, relative to the web root.
     */
    public string $feedDirectory = 'feeds';

    /**
     * @var string What goes in `brand` when nothing else does. Env vars are parsed.
     */
    public string $defaultBrand = '';

    /**
     * @var bool Whether due feeds may be queued from a web request, for sites without cron.
     */
    public bool $scheduleOnRequest = true;

    /**
     * @var int How many runs to keep per feed. 0 keeps them all.
     */
    public int $runsToKeep = 50;

    /**
     * @var bool Whether generation runs in the queue rather than in the request that asked for it.
     */
    public bool $generateInQueue = true;

    /**
     * @var int Rows read from the database at a time.
     */
    public int $batchSize = 100;

    /**
     * @var string Named image transform applied to feed images, if any.
     */
    public string $imageTransform = '';

    /**
     * @var bool Whether the live feed route (`/eat/feed/<handle>`) is reachable at all.
     */
    public bool $enableLiveRoutes = true;

    /**
     * @var int Seconds a live feed response is cached for when the feed has no interval.
     */
    public int $liveCacheDuration = 3600;

    protected function defineRules(): array
    {
        return [
            [['feedDirectory', 'defaultBrand', 'imageTransform'], 'string'],
            [['scheduleOnRequest', 'generateInQueue', 'enableLiveRoutes'], 'boolean'],
            [['runsToKeep', 'batchSize', 'liveCacheDuration'], 'integer', 'min' => 0],
            [['batchSize'], 'integer', 'min' => 1],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'feedDirectory' => Craft::t('eat', 'Feed directory'),
            'defaultBrand' => Craft::t('eat', 'Default brand'),
            'scheduleOnRequest' => Craft::t('eat', 'Schedule from web requests'),
            'runsToKeep' => Craft::t('eat', 'Runs to keep'),
            'generateInQueue' => Craft::t('eat', 'Generate in the queue'),
            'batchSize' => Craft::t('eat', 'Batch size'),
            'imageTransform' => Craft::t('eat', 'Image transform'),
            'enableLiveRoutes' => Craft::t('eat', 'Enable live feed routes'),
            'liveCacheDuration' => Craft::t('eat', 'Live feed cache duration'),
        ];
    }

    /**
     * Neither Craft's nor Yii's FileHelper has an absolute-path test, so this is ours.
     */
    public static function isAbsolute(string $path): bool
    {
        return (bool)preg_match('#^([A-Za-z]:)?[/\\\\]#', $path);
    }

    /**
     * The absolute directory feeds are written to. An absolute setting is honoured as-is, so a feed
     * directory can live outside the web root when only FTP delivery needs it.
     */
    public function getFeedDirectory(): string
    {
        $directory = App::parseEnv($this->feedDirectory) ?: 'feeds';

        if (!self::isAbsolute($directory) && !str_starts_with($directory, '@')) {
            $directory = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . ltrim($directory, '/\\');
        } else {
            $directory = Craft::getAlias($directory);
        }

        return rtrim((string)$directory, '/\\');
    }

    /**
     * The base URL the written files are served from.
     */
    public function getFeedUrlBase(): string
    {
        $directory = App::parseEnv($this->feedDirectory) ?: 'feeds';

        if (self::isAbsolute($directory) || str_starts_with($directory, '@')) {
            // An absolute directory is not necessarily under the web root; the closest honest
            // answer is the folder name relative to it.
            $webroot = Craft::getAlias('@webroot');
            $resolved = Craft::getAlias($directory);

            if (is_string($webroot) && is_string($resolved) && str_starts_with($resolved, $webroot)) {
                $directory = ltrim(substr($resolved, strlen($webroot)), '/\\');
            } else {
                return UrlHelper::siteUrl();
            }
        }

        return UrlHelper::siteUrl(trim((string)$directory, '/'));
    }
}
