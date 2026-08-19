<?php

namespace justinholtweb\eat\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\helpers\StringHelper;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Money\Money;

/**
 * Turning whatever Craft hands back into something a merchant's parser will accept, and the output
 * modifiers that run afterwards.
 */
abstract class Value
{
    /**
     * @return array<string, string> modifier type => label
     */
    public static function modifiers(): array
    {
        return [
            'strip_tags' => 'Strip HTML',
            'decode_entities' => 'Decode HTML entities',
            'collapse_whitespace' => 'Collapse whitespace',
            'truncate' => 'Truncate to length',
            'prefix' => 'Add a prefix',
            'suffix' => 'Add a suffix',
            'replace' => 'Find and replace',
            'regex_replace' => 'Regex replace',
            'upper' => 'UPPERCASE',
            'lower' => 'lowercase',
            'ucfirst' => 'Capitalise first letter',
            'number' => 'Format as a number',
            'multiply' => 'Multiply',
            'map' => 'Map values',
            'default' => 'Fall back when empty',
        ];
    }

    /**
     * Flatten a value into feed output. Arrays survive as arrays — an XML writer repeats the
     * element, a CSV writer joins them — everything else becomes a string.
     */
    public static function stringify(mixed $value): string|array
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        if ($value instanceof Money) {
            return (string)((int)$value->getAmount() / (10 ** 2));
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        if ($value instanceof Asset) {
            return (string)$value->getUrl();
        }

        if ($value instanceof ElementInterface) {
            // Not `getTitle()` — that is not an element method; `title` is a property, and elements
            // without one (assets, users) still answer `getUiLabel()`.
            return (string)($value->title ?? $value->getUiLabel());
        }

        if ($value instanceof ElementQuery) {
            // Element queries are only resolved deliberately: `craft\base\Model` is Traversable, so
            // anything that treats an element as an iterable silently explodes it into attributes.
            return self::stringify($value->all());
        }

        if ($value instanceof Collection) {
            return self::stringify($value->all());
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $item) {
                $flat = self::stringify($item);

                foreach ((array)$flat as $one) {
                    if ($one !== '') {
                        $out[] = $one;
                    }
                }
            }

            return $out;
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }

