<?php

namespace justinholtweb\eat\helpers;

/**
 * The modifier chain, as a merchant types it.
 *
 * `strip_tags | truncate:150:… | replace:Ltd:Limited` — one line in the mapping table rather than a
 * nested editor, because a mapping table with 40 rows of collapsible sub-forms is unusable.
 */
abstract class Modifiers
{
    /**
     * @return array<int, array<string, string>>
     */
    public static function parse(?string $string): array
    {
        $string = trim((string)$string);

        if ($string === '') {
            return [];
        }

        $modifiers = [];

        foreach (explode('|', $string) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $segments = explode(':', $part, 3);
            $type = trim($segments[0]);

            if ($type === '' || !isset(Value::modifiers()[$type])) {
                continue;
            }

            $modifiers[] = array_filter([
                'type' => $type,
                'a' => isset($segments[1]) ? trim($segments[1]) : '',
                'b' => isset($segments[2]) ? $segments[2] : '',
            ], static fn($value, $key) => $key === 'type' || $value !== '', ARRAY_FILTER_USE_BOTH);
        }

        return $modifiers;
    }

    /**
     * @param array<int, array<string, mixed>> $modifiers
     */
    public static function toString(array $modifiers): string
    {
        $parts = [];

        foreach ($modifiers as $modifier) {
            $type = (string)($modifier['type'] ?? '');

            if ($type === '') {
                continue;
            }

            $a = (string)($modifier['a'] ?? '');
            $b = (string)($modifier['b'] ?? '');

            if ($b !== '') {
                $parts[] = "$type:$a:$b";
            } elseif ($a !== '') {
                $parts[] = "$type:$a";
            } else {
                $parts[] = $type;
            }
        }

        return implode(' | ', $parts);
    }
}
