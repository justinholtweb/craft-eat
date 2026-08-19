<?php

namespace justinholtweb\eat\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\Json;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\Plugin;
use Throwable;

/**
 * Pushing products into Google Merchant Center over the Content API, instead of leaving a file
 * somewhere and hoping Google comes to fetch it.
 *
 * No Google SDK: a service-account JWT is 40 lines of `openssl_sign`, and a dependency that drags
 * in half of `google/apiclient` is not worth it for two endpoints.
 */
class Merchant extends Component
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const API_BASE = 'https://shoppingcontent.googleapis.com/content/v2.1';
    public const SCOPE = 'https://www.googleapis.com/auth/content';
    public const BATCH_SIZE = 100;

    /**
     * Content API field names for the Google feed attributes Eat knows how to fill.
     */
    public const FIELD_MAP = [
        'id' => 'offerId',
        'title' => 'title',
        'description' => 'description',
        'link' => 'link',
        'image_link' => 'imageLink',
        'additional_image_link' => 'additionalImageLinks',
        'availability' => 'availability',
        'availability_date' => 'availabilityDate',
        'brand' => 'brand',
        'gtin' => 'gtin',
        'mpn' => 'mpn',
        'identifier_exists' => 'identifierExists',
        'condition' => 'condition',
        'google_product_category' => 'googleProductCategory',
        'product_type' => 'productTypes',
        'item_group_id' => 'itemGroupId',
        'color' => 'color',
        'size' => 'sizes',
        'material' => 'material',
        'pattern' => 'pattern',
        'age_group' => 'ageGroup',
        'gender' => 'gender',
        'multipack' => 'multipack',
        'is_bundle' => 'isBundle',
        'mobile_link' => 'mobileLink',
        'expiration_date' => 'expirationDate',
        'custom_label_0' => 'customLabel0',
        'custom_label_1' => 'customLabel1',
        'custom_label_2' => 'customLabel2',
        'custom_label_3' => 'customLabel3',
        'custom_label_4' => 'customLabel4',
    ];

    /**
     * Send every row of a feed to Merchant Center.
     *
     * @return array<string, mixed>
     */
    public function push(Feed $feed): array
    {
        if (!Plugin::getInstance()->isPro()) {
            return ['mode' => 'merchant', 'ok' => false, 'message' => Craft::t('eat', 'Content API push is a Pro feature.')];
        }

        $config = $feed->getDelivery()['merchant'] ?? [];
        $merchantId = trim((string)App::parseEnv((string)($config['merchantId'] ?? '')));

        if ($merchantId === '') {
            return ['mode' => 'merchant', 'ok' => false, 'message' => Craft::t('eat', 'No Merchant Center account ID.')];
        }

        try {
            $token = $this->token($this->serviceAccount($config));
        } catch (Throwable $e) {
            return ['mode' => 'merchant', 'ok' => false, 'message' => $e->getMessage()];
        }

        $client = Craft::createGuzzleClient(['timeout' => 60]);
        $generator = Plugin::getInstance()->getGenerator();
        $batch = [];
        $sent = 0;
        $failed = 0;
        $errors = [];
        $line = 0;

        foreach ($generator->rows($feed) as $row) {
            $batch[] = [
                'batchId' => ++$line,
                'merchantId' => $merchantId,
                'method' => 'insert',
                'product' => $this->productResource($row['values'], $config),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->_send($client, $token, $batch, $sent, $failed, $errors);
                $batch = [];
            }
        }

        if ($batch) {
            $this->_send($client, $token, $batch, $sent, $failed, $errors);
        }

        return [
            'mode' => 'merchant',
            'ok' => $failed === 0,
            'sent' => $sent,
            'failed' => $failed,
            'errors' => array_slice($errors, 0, 10),
            'message' => $failed === 0
                ? Craft::t('eat', '{n} products sent to Merchant Center.', ['n' => $sent])
                : Craft::t('eat', '{n} of {total} products were rejected.', ['n' => $failed, 'total' => $sent + $failed]),
        ];
    }

    /**
     * Prove the credentials work without sending anything.
     */
    public function test(array $config): array
    {
        try {
            $token = $this->token($this->serviceAccount($config));
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $merchantId = trim((string)App::parseEnv((string)($config['merchantId'] ?? '')));

        if ($merchantId === '') {
            return ['ok' => true, 'message' => Craft::t('eat', 'Signed in, but no Merchant Center account ID was given.')];
        }

        try {
            $client = Craft::createGuzzleClient(['timeout' => 30]);
            $response = $client->get(self::API_BASE . "/accounts/$merchantId", [
                'headers' => ['Authorization' => "Bearer $token"],
                'http_errors' => false,
            ]);

            $body = Json::decodeIfJson((string)$response->getBody());

            if ($response->getStatusCode() >= 400) {
                return ['ok' => false, 'message' => $body['error']['message'] ?? ('HTTP ' . $response->getStatusCode())];
            }

            return ['ok' => true, 'message' => Craft::t('eat', 'Connected to “{name}”.', ['name' => $body['name'] ?? $merchantId])];
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
        // Expire our copy a minute early: a token that dies mid-batch costs a whole feed.
        Craft::$app->getCache()->set($cacheKey, $token, max(60, (int)($body['expires_in'] ?? 3600) - 60));

        return $token;
    }

    /**
     * One feed row as a Content API product resource.
     *
     * @param array<string, string|array> $values
     */
    public function productResource(array $values, array $config): array
    {
        $product = [
            'channel' => (string)($config['channel'] ?? 'online'),
            'contentLanguage' => strtolower((string)($config['contentLanguage'] ?? 'en')),
            'targetCountry' => strtoupper((string)($config['targetCountry'] ?? 'US')),
        ];

        foreach ($values as $key => $value) {
            $field = self::FIELD_MAP[$key] ?? null;

            if ($field === null) {
                continue;
            }

            if (in_array($field, ['additionalImageLinks', 'productTypes', 'sizes'], true)) {
                $product[$field] = array_values((array)$value);
                continue;
            }

            if (is_array($value)) {
                $value = $value[0] ?? '';
            }

            $product[$field] = (string)$value;
        }

        foreach (['price' => 'price', 'sale_price' => 'salePrice'] as $key => $field) {
            $raw = $values[$key] ?? '';

            if (is_array($raw)) {
                $raw = $raw[0] ?? '';
            }

            $money = $this->_money((string)$raw);

            if ($money !== null) {
                $product[$field] = $money;
            }
        }

        if (isset($product['isBundle'])) {
            $product['isBundle'] = in_array(strtolower((string)$product['isBundle']), ['yes', 'true', '1'], true);
        }

        if (isset($product['identifierExists'])) {
            $product['identifierExists'] = in_array(strtolower((string)$product['identifierExists']), ['yes', 'true', '1'], true);
        }

        return $product;
    }

    public function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * `12.00 USD` — the feed's own price format — as the API's `{value, currency}`.
     */
    private function _money(string $raw): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^([\d.,]+)\s*([A-Za-z]{3})?$/', $raw, $matches)) {
            return [
                'value' => str_replace(',', '', $matches[1]),
                'currency' => strtoupper($matches[2] ?? 'USD'),
            ];
        }

        return null;
    }

    private function _send(\GuzzleHttp\Client $client, string $token, array $entries, int &$sent, int &$failed, array &$errors): void
    {
        try {
            $response = $client->post(self::API_BASE . '/products/batch', [
                'headers' => ['Authorization' => "Bearer $token"],
                'json' => ['entries' => $entries],
                'http_errors' => false,
            ]);

            $body = Json::decodeIfJson((string)$response->getBody());

            if (!is_array($body) || !isset($body['entries'])) {
                $failed += count($entries);
                $errors[] = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $response->getStatusCode()) : 'HTTP ' . $response->getStatusCode();

                return;
            }

            foreach ($body['entries'] as $entry) {
                if (isset($entry['errors'])) {
                    $failed++;
                    $errors[] = $entry['errors']['errors'][0]['message'] ?? 'rejected';
                    continue;
                }

                $sent++;
            }
        } catch (Throwable $e) {
            $failed += count($entries);
            $errors[] = $e->getMessage();
        }
    }
}
