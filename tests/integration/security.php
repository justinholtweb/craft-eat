<?php
/**
 * Who may write Twig into a feed, and what a burst of fetches does to the live route — checked in
 * the plugin-testing harness, over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-eat/tests/integration/security.php
 *
 * Until 5.0.1 a Twig template mapping could be written by anybody with "Manage feeds", and it ran
 * as a full object template whose output is published — so `{{ craft.app.config.general.securityKey }}`
 * in a mapping put the security key in the preview, the file and the public live route. And the
 * live route rebuilt the whole catalogue for every request that arrived during a cache miss.
 *
 * Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\models\Mapping;
use justinholtweb\eat\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

// craft-penny's broken beforeSaveElement handler (see craft-bird's checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$run = bin2hex(random_bytes(3));
$password = 'Eat-' . bin2hex(random_bytes(6));
$secret = (string)Craft::$app->getConfig()->getGeneral()->securityKey;
$cleanup = ['feeds' => [], 'users' => []];

register_shutdown_function(function() use (&$cleanup, $plugin) {
    foreach ($cleanup['feeds'] as $feed) {
        Craft::$app->getCache()->delete('eat:live:last:' . $feed->id);
        $plugin->getFeeds()->deleteFeedById((int)$feed->id);
    }
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

$adminTemplate = '{{ product.title }}';
$feed = new Feed([
    'name' => "Security $run",
    'handle' => "security$run",
    'channel' => 'custom',
    'format' => 'csv',
    'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
    'enabled' => true,
]);
$feed->setMappings([
    ['attribute' => 'id', 'source' => Mapping::SOURCE_ATTRIBUTE, 'value' => 'sku', 'enabled' => true],
    ['attribute' => 'title', 'source' => Mapping::SOURCE_TEMPLATE, 'value' => $adminTemplate, 'enabled' => true],
]);
$feed->setOptions(['liveRoute' => true]);
$plugin->getFeeds()->saveFeed($feed) or throw new RuntimeException(json_encode($feed->getErrors()));
$cleanup['feeds'][] = $feed;

$manager = new User(['username' => "eat-manager-$run", 'email' => "eat-manager-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($manager, false);
Craft::$app->getUsers()->activateUser($manager);
Craft::$app->getUserPermissions()->saveUserPermissions($manager->id, ['accesscp', 'accessplugin-eat', 'eat-managefeeds']);
$cleanup['users'][] = $manager;

function client(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
    $login = $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);
    $login->getStatusCode() === 200 or throw new RuntimeException("Could not sign in as $username");

    $post = static fn(string $action, array $params) => $http->post("index.php?p=admin/actions/$action", ['headers' => $json, 'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()]]);

    return [$http, $post];
}

[, $managerPost] = client($manager->username, $password);
[, $adminPost] = client('admin', 'claudepassword');

$stored = static function() use ($plugin, $feed): array {
    // The web process saved it; this process's list of feeds is memoized.
    $plugin->getFeeds()->clearCaches();
    $fresh = $plugin->getFeeds()->getFeedById((int)$feed->id);
    $out = [];
    foreach ($fresh->getMappings() as $m) {
        $out[$m->attribute] = [$m->source, (string)$m->value];
    }

    return $out;
};

$base = static fn(array $rows) => [
    'id' => $feed->id, 'name' => $feed->name, 'handle' => $feed->handle, 'channel' => 'custom', 'format' => 'csv',
    'enabled' => '1', 'siteId' => $feed->siteId, 'mappings' => $rows,
];

$leak = '{{ craft.app.config.general.securityKey }}';

echo "\nWriting Twig into a feed\n";

check('“Manage feeds” alone can’t preview a new template', function() use ($managerPost, $feed, $leak, $secret) {
    $response = $managerPost('eat/feeds/preview', ['id' => $feed->id, 'mappings' => [
        ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => '1'],
        ['attribute' => 'leak', 'source' => 'template', 'value' => $leak, 'enabled' => '1'],
    ]]);

    return $response->getStatusCode() === 403 && !str_contains((string)$response->getBody(), $secret) ?: 'status ' . $response->getStatusCode();
});

check('…or save one', function() use ($managerPost, $base, $leak, $stored) {
    $status = $managerPost('eat/feeds/save', $base([
        ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => '1'],
        ['attribute' => 'title', 'source' => 'template', 'value' => $leak, 'enabled' => '1'],
    ]))->getStatusCode();

    return $status === 403 && ($stored()['title'][1] ?? null) !== $leak ?: "status $status";
});

check('…but can save the feed with an admin’s template untouched', function() use ($managerPost, $base, $adminTemplate, $stored) {
    $status = $managerPost('eat/feeds/save', $base([
        ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => '1'],
        ['attribute' => 'title', 'source' => 'template', 'value' => $adminTemplate, 'enabled' => '1'],
    ]))->getStatusCode();

    return $status === 200 && ($stored()['title'] ?? null) === ['template', $adminTemplate] ?: "status $status, " . json_encode($stored());
});

check('…including when the browser leaves the disabled source out', function() use ($managerPost, $base, $adminTemplate, $stored) {
    $status = $managerPost('eat/feeds/save', $base([
        ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => '1'],
        ['attribute' => 'title', 'value' => 'edited in the browser', 'enabled' => '1'],
    ]))->getStatusCode();

    return $status === 200 && ($stored()['title'] ?? null) === ['template', $adminTemplate] ?: "status $status, " . json_encode($stored());
});

check('an admin can write one', function() use ($adminPost, $base, $stored) {
    $status = $adminPost('eat/feeds/save', $base([
        ['attribute' => 'id', 'source' => 'attribute', 'value' => 'sku', 'enabled' => '1'],
        ['attribute' => 'title', 'source' => 'template', 'value' => '{{ product.title|upper }}', 'enabled' => '1'],
    ]))->getStatusCode();

    return $status === 200 && ($stored()['title'][1] ?? null) === '{{ product.title|upper }}' ?: "status $status";
});

echo "\nThe live route during a rebuild\n";

$anon = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]);
// The saves above posted no options, which turns the live route off; put it back.
$plugin->getFeeds()->clearCaches();
$fresh = $plugin->getFeeds()->getFeedById((int)$feed->id);
$fresh->setOptions(array_merge($fresh->getOptions(), ['liveRoute' => true]));
$plugin->getFeeds()->saveFeed($fresh) or throw new RuntimeException('could not re-enable the live route');
$lock = 'eat:live:build:' . $feed->id;
$mutex = Craft::$app->getMutex();
$cache = Craft::$app->getCache();
$cache->delete('eat:live:last:' . $feed->id);
$cache->delete('eat:live:' . $feed->id . ':' . ($fresh->dateUpdated?->getTimestamp() ?? 0));

check('while one request is building it, the next is told to come back — not set to building too', function() use ($anon, $feed, $mutex, $lock) {
    $mutex->acquire($lock) or throw new RuntimeException('could not take the lock');
    try {
        $response = $anon->get("index.php?p=eat/feed/{$feed->handle}");
    } finally {
        $mutex->release($lock);
    }

    return $response->getStatusCode() === 503 && $response->getHeaderLine('Retry-After') !== '' ?: 'status ' . $response->getStatusCode();
});

check('…or handed the last good feed, if there is one', function() use ($anon, $feed, $mutex, $lock, $cache, $run) {
    $cache->set('eat:live:last:' . $feed->id, "stale-$run", 60);
    $mutex->acquire($lock) or throw new RuntimeException('could not take the lock');
    try {
        $response = $anon->get("index.php?p=eat/feed/{$feed->handle}");
    } finally {
        $mutex->release($lock);
    }

    return $response->getStatusCode() === 200 && (string)$response->getBody() === "stale-$run" ?: 'status ' . $response->getStatusCode();
});

check('with nobody building, it builds — and the admin’s template renders', function() use ($anon, $feed, $run) {
    $response = $anon->get("index.php?p=eat/feed/{$feed->handle}");
    $body = (string)$response->getBody();

    return $response->getStatusCode() === 200 && $body !== "stale-$run" && preg_match('/[A-Z]{3,}/', $body) === 1
        ?: 'status ' . $response->getStatusCode() . ' ' . substr($body, 0, 60);
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
