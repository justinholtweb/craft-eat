<?php

namespace justinholtweb\eat\formats;

/**
 * CSV, TSV and the tab-separated `.txt` most comparison engines ask for.
 */
class DelimitedWriter extends BaseWriter
{
    public function open(): void
    {
        if (!($this->options['includeHeader'] ?? true)) {
            return;
        }

        $this->row($this->columns);
    }

    public function write(array $row): void
    {
        $cells = [];

        foreach ($this->columns as $column) {
            $cells[] = $this->flatten($row[$column] ?? '');
        }

        $this->row($cells);
        $this->written++;
    }

    public function close(): void
    {
    }

    public function delimiter(): string
    {
        if ($this->feed->format === 'csv') {
            $delimiter = (string)($this->options['delimiter'] ?? ',');

            return $delimiter === '' ? ',' : substr($delimiter, 0, 1);
        }

        return "\t";
    }

    /**
     * @param string[] $cells
     */
    private function row(array $cells): void
    {
        $delimiter = $this->delimiter();

        // Tab-separated feeds are read line by line by most merchants, and a quoted cell with an
        // embedded newline is what breaks them. Flatten the whitespace instead of quoting it.
        if ($delimiter === "\t") {
            $clean = array_map(
                static fn(string $cell) => (string)preg_replace('/[\t\r\n]+/u', ' ', $cell),
                $cells
            );

            $this->put(implode("\t", $clean) . "\n");

            return;
        }

        $enclosure = (string)($this->options['enclosure'] ?? '"');
        $enclosure = $enclosure === '' ? '"' : substr($enclosure, 0, 1);

        fputcsv($this->handle, $cells, $delimiter, $enclosure, '', "\n");
    }
}
