<?php

namespace justinholtweb\eat\formats;

use justinholtweb\eat\models\Channel;
use justinholtweb\eat\models\Feed;

abstract class Writers
{
    /**
     * @param resource $handle
     * @param string[] $columns
     */
    public static function create($handle, Feed $feed, ?Channel $channel, array $columns): WriterInterface
    {
        return match ($feed->format) {
            'rss', 'xml' => new XmlWriter($handle, $feed, $channel, $columns),
            'json' => new JsonWriter($handle, $feed, $channel, $columns),
            default => new DelimitedWriter($handle, $feed, $channel, $columns),
        };
    }
}
