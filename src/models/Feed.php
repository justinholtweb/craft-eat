<?php

namespace justinholtweb\eat\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\conditions\products\ProductCondition;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\validators\UniqueValidator;
use DateTime;
use justinholtweb\eat\channels\Registry;
use justinholtweb\eat\Plugin;
use justinholtweb\eat\records\FeedRecord;

/**
 * A feed definition.
 *
 * @property-read Channel|null $channelDefinition
 * @property Mapping[] $mappings
 */
class Feed extends Model
{
    public const VARIANT_MODE_VARIANT = 'variant';
    public const VARIANT_MODE_DEFAULT = 'default';
    public const VARIANT_MODE_PRODUCT = 'product';

    public ?int $id = null;
    public ?int $siteId = null;
    public ?int $storeId = null;
    public ?string $name = null;
    public ?string $handle = null;
    public string $channel = 'google';
    public string $format = 'rss';
    public bool $enabled = true;
    public string $variantMode = self::VARIANT_MODE_VARIANT;
    public int $interval = 0;
    public bool $regenerateOnSave = false;
    public ?DateTime $nextGenerateAt = null;
    public ?DateTime $lastGeneratedAt = null;
    public ?int $sortOrder = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** @var Mapping[]|null */
    private ?array $_mappings = null;
    private array $_filters = [];
    private array $_options = [];
    private array $_delivery = [];
    private ?ProductCondition $_productCondition = null;

    public static function variantModes(): array
    {
        return [
            self::VARIANT_MODE_VARIANT => Craft::t('eat', 'One row per variant (item_group_id links them)'),
            self::VARIANT_MODE_DEFAULT => Craft::t('eat', 'One row per product, using its default variant'),
            self::VARIANT_MODE_PRODUCT => Craft::t('eat', 'One row per product, cheapest variant’s price'),
        ];
    }

    public static function formats(): array
    {
        return [
            'rss' => 'RSS 2.0 (XML)',
            'xml' => 'XML',
            'csv' => 'CSV',
            'tsv' => 'TSV',
            'txt' => 'TXT (tab separated)',
            'json' => 'JSON',
        ];
    }

    public static function intervals(): array
    {
        return [
            0 => Craft::t('eat', 'Manually'),
            3600 => Craft::t('eat', 'Hourly'),
            21600 => Craft::t('eat', 'Every 6 hours'),
            43200 => Craft::t('eat', 'Every 12 hours'),
            86400 => Craft::t('eat', 'Daily'),
            604800 => Craft::t('eat', 'Weekly'),
        ];
    }

    public static function defaultFilters(): array
    {
        return [
            'productTypes' => [],
            'statuses' => ['live'],
            'inStockOnly' => false,
            'requireImage' => false,
            'requirePrice' => true,
            'minPrice' => null,
            'maxPrice' => null,
            'includeDisabledVariants' => false,
            'excludeSkus' => [],
            'excludeIds' => [],
            'limit' => null,
        ];
    }

    public static function defaultOptions(): array
    {
        return [
            'currency' => null,
            'priceMultiplier' => null,
            'skipIncomplete' => true,
            'includeHeader' => true,
            'delimiter' => ',',
            'enclosure' => '"',
            'compress' => false,
            'batchSize' => 100,
            'imageTransform' => null,
            'utmSource' => null,
            'utmMedium' => null,
            'utmCampaign' => null,
            'utmTerm' => null,
            'utmContent' => null,
            'xmlRoot' => null,
            'xmlItem' => null,
            'jsonWrapper' => 'products',
            'feedTitle' => null,
            'feedLink' => null,
            'feedDescription' => null,
            'liveRoute' => false,
        ];
    }

