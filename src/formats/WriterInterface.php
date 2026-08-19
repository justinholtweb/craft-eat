<?php

namespace justinholtweb\eat\formats;

/**
 * A feed writer streams rows to an open file handle. Nothing accumulates in memory: a 200,000
 * product catalogue costs the same as a 20 product one.
 */
interface WriterInterface
{
    public function open(): void;

    /**
     * @param array<string, string|array> $row attribute key => value
     */
    public function write(array $row): void;

    public function close(): void;
}
