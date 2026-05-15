<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\Security;

final class CanonicalQuery
{
    public static function normalize(array|string|null $query): string
    {
        $pairs = is_array($query) ? self::pairsFromArray($query) : self::pairsFromString((string) $query);
        usort($pairs, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(
            fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]),
            $pairs,
        ));
    }

    private static function pairsFromString(string $query): array
    {
        $query = ltrim($query, '?');
        if ($query === '') {
            return [];
        }

        $pairs = [];
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[] = [rawurldecode($key), rawurldecode($value)];
        }

        return $pairs;
    }

    private static function pairsFromArray(array $query, string $prefix = ''): array
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";
            if (is_array($value)) {
                array_push($pairs, ...self::pairsFromArray($value, $name));

                continue;
            }
            if ($value === null) {
                continue;
            }
            $pairs[] = [$name, (string) $value];
        }

        return $pairs;
    }
}