    public static function defaultDelivery(): array
    {
        return [
            'mode' => 'file',
            'path' => null,
            'volumeId' => null,
            'volumePath' => '',
            'ftp' => ['host' => null, 'port' => 21, 'username' => null, 'password' => null, 'path' => null, 'passive' => true, 'secure' => false],
            'sftp' => ['host' => null, 'port' => 22, 'username' => null, 'password' => null, 'privateKey' => null, 'path' => null],
            'merchant' => ['merchantId' => null, 'serviceAccount' => null, 'targetCountry' => 'US', 'contentLanguage' => 'en', 'channel' => 'online'],
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'channel', 'format'], 'required'],
            [['handle'], 'validateHandle'],
            [['handle'], UniqueValidator::class, 'targetClass' => FeedRecord::class, 'targetAttribute' => 'handle'],
            [['interval'], 'integer', 'min' => 0],
            [['variantMode'], 'in', 'range' => array_keys(self::variantModes())],
            [['format'], 'in', 'range' => array_keys(self::formats())],
            [['channel'], 'validateChannel'],
            [['format'], 'validateFormat'],
        ];
    }

    /**
     * A feed handle is not a Craft handle: it is a file name and a URL segment, so `google-shopping`
     * has to be allowed — Craft's own HandleValidator would insist on `googleShopping.xml`.
     */
    public function validateHandle(): void
    {
        if (!preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', (string)$this->handle)) {
            $this->addError('handle', Craft::t('eat', 'Handles can contain lowercase letters, numbers, hyphens and underscores.'));
        }
    }

    public function validateChannel(): void
    {
        if (Registry::get($this->channel) === null) {
            $this->addError('channel', Craft::t('eat', 'No such channel.'));
        }
    }

    public function validateFormat(): void
    {
        $channel = $this->getChannelDefinition();

        if ($channel !== null && !$channel->supportsFormat($this->format)) {
            $this->addError('format', Craft::t('eat', '{channel} does not accept {format} feeds.', [
                'channel' => $channel->name,
                'format' => strtoupper($this->format),
            ]));
        }
    }

    public function getChannelDefinition(): ?Channel
    {
        return Registry::get($this->channel);
    }

    // Mappings
    // -------------------------------------------------------------------------

    /**
     * @return Mapping[]
     */
    public function getMappings(): array
    {
        if ($this->_mappings === null) {
            $channel = $this->getChannelDefinition();
            $this->_mappings = $channel ? $channel->defaultMappings() : [];
        }

        return $this->_mappings;
    }

    public function setMappings(mixed $value): void
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : (Json::decodeIfJson($value) ?: []);
        }

        if (!is_array($value)) {
            return;
        }

        $mappings = [];

        foreach ($value as $row) {
            if ($row instanceof Mapping) {
                $mappings[] = $row;
                continue;
            }

            if (!is_array($row) || !isset($row['attribute'])) {
                continue;
            }

            $modifiers = $row['modifiers'] ?? [];

            if (is_string($modifiers)) {
                $modifiers = $modifiers === '' ? [] : (Json::decodeIfJson($modifiers) ?: []);
            }

            $mappings[] = new Mapping([
                'attribute' => (string)$row['attribute'],
                'source' => (string)($row['source'] ?? Mapping::SOURCE_NONE),
                'value' => isset($row['value']) && $row['value'] !== '' ? (string)$row['value'] : null,
                'enabled' => (bool)($row['enabled'] ?? true),
                'modifiers' => is_array($modifiers) ? array_values($modifiers) : [],
            ]);
        }

        $this->_mappings = $mappings;
    }

    /**
     * The mappings that will actually produce a value, in order.
     *
     * @return Mapping[]
     */
    public function getActiveMappings(): array
    {
        return array_values(array_filter($this->getMappings(), static fn(Mapping $m) => $m->isActive()));
    }

    public function getMapping(string $attribute): ?Mapping
    {
        foreach ($this->getMappings() as $mapping) {
            if ($mapping->attribute === $attribute) {
                return $mapping;
            }
        }

        return null;
    }

    // JSON blobs
    // -------------------------------------------------------------------------

    public function setFilters(mixed $value): void
    {
        $this->_filters = $this->_decode($value);
    }

    public function getFilters(): array
    {
        return array_merge(self::defaultFilters(), $this->_filters);
    }

    public function getFilter(string $key): mixed
    {
        return $this->getFilters()[$key] ?? null;
    }

    public function setOptions(mixed $value): void
    {
        $this->_options = $this->_decode($value);
    }

    public function getOptions(): array
    {
        return array_merge(self::defaultOptions(), $this->_options);
    }

    public function getOption(string $key): mixed
    {
        return $this->getOptions()[$key] ?? null;
    }

    public function setDelivery(mixed $value): void
    {
        $this->_delivery = $this->_decode($value);
    }

    public function getDelivery(): array
    {
        return ArrayHelper::merge(self::defaultDelivery(), $this->_delivery);
    }

    public function getDeliveryMode(): string
    {
        return (string)($this->getDelivery()['mode'] ?? 'file');
    }

    public function setProductCondition(mixed $condition): void
    {
        if (is_string($condition)) {
            $condition = $condition === '' ? [] : (Json::decodeIfJson($condition) ?: []);
        }

        if ($condition instanceof ProductCondition) {
            $this->_productCondition = $condition;
            return;
        }

        if (!is_array($condition)) {
            $condition = [];
        }

        $condition['class'] = ProductCondition::class;

        /** @var ProductCondition $instance */
        $instance = Craft::$app->getConditions()->createCondition($condition);
        $instance->forProjectConfig = false;
        $this->_productCondition = $instance;
    }

    public function getProductCondition(): ProductCondition
    {
        if ($this->_productCondition === null) {
            $this->setProductCondition([]);
        }

        /** @var ProductCondition $condition */
        $condition = $this->_productCondition;

        // The builder needs both, and needs them every time it is rendered: `mainTag` keeps it out
        // of a nested <form>, `name` is what the whole thing posts under.
        $condition->mainTag = 'div';
        $condition->name = 'productCondition';

        return $condition;
    }

    public function hasProductCondition(): bool
    {
        return (bool)$this->getProductCondition()->getConditionRules();
    }

    // Output location
    // -------------------------------------------------------------------------

    public function getExtension(): string
    {
        $extension = match ($this->format) {
            'rss', 'xml' => 'xml',
            'csv' => 'csv',
            'tsv' => 'tsv',
            'txt' => 'txt',
            'json' => 'json',
            default => 'txt',
        };

        return $this->getOption('compress') ? $extension . '.gz' : $extension;
    }

    public function getFileName(): string
    {
        $path = $this->getDelivery()['path'] ?? null;

        if ($path) {
            return basename((string)$path);
        }

        return ($this->handle ?: 'feed') . '.' . $this->getExtension();
    }

    public function getMimeType(): string
    {
        if ($this->getOption('compress')) {
            return 'application/gzip';
        }

        return match ($this->format) {
            'rss', 'xml' => 'application/xml',
            'csv' => 'text/csv',
            'json' => 'application/json',
            default => 'text/plain',
        };
    }

    /**
     * Where the generated file lives, for file delivery.
     */
    public function getFilePath(): string
    {
        $directory = Plugin::getInstance()->getSettings()->getFeedDirectory();

        return $directory . DIRECTORY_SEPARATOR . $this->getFileName();
    }

    /**
     * The URL a merchant pastes into the channel.
     */
    public function getUrl(): ?string
    {
        if ($this->getOption('liveRoute')) {
            return UrlHelper::siteUrl('eat/feed/' . $this->handle, null, null, $this->siteId ?: null);
        }

        if ($this->getDeliveryMode() === 'volume') {
            return Plugin::getInstance()->getDelivery()->getVolumeUrl($this);
        }

        if ($this->getDeliveryMode() !== 'file') {
            return null;
        }

        $base = rtrim(Plugin::getInstance()->getSettings()->getFeedUrlBase(), '/');

        return $base . '/' . $this->getFileName();
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('eat/feeds/' . ($this->id ?: 'new'));
    }

    public function getSite(): ?\craft\models\Site
    {
        if ($this->siteId) {
            return Craft::$app->getSites()->getSiteById($this->siteId);
        }

        return Craft::$app->getSites()->getPrimarySite();
    }

    public function getIntervalLabel(): string
    {
        return self::intervals()[$this->interval] ?? Craft::t('eat', 'Every {n} seconds', ['n' => $this->interval]);
    }

    public function getIsDue(): bool
    {
        if (!$this->enabled || $this->interval <= 0) {
            return false;
        }

        return $this->nextGenerateAt === null || $this->nextGenerateAt->getTimestamp() <= time();
    }

    public function __toString(): string
    {
        return (string)($this->name ?: $this->handle ?: 'Feed');
    }

    private function _decode(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : (Json::decodeIfJson($value) ?: []);
        }

        return is_array($value) ? $value : [];
    }
}
