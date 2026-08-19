<?php

namespace justinholtweb\eat\formats;

/**
 * A JSON document, streamed one product at a time.
 */
class JsonWriter extends BaseWriter
{
    private bool $_wrapped = false;

    public function open(): void
    {
        $wrapper = (string)($this->options['jsonWrapper'] ?? 'products');
        $this->_wrapped = $wrapper !== '';

        if ($this->_wrapped) {
            $this->put('{' . json_encode($wrapper) . ':[');
            return;
        }

        $this->put('[');
    }

    public function write(array $row): void
    {
        $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded === false) {
            return;
        }

        $this->put(($this->written > 0 ? ',' : '') . $encoded);
        $this->written++;
    }

    public function close(): void
    {
        $this->put($this->_wrapped ? ']}' : ']');
    }
}
