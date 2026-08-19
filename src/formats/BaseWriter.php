<?php

namespace justinholtweb\eat\formats;

use justinholtweb\eat\models\Channel;
use justinholtweb\eat\models\Feed;

abstract class BaseWriter implements WriterInterface
{
    /** @var resource */
    protected $handle;

    protected Feed $feed;
    protected ?Channel $channel;

    /** @var string[] Attribute keys, in mapping order. */
    protected array $columns;

    protected array $options;
    protected int $written = 0;

    /**
     * @param resource $handle
     * @param string[] $columns
     */
    public function __construct($handle, Feed $feed, ?Channel $channel, array $columns)
    {
        $this->handle = $handle;
        $this->feed = $feed;
        $this->channel = $channel;
        $this->columns = $columns;
        $this->options = $feed->getOptions();
    }

    public function getWritten(): int
    {
        return $this->written;
    }

    protected function put(string $string): void
    {
        fwrite($this->handle, $string);
    }

    /**
     * Join a repeated value for formats with one cell per attribute.
     */
    protected function flatten(string|array $value, string $glue = ','): string
    {
        return is_array($value) ? implode($glue, $value) : $value;
    }
}
