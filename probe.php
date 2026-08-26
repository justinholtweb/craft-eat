<?php
require getcwd() . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
use justinholtweb\eat\Plugin;
$plugin = Plugin::getInstance();
foreach ($plugin->getFeeds()->getAllFeeds() as $feed) {
    echo "feed {$feed->handle} id={$feed->id}\n";
}
foreach ($plugin->getRuns()->getRuns(null, 8) as $run) {
    echo "run {$run->id} feed={$run->feedId} status={$run->status} items={$run->itemCount} skipped={$run->skippedCount}\n";
}
