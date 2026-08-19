<?php

namespace justinholtweb\eat\formats;

use craft\helpers\UrlHelper;

/**
 * RSS 2.0 (what Google, Meta, Pinterest, Microsoft and TikTok all read) and plain XML documents.
 */
class XmlWriter extends BaseWriter
{
    public function open(): void
    {
        $this->put('<?xml version="1.0" encoding="UTF-8"?>' . "\n");

        $namespaces = '';

        foreach ($this->channel?->namespaces ?? [] as $prefix => $uri) {
            $namespaces .= sprintf(' xmlns:%s="%s"', $prefix, $uri);
        }

        if ($this->isRss()) {
            $this->put('<rss version="2.0"' . $namespaces . ">\n<channel>\n");
            $this->put('<title>' . $this->escape($this->feedTitle()) . "</title>\n");
            $this->put('<link>' . $this->escape($this->feedLink()) . "</link>\n");
            $this->put('<description>' . $this->escape($this->feedDescription()) . "</description>\n");

            return;
        }

        $this->put('<' . $this->rootName() . $namespaces . ">\n");
    }

    public function write(array $row): void
    {
        $item = $this->itemName();
        $this->put("  <$item>\n");

        foreach ($row as $key => $value) {
            foreach ((array)$value as $one) {
                $one = (string)$one;

                if ($one === '') {
                    continue;
                }

                $name = $this->elementName($key);
                $this->put('    <' . $name . '>' . $this->text($one) . '</' . $name . ">\n");
            }
        }

        $this->put("  </$item>\n");
        $this->written++;
    }

    public function close(): void
    {
        if ($this->isRss()) {
            $this->put("</channel>\n</rss>\n");
            return;
        }

        $this->put('</' . $this->rootName() . ">\n");
    }

    public function isRss(): bool
    {
        return $this->feed->format === 'rss' && ($this->channel?->rss ?? false);
    }

    /**
     * `g:image_link` for a namespaced attribute, `title` for one of RSS's own three, and anything
     * a merchant typed on a custom feed sanitised into a legal element name.
     */
    public function elementName(string $key): string
    {
        $prefix = $this->channel?->prefix;
        $namespaced = $prefix !== null && ($this->channel?->isNamespaced($key) ?? false);
        $name = $this->sanitiseName($key);

        return $namespaced ? $prefix . ':' . $name : $name;
    }

    public function sanitiseName(string $key): string
    {
        $name = (string)preg_replace('/[^A-Za-z0-9_.\-]+/', '_', trim($key));
        $name = trim($name, '_');

        if ($name === '' || !preg_match('/^[A-Za-z_]/', $name)) {
            $name = 'attr_' . $name;
        }

        return $name;
    }

    /**
     * Markup goes in CDATA, everything else is escaped. A value carrying `]]>` is split across two
     * sections — the one thing that can end a CDATA block early and corrupt the whole document.
     */
    public function text(string $value): string
    {
        if (!str_contains($value, '<') && !str_contains($value, '&')) {
            return $this->escape($value);
        }

        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function rootName(): string
    {
        return $this->sanitiseName((string)($this->options['xmlRoot'] ?: ($this->channel?->xmlRoot ?? 'products')));
    }

    private function itemName(): string
    {
        return $this->sanitiseName((string)($this->options['xmlItem'] ?: ($this->channel?->xmlItem ?? 'product')));
    }

    private function feedTitle(): string
    {
        return (string)($this->options['feedTitle'] ?: ($this->feed->name ?? 'Product feed'));
    }

    private function feedLink(): string
    {
        return (string)($this->options['feedLink'] ?: UrlHelper::siteUrl('', null, null, $this->feed->siteId ?: null));
    }

    private function feedDescription(): string
    {
        return (string)($this->options['feedDescription'] ?: ($this->feed->name . ' product feed'));
    }
}
