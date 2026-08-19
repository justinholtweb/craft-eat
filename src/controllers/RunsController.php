<?php

namespace justinholtweb\eat\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\eat\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The run log (Pro).
 */
class RunsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('eat-viewRuns');

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(Craft::t('eat', 'The run log is a Pro feature.'));
        }

        return true;
    }

    public function actionIndex(?int $feedId = null): Response
    {
        $plugin = Plugin::getInstance();
        $feedId ??= (int)$this->request->getQueryParam('feedId') ?: null;
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $perPage = 50;

        $runs = $plugin->getRuns()->getRuns($feedId, $perPage, ($page - 1) * $perPage);
        $total = $plugin->getRuns()->getTotalRuns($feedId);

        $feeds = [];

        foreach ($plugin->getFeeds()->getAllFeeds() as $feed) {
            $feeds[$feed->id] = $feed;
        }

        return $this->renderTemplate('eat/runs/_index', [
            'runs' => $runs,
            'feeds' => $feeds,
            'feedId' => $feedId,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ]);
    }

    public function actionDetail(int $runId): Response
    {
        $plugin = Plugin::getInstance();
        $run = $plugin->getRuns()->getRunById($runId);

        if ($run === null) {
            throw new NotFoundHttpException('Run not found');
        }

        return $this->renderTemplate('eat/runs/_detail', [
            'run' => $run,
            'feed' => $run->feedId ? $plugin->getFeeds()->getFeedById($run->feedId) : null,
        ]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $feedId = (int)$this->request->getBodyParam('feedId');

        if ($feedId) {
            Plugin::getInstance()->getRuns()->deleteRunsForFeed($feedId);
        } else {
            Plugin::getInstance()->getRuns()->pruneOlderThan(0);
        }

        return $this->asSuccess(Craft::t('eat', 'Run log cleared.'));
    }
}
