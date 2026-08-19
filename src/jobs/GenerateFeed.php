<?php

namespace justinholtweb\eat\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\eat\Plugin;

/**
 * Generating a feed in the background, which is where a 50,000 product catalogue belongs.
 */
class GenerateFeed extends BaseJob
{
    public int $feedId;
    public string $feedName = '';
    public string $trigger = 'schedule';

    public function execute($queue): void
    {
        $feeds = Plugin::getInstance()->getFeeds();
        $feed = $feeds->getFeedById($this->feedId);

        if ($feed === null) {
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('eat', 'Reading products'));

        $run = $feeds->run($feed, $this->trigger);

        Craft::$app->getCache()->delete('eat:queued:' . $this->feedId);

        $this->setProgress($queue, 1, Craft::t('eat', '{n} products', ['n' => $run->itemCount]));

        if ($run->getIsError()) {
            throw new \RuntimeException((string)$run->message);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('eat', 'Generating the {name} product feed', [
            'name' => $this->feedName !== '' ? $this->feedName : $this->feedId,
        ]);
    }
}
