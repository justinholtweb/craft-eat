<?php

namespace justinholtweb\eat\twig;

use justinholtweb\eat\channels\Registry;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\models\Run;
use justinholtweb\eat\Plugin;

/**
 * `craft.eat.*`
 */
class EatVariable
{
    /**
     * @return Feed[]
     */
    public function feeds(bool $enabledOnly = true): array
    {
        $feeds = Plugin::getInstance()->getFeeds();

        return $enabledOnly ? $feeds->getEnabledFeeds() : $feeds->getAllFeeds();
    }

    public function feed(string $handle): ?Feed
    {
        return Plugin::getInstance()->getFeeds()->getFeedByHandle($handle);
    }

    public function url(string $handle): ?string
    {
        return $this->feed($handle)?->getUrl();
    }

    public function lastRun(string $handle): ?Run
    {
        $feed = $this->feed($handle);

        return $feed?->id ? Plugin::getInstance()->getRuns()->getLastRun($feed->id) : null;
    }

    /**
     * The first rows of a feed, for a template that would rather render the products itself.
     *
     * @return array<int, array<string, string|array>>
     */
    public function items(string $handle, int $limit = 50): array
    {
        $feed = $this->feed($handle);

        if ($feed === null) {
            return [];
        }

        return Plugin::getInstance()->getGenerator()->preview($feed, $limit);
    }

    public function channels(): array
    {
        return Registry::all();
    }

    public function isPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }
}
