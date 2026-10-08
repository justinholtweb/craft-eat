<?php

namespace justinholtweb\eat\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\Json;
use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\Plugin;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Pushing products into Google Merchant Center over the Merchant API, instead of leaving a file
 * somewhere and hoping Google comes to fetch it.
 *
 * The Content API for Shopping that Eat 5.0 used was sunset on 2026-08-18 and fails with 410s
 * from 2026-09-01. The Merchant API differs in three ways that shape this class:
 *
 * - products go into a *data source*, and only an API-input one, so a feed needs one to push to;
 * - there is no batch endpoint, so products are inserted one request each, a few at a time;
 * - the Google Cloud project must be registered with the Merchant Center account once.
 *
 * No Google SDK: a service-account JWT is 40 lines of `openssl_sign`, and a dependency that drags
 * in half of `google/apiclient` is not worth it for a handful of endpoints.
 */
class Merchant extends Component
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const API_ROOT = 'https://merchantapi.googleapis.com';
    public const SCOPE = 'https://www.googleapis.com/auth/content';

    /**
     * How many insert requests are in flight at once. Google's per-minute quota is generous;
     * this is about not holding a queue worker on a thousand sockets.
     */
    public const CONCURRENCY = 8;

    /**
     * Rows gathered before a pool of requests is sent.
     */
    public const CHUNK_SIZE = 200;

    /**
     * Merchant API `productAttributes` names for the Google feed attributes Eat knows how to fill.
     */
    public const FIELD_MAP = [
        'title' => 'title',
        'description' => 'description',
        'link' => 'link',
        'image_link' => 'imageLink',
        'additional_image_link' => 'additionalImageLinks',
        'availability' => 'availability',
        'availability_date' => 'availabilityDate',
        'brand' => 'brand',
        'gtin' => 'gtins',
        'mpn' => 'mpn',
        'identifier_exists' => 'identifierExists',
        'condition' => 'condition',
        'google_product_category' => 'googleProductCategory',
        'product_type' => 'productTypes',
        'item_group_id' => 'itemGroupId',
        'color' => 'color',
        'size' => 'size',
        'material' => 'material',
        'pattern' => 'pattern',
        'age_group' => 'ageGroup',
        'gender' => 'gender',
        'multipack' => 'multipack',
        'is_bundle' => 'isBundle',
        'adult' => 'adult',
        'mobile_link' => 'mobileLink',
        'expiration_date' => 'expirationDate',
        'custom_label_0' => 'customLabel0',
        'custom_label_1' => 'customLabel1',
        'custom_label_2' => 'customLabel2',
        'custom_label_3' => 'customLabel3',
        'custom_label_4' => 'customLabel4',
    ];

    /**
     * Attributes the API takes as arrays.
     */
    private const REPEATED = ['additionalImageLinks', 'gtins', 'productTypes'];

    /**
     * Attributes the API takes as booleans.
     */
    private const BOOLEANS = ['identifierExists', 'isBundle', 'adult'];

    /**
     * Attributes the API takes as timestamps.
     */
    private const TIMESTAMPS = ['availabilityDate', 'expirationDate'];

    /**
     * Enum attributes, and the values the API accepts for each. A feed spells these the way the
     * Google product data specification does (`in stock`, `in_stock`); the API wants `IN_STOCK`.
     */
    private const ENUMS = [
        'availability' => ['IN_STOCK', 'OUT_OF_STOCK', 'PREORDER', 'LIMITED_AVAILABILITY', 'BACKORDER'],
        'condition' => ['NEW', 'USED', 'REFURBISHED'],
        'ageGroup' => ['ADULT', 'KIDS', 'TODDLER', 'INFANT', 'NEWBORN'],
        'gender' => ['MALE', 'FEMALE', 'UNISEX'],
    ];

    /**
     * Send every row of a feed to Merchant Center.
     *
     * @return array<string, mixed>
     */
    public function push(Feed $feed): array
    {
        if (!Plugin::getInstance()->isPro()) {
            return ['mode' => 'merchant', 'ok' => false, 'message' => Craft::t('eat', 'Merchant Center push is a Pro feature.')];
        }

        $config = $feed->getDelivery()['merchant'] ?? [];
        $merchantId = $this->merchantId($config);

        if ($merchantId === '') {
            return ['mode' => 'merchant', 'ok' => false, 'message' => Craft::t('eat', 'No Merchant Center account ID.')];
        }

        $client = Craft::createGuzzleClient(['timeout' => 60]);

        try {
            $token = $this->token($this->serviceAccount($config));
            $registration = $this->ensureRegistered($client, $token, $merchantId);
            $dataSource = $this->dataSource($client, $token, $merchantId, $feed, $config);
        } catch (Throwable $e) {
            return ['mode' => 'merchant', 'ok' => false, 'message' => $this->_withRegistration($e->getMessage(), $registration ?? null)];
        }

        $url = self::API_ROOT . "/products/v1/accounts/$merchantId/productInputs:insert?dataSource=" . rawurlencode($dataSource);
        $chunk = [];
        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach (Plugin::getInstance()->getGenerator()->rows($feed) as $row) {
            $chunk[] = $this->productInput($row['values'], $config);

            if (count($chunk) >= self::CHUNK_SIZE) {
                $this->_insert($client, $token, $url, $chunk, $sent, $failed, $errors);
                $chunk = [];
            }
        }

        if ($chunk) {
            $this->_insert($client, $token, $url, $chunk, $sent, $failed, $errors);
        }

        return [
            'mode' => 'merchant',
            'ok' => $failed === 0,
            'sent' => $sent,
            'failed' => $failed,
            'dataSource' => $dataSource,
            'errors' => array_slice($errors, 0, 10),
            'message' => $failed === 0
                ? Craft::t('eat', '{n} products sent to Merchant Center.', ['n' => $sent])
                : $this->_withRegistration(
                    Craft::t('eat', '{n} of {total} products were rejected.', ['n' => $failed, 'total' => $sent + $failed]),
                    $sent === 0 ? $registration : null,
                ),
        ];
    }

    /**
     * Prove the credentials work without sending any products. Registers the Google Cloud project
     * with the account on the way, since the Merchant API refuses everything until that is done.
     */
    public function test(array $config): array
    {
        try {
            $token = $this->token($this->serviceAccount($config));
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $merchantId = $this->merchantId($config);

        if ($merchantId === '') {
            return ['ok' => true, 'message' => Craft::t('eat', 'Signed in, but no Merchant Center account ID was given.')];
        }

        try {
            $client = Craft::createGuzzleClient(['timeout' => 30]);
            $registration = $this->ensureRegistered($client, $token, $merchantId);
            [$status, $body] = $this->_request($client, 'GET', self::API_ROOT . "/accounts/v1/accounts/$merchantId", $token);

            if ($status >= 400) {
                return ['ok' => false, 'message' => $this->_withRegistration($this->_errorMessage($body, $status), $registration)];
            }

            return ['ok' => true, 'message' => Craft::t('eat', 'Connected to “{name}”.', ['name' => $body['accountName'] ?? $merchantId])];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * The service account JSON, however the merchant gave it to us: pasted, an env var, or a path
     * to a file outside the web root (which is where it belongs).
     */
    public function serviceAccount(array $config): array
    {
        $raw = trim((string)App::parseEnv((string)($config['serviceAccount'] ?? '')));

        if ($raw === '') {
            throw new \RuntimeException(Craft::t('eat', 'No Google service account credentials.'));
        }

        if (!str_starts_with($raw, '{')) {
            $path = Craft::getAlias($raw);

            if (!is_string($path) || !is_file($path)) {
                throw new \RuntimeException(Craft::t('eat', 'The service account file could not be read.'));
            }

            $raw = (string)file_get_contents($path);
        }

        $decoded = Json::decodeIfJson($raw);

        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            throw new \RuntimeException(Craft::t('eat', 'The service account credentials are not a valid key file.'));
        }

        return $decoded;
    }

    /**
     * Sign a JWT with the service account key and swap it for an access token.
     */
    public function token(array $account): string
    {
        $cacheKey = 'eat:merchant:token:' . md5((string)$account['client_email']);
        $cached = Craft::$app->getCache()->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $input = $this->base64Url(Json::encode($header)) . '.' . $this->base64Url(Json::encode($claims));
        $signature = '';

        if (!openssl_sign($input, $signature, (string)$account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException(Craft::t('eat', 'Could not sign the Google credentials.'));
        }

        $assertion = $input . '.' . $this->base64Url($signature);

        $client = Craft::createGuzzleClient(['timeout' => 30]);
        $response = $client->post(self::TOKEN_URL, [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ],
            'http_errors' => false,
        ]);

        $body = Json::decodeIfJson((string)$response->getBody());

        if (!is_array($body) || empty($body['access_token'])) {
            throw new \RuntimeException(Craft::t('eat', 'Google refused the credentials: {message}', [
                'message' => is_array($body) ? ($body['error_description'] ?? $body['error'] ?? 'unknown error') : 'unknown error',
            ]));
        }

        $token = (string)$body['access_token'];
        // Expire our copy a minute early: a token that dies mid-run costs a whole feed.
        Craft::$app->getCache()->set($cacheKey, $token, max(60, (int)($body['expires_in'] ?? 3600) - 60));

        return $token;
    }

    /**
     * Register the service account's Google Cloud project with the Merchant Center account.
     *
     * Google calls this a mandatory one-time step; until it happens every call answers 401 "GCP
     * project … is not registered". Registering needs the service account to be an admin on the
     * account — but the project may already have been registered by someone who is, so a refusal
     * here is returned as a note rather than thrown, and only shown if a later call fails.
     * Success is cached so a scheduled feed doesn't repeat it every run.
     */
    public function ensureRegistered(Client $client, string $token, string $merchantId): ?string
    {
        $cacheKey = 'eat:merchant:registered:' . $merchantId;

        if (Craft::$app->getCache()->get($cacheKey)) {
            return null;
        }

        [$status, $body] = $this->_request(
            $client,
            'POST',
            self::API_ROOT . "/accounts/v1/accounts/$merchantId/developerRegistration:registerGcp",
            $token,
            new \stdClass(),
        );

        $message = $this->_errorMessage($body, $status);

        if ($status < 400 || str_contains(strtoupper($message), 'ALREADY_REGISTERED')) {
            Craft::$app->getCache()->set($cacheKey, true, 86400);

            return null;
        }

        return $message;
    }

    /**
     * The API data source a feed pushes into, as `accounts/{id}/dataSources/{id}`.
     *
     * One named in the feed's settings wins. Otherwise Eat finds the one it created for this feed
     * before (by display name) or creates it, so a merchant upgrading from 5.0 gets a working push
     * without a trip to Merchant Center.
     */
    public function dataSource(Client $client, string $token, string $merchantId, Feed $feed, array $config): string
    {
        $configured = trim((string)App::parseEnv((string)($config['dataSource'] ?? '')));

        if ($configured !== '') {
            return str_starts_with($configured, 'accounts/')
                ? $configured
                : "accounts/$merchantId/dataSources/" . preg_replace('/\D/', '', $configured);
        }

        $displayName = $this->dataSourceName($feed);
        $base = self::API_ROOT . "/datasources/v1/accounts/$merchantId/dataSources";
        $pageToken = null;

        do {
            [$status, $body] = $this->_request($client, 'GET', $base . '?pageSize=1000' . ($pageToken ? '&pageToken=' . rawurlencode($pageToken) : ''), $token);

            if ($status >= 400) {
                throw new \RuntimeException(Craft::t('eat', 'Could not list Merchant Center data sources: {message}', [
                    'message' => $this->_errorMessage($body, $status),
                ]));
            }

            foreach ((array)($body['dataSources'] ?? []) as $source) {
                if (($source['displayName'] ?? null) === $displayName && ($source['input'] ?? 'API') === 'API' && isset($source['primaryProductDataSource'])) {
                    return (string)$source['name'];
                }
            }

            $pageToken = $body['nextPageToken'] ?? null;
        } while ($pageToken);

        // Language and label are left off so the source accepts whatever each product carries;
        // the countries are what decides where the products can show.
        [$status, $body] = $this->_request($client, 'POST', $base, $token, [
            'displayName' => $displayName,
            'primaryProductDataSource' => [
                'countries' => [$this->country($config)],
            ],
        ]);

        if ($status >= 400 || empty($body['name'])) {
            throw new \RuntimeException(Craft::t('eat', 'Could not create a Merchant Center data source: {message}', [
                'message' => $this->_errorMessage($body, $status),
            ]));
        }

        return (string)$body['name'];
    }

    /**
     * The display name of the data source Eat creates for a feed.
     */
    public function dataSourceName(Feed $feed): string
    {
        return 'Eat: ' . $feed->handle;
    }

    /**
     * One feed row as a Merchant API `ProductInput`.
     *
     * @param array<string, string|array> $values
     */
    public function productInput(array $values, array $config): array
    {
        $offerId = $values['id'] ?? '';

        if (is_array($offerId)) {
            $offerId = $offerId[0] ?? '';
        }

        $feedLabel = strtoupper(trim((string)($config['feedLabel'] ?? ''))) ?: $this->country($config);

        $product = [
            'offerId' => (string)$offerId,
            'contentLanguage' => strtolower(trim((string)($config['contentLanguage'] ?? '')) ?: 'en'),
            'feedLabel' => $feedLabel,
        ];

        // The Content API's `local` channel is the Merchant API's `legacyLocal`.
        if (($config['channel'] ?? 'online') === 'local') {
            $product['legacyLocal'] = true;
        }

        $attributes = [];

        foreach ($values as $key => $value) {
            $field = self::FIELD_MAP[$key] ?? null;

            if ($field === null) {
                continue;
            }

            if (in_array($field, self::REPEATED, true)) {
                $list = array_values(array_filter(array_map('strval', (array)$value), fn(string $v) => trim($v) !== ''));

                if ($list) {
                    $attributes[$field] = $list;
                }

                continue;
            }

            if (is_array($value)) {
                $value = $value[0] ?? '';
            }

            $value = trim((string)$value);

            if ($value === '') {
                continue;
            }

            if (in_array($field, self::BOOLEANS, true)) {
                $attributes[$field] = in_array(strtolower($value), ['yes', 'true', '1'], true);
            } elseif (isset(self::ENUMS[$field])) {
                $enum = $this->enum($field, $value);

                if ($enum !== null) {
                    $attributes[$field] = $enum;
                }
            } elseif (in_array($field, self::TIMESTAMPS, true)) {
                $timestamp = $this->timestamp($value);

                if ($timestamp !== null) {
                    $attributes[$field] = $timestamp;
                }
            } elseif ($field === 'multipack') {
                if (ctype_digit($value)) {
                    $attributes[$field] = $value;
                }
            } else {
                $attributes[$field] = $value;
            }
        }

        foreach (['price' => 'price', 'sale_price' => 'salePrice'] as $key => $field) {
            $raw = $values[$key] ?? '';

            if (is_array($raw)) {
                $raw = $raw[0] ?? '';
            }

            $money = $this->money((string)$raw);

            if ($money !== null) {
                $attributes[$field] = $money;
            }
        }

        $product['productAttributes'] = $attributes ?: new \stdClass();

        return $product;
    }

    /**
     * `12.00 USD` — the feed's own price format — as the API's `{amountMicros, currencyCode}`.
     * Done on the decimal string, so `19.99` is 19990000 micros and never 19989999.
     */
    public function money(string $raw): ?array
    {
        $raw = trim($raw);

        if (!preg_match('/^(\d[\d,]*)(?:\.(\d+))?\s*([A-Za-z]{3})?$/', $raw, $matches)) {
            return null;
        }

        $whole = ltrim(str_replace(',', '', $matches[1]), '0');
        $fraction = substr(str_pad($matches[2] ?? '', 6, '0'), 0, 6);
        $micros = ltrim($whole . $fraction, '0');

        return [
            'amountMicros' => $micros === '' ? '0' : $micros,
            'currencyCode' => strtoupper(($matches[3] ?? '') ?: 'USD'),
        ];
    }

    /**
     * A feed's enum spelling (`in stock`, `in_stock`, `In Stock`) as the API's (`IN_STOCK`), or null
     * when it isn't one the API knows.
     */
    public function enum(string $field, string $value): ?string
    {
        $normalised = strtoupper((string)preg_replace('/[\s-]+/', '_', trim($value)));

        return in_array($normalised, self::ENUMS[$field] ?? [], true) ? $normalised : null;
    }

    /**
     * An ISO 8601 date or date-time as an RFC 3339 UTC timestamp, or null when it doesn't parse.
     * The feed's `2026-11-01T00:00-0500` and a bare `2026-11-01` both work.
     */
    public function timestamp(string $value): ?string
    {
        try {
            $date = new DateTime($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * The target country, upper-cased, which is also the default feed label.
     */
    public function country(array $config): string
    {
        return strtoupper(trim((string)($config['targetCountry'] ?? ''))) ?: 'US';
    }

    private function _withRegistration(string $message, ?string $registration): string
    {
        if ($registration === null) {
            return $message;
        }

        return $message . ' ' . Craft::t('eat', 'Registering the Google Cloud project with this account also failed ({message}); the service account needs admin access to register it.', [
            'message' => $registration,
        ]);
    }

    private function merchantId(array $config): string
    {
        return preg_replace('/\D/', '', trim((string)App::parseEnv((string)($config['merchantId'] ?? '')))) ?? '';
    }

    /**
     * Insert one chunk of products, a few requests at a time. A product the API rate-limits gets
     * one more try after the rest of the chunk is done.
     *
     * @param array<int, array> $products
     */
    private function _insert(Client $client, string $token, string $url, array $products, int &$sent, int &$failed, array &$errors, bool $retry = true): void
    {
        $retryable = [];

        $requests = function() use ($products, $token, $url) {
            foreach ($products as $i => $product) {
                yield $i => new Request('POST', $url, [
                    'Authorization' => "Bearer $token",
                    'Content-Type' => 'application/json',
                ], Json::encode($product));
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => self::CONCURRENCY,
            'options' => ['http_errors' => false],
            'fulfilled' => function(ResponseInterface $response, $i) use ($products, $retry, &$retryable, &$sent, &$failed, &$errors) {
                $status = $response->getStatusCode();

                if ($status < 400) {
                    $sent++;

                    return;
                }

                if ($retry && in_array($status, [429, 500, 503], true)) {
                    $retryable[] = $products[$i];

                    return;
                }

                $failed++;
                $errors[] = ($products[$i]['offerId'] ?? '?') . ': ' . $this->_errorMessage(Json::decodeIfJson((string)$response->getBody()), $status);
            },
            'rejected' => function($reason, $i) use ($products, &$failed, &$errors) {
                $failed++;
                $errors[] = ($products[$i]['offerId'] ?? '?') . ': ' . ($reason instanceof Throwable ? $reason->getMessage() : 'request failed');
            },
        ]);

        $pool->promise()->wait();

        if ($retryable) {
            sleep(2);
            $this->_insert($client, $token, $url, $retryable, $sent, $failed, $errors, false);
        }
    }

    /**
     * @return array{0: int, 1: mixed}
     */
    private function _request(Client $client, string $method, string $url, string $token, mixed $json = null): array
    {
        $options = [
            'headers' => ['Authorization' => "Bearer $token"],
            'http_errors' => false,
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        $response = $client->request($method, $url, $options);

        return [$response->getStatusCode(), Json::decodeIfJson((string)$response->getBody())];
    }

    private function _errorMessage(mixed $body, int $status): string
    {
        if (is_array($body) && isset($body['error'])) {
            $message = (string)($body['error']['message'] ?? '');
            $reason = (string)($body['error']['status'] ?? '');

            foreach ((array)($body['error']['details'] ?? []) as $detail) {
                if (!empty($detail['reason'])) {
                    $reason = (string)$detail['reason'];
                    break;
                }
            }

            return trim($message . ($reason !== '' ? " ($reason)" : '')) ?: "HTTP $status";
        }

        return "HTTP $status";
    }
}
