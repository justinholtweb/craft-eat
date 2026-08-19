<?php

namespace justinholtweb\eat\controllers;

use Craft;
use craft\helpers\FileHelper;
use craft\web\Controller;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The live feed route: `/eat/feed/<handle>`.
 *
 * A feed can be fetched rather than written — handy for small catalogues, and the only way to be
 * certain the channel gets the very latest prices.
 */
class FeedController extends Controller
{
    public array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function actionIndex(string $handle): Response
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->enableLiveRoutes) {
            throw new NotFoundHttpException('Feed not found');
        }

        $feed = $plugin->getFeeds()->getFeedByHandle($handle);

        if ($feed === null || !$feed->enabled) {
            throw new NotFoundHttpException('Feed not found');
        }

        // A feed that is not marked live is still served from its last generated file — the route
        // is then just a stable URL in front of the file, which is what a channel wants anyway.
        if (!$feed->getOption('liveRoute')) {
            return $this->_serveFile($feed);
        }

        if (!$plugin->isPro()) {
            return $this->_serveFile($feed);
        }

        $cacheKey = 'eat:live:' . $feed->id . ':' . ($feed->dateUpdated?->getTimestamp() ?? 0);
        $cache = Craft::$app->getCache();
        $body = $cache->get($cacheKey);

        if (!is_string($body) || $body === '') {
            $result = $plugin->getGenerator()->write($feed);
            $body = (string)@file_get_contents($result['path']);
            FileHelper::unlink($result['path']);

            $duration = $feed->interval > 0 ? $feed->interval : $plugin->getSettings()->liveCacheDuration;
            $cache->set($cacheKey, $body, max(60, $duration));
        }

        return $this->_respond($feed, $body);
    }

    private function _serveFile(Feed $feed): Response
    {
        $path = $feed->getFilePath();

        if (!is_file($path)) {
            throw new NotFoundHttpException('Feed has not been generated yet');
        }

        return Craft::$app->getResponse()->sendFile($path, $feed->getFileName(), [
            'mimeType' => $feed->getMimeType(),
            'inline' => true,
        ]);
    }

    private function _respond(Feed $feed, string $body): Response
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', $feed->getMimeType());

        if ($feed->getOption('compress')) {
            $response->getHeaders()->set('Content-Disposition', 'inline; filename="' . $feed->getFileName() . '"');
        }

        $response->data = $body;

        return $response;
    }
}
