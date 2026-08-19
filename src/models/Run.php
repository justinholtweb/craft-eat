<?php

namespace justinholtweb\eat\models;

use craft\base\Model;
use craft\helpers\Json;
use DateTime;

/**
 * One generation attempt.
 */
class Run extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_ERROR = 'error';

    public ?int $id = null;
    public ?int $feedId = null;
    public string $status = self::STATUS_SUCCESS;
    public string $trigger = 'manual';
    public int $itemCount = 0;
    public int $skippedCount = 0;
    public int $byteSize = 0;
    public int $durationMs = 0;
    public ?string $url = null;
    public ?string $message = null;
    public ?DateTime $dateCreated = null;
    public ?string $uid = null;

    /** @var array<string, mixed> */
    private array $_details = [];

    public function setDetails(mixed $value): void
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : (Json::decodeIfJson($value) ?: []);
        }

        $this->_details = is_array($value) ? $value : [];
    }

    public function getDetails(): array
    {
        return $this->_details;
    }

    public function getIsError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function getSizeLabel(): string
    {
        $bytes = $this->byteSize;

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }
}