            if (method_exists($value, 'getUrl')) {
                return (string)$value->getUrl();
            }
        }

        return '';
    }

    /**
     * Walk a dotted path into a value: `heroImage.0.url`, `author.name`, `size.width`.
     */
    public static function traverse(mixed $value, array $path): mixed
    {
        foreach ($path as $step) {
            if ($value === null) {
                return null;
            }

            if ($value instanceof ElementQuery) {
                $value = $value->all();
            } elseif ($value instanceof Collection) {
                $value = $value->all();
            }

            if (is_array($value)) {
                if (is_numeric($step)) {
                    $value = $value[(int)$step] ?? null;
                    continue;
                }

                $value = $value[$step] ?? null;
                continue;
            }

            if (is_object($value)) {
                $getter = 'get' . ucfirst($step);

                if (method_exists($value, $getter)) {
                    $value = $value->$getter();
                    continue;
                }

                if (method_exists($value, $step)) {
                    $value = $value->$step();
                    continue;
                }

                if (isset($value->$step)) {
                    $value = $value->$step;
                    continue;
                }

                if ($value instanceof ElementInterface) {
                    try {
                        $value = $value->getFieldValue($step);
                        continue;
                    } catch (\Throwable) {
                        return null;
                    }
                }
            }

            return null;
        }

        return $value;
    }

    /**
     * Apply a modifier chain, in order.
     *
     * @param array<int, array<string, mixed>> $modifiers
     */
    public static function applyModifiers(string|array $value, array $modifiers): string|array
    {
        foreach ($modifiers as $modifier) {
            $type = (string)($modifier['type'] ?? '');

            if ($type === '') {
                continue;
            }

            if (is_array($value)) {
                // `default` is about the value as a whole; everything else is per item.
                if ($type === 'default') {
                    if ($value === []) {
                        $value = self::applyModifier('', $modifier);
                    }

                    continue;
                }

                $value = array_values(array_filter(
                    array_map(fn($item) => self::applyModifier((string)$item, $modifier), $value),
                    static fn($item) => $item !== ''
                ));

                continue;
            }

            $value = self::applyModifier($value, $modifier);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $modifier
     */
    public static function applyModifier(string $value, array $modifier): string
    {
        $type = (string)($modifier['type'] ?? '');
        $a = (string)($modifier['a'] ?? '');
        $b = (string)($modifier['b'] ?? '');

        return match ($type) {
            'strip_tags' => trim(strip_tags($value)),
            'decode_entities' => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'collapse_whitespace' => trim((string)preg_replace('/\s+/u', ' ', $value)),
            'truncate' => self::truncate($value, (int)$a, $b),
            'prefix' => $value === '' ? '' : $a . $value,
            'suffix' => $value === '' ? '' : $value . $a,
            'replace' => $a === '' ? $value : str_replace($a, $b, $value),
            'regex_replace' => self::regexReplace($value, $a, $b),
            'upper' => StringHelper::toUpperCase($value),
            'lower' => StringHelper::toLowerCase($value),
            'ucfirst' => StringHelper::upperCaseFirst($value),
            'number' => $value === '' ? '' : number_format((float)$value, (int)$a, '.', ''),
            'multiply' => $value === '' || !is_numeric($a) ? $value : (string)round((float)$value * (float)$a, 4),
            'map' => self::map($value, $a),
            'default' => $value === '' ? $a : $value,
            default => $value,
        };
    }

    public static function truncate(string $value, int $length, string $suffix = ''): string
    {
        if ($length <= 0 || mb_strlen($value) <= $length) {
            return $value;
        }

        $keep = max(0, $length - mb_strlen($suffix));

        return rtrim(mb_substr($value, 0, $keep)) . $suffix;
    }

    /**
     * A merchant's regex is never trusted with the whole request: a bad pattern warns and the value
     * passes through unchanged rather than emptying a column in every row.
     */
    public static function regexReplace(string $value, string $pattern, string $replacement): string
    {
        if ($pattern === '') {
            return $value;
        }

        if (!str_starts_with($pattern, '/') && !str_starts_with($pattern, '#') && !str_starts_with($pattern, '~')) {
            $pattern = '/' . str_replace('/', '\/', $pattern) . '/';
        }

        $result = @preg_replace($pattern, $replacement, $value);

        if ($result === null) {
            Craft::warning("Invalid feed regex modifier: $pattern", 'eat');
            return $value;
        }

        return $result;
    }

    /**
     * `red=Rot|blue=Blau` — the escape hatch for a channel that insists on its own vocabulary.
     */
    public static function map(string $value, string $pairs): string
    {
        if ($pairs === '') {
            return $value;
        }

        foreach (preg_split('/[\n|]+/', $pairs) ?: [] as $pair) {
            $parts = explode('=', $pair, 2);

            if (count($parts) !== 2) {
                continue;
            }

            if (trim($parts[0]) === $value) {
                return trim($parts[1]);
            }
        }

        return $value;
    }

    /**
     * Append query parameters (UTM tags, affiliate ids) without clobbering the ones already there.
     *
     * @param array<string, string|null> $params
     */
    public static function appendQuery(string $url, array $params): string
    {
        $params = array_filter($params, static fn($value) => $value !== null && $value !== '');

        if ($url === '' || $params === []) {
            return $url;
        }

        $fragment = '';

        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $existing = [];

        if (($mark = strpos($url, '?')) !== false) {
            parse_str(substr($url, $mark + 1), $existing);
            $url = substr($url, 0, $mark);
        }

        $query = http_build_query(array_merge($params, $existing));

        return $url . ($query !== '' ? '?' . $query : '') . $fragment;
    }
}
