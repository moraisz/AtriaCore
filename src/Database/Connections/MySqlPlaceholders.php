<?php

declare(strict_types=1);

namespace Atria\Database\Connections;

use Atria\Database\Exceptions\QueryException;

/**
 * Replaces positional `?` placeholders with escaped literals, for the
 * asynchronous mysqli path, which only accepts plain-text queries.
 *
 * Question marks inside string literals, quoted identifiers and comments are
 * left alone. Strings are quoted with the connection's escape function, which
 * must use the connection charset (set with mysqli::set_charset()).
 *
 * @internal
 */
final class MySqlPlaceholders
{
    /**
     * @param array<int, mixed> $bindings
     * @param callable(string): string $escape
     */
    public static function interpolate(string $sql, array $bindings, callable $escape): string
    {
        $bindings = array_values($bindings);

        if (!str_contains($sql, '?')) {
            if ($bindings !== []) {
                throw self::countMismatch(0, count($bindings));
            }

            return $sql;
        }

        $length = strlen($sql);
        $out = '';
        $index = 0;
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            $end = match (true) {
                $char === "'", $char === '"' => self::quotedEnd($sql, $i, $char, true),
                $char === '`' => self::quotedEnd($sql, $i, '`', false),
                $char === '#' => self::lineCommentEnd($sql, $i),
                // MySQL only treats `--` as a comment when whitespace follows.
                $char === '-' && $next === '-' && ctype_space($sql[$i + 2] ?? ' ') => self::lineCommentEnd($sql, $i),
                $char === '/' && $next === '*' => self::blockCommentEnd($sql, $i),
                default => null,
            };

            if ($end !== null) {
                $out .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($char === '?') {
                if (!array_key_exists($index, $bindings)) {
                    throw new QueryException(
                        'The query has more placeholders than the ' . count($bindings) . ' bindings given.',
                        'HY093',
                    );
                }

                $out .= self::literal($bindings[$index++], $escape);
                $i++;
                continue;
            }

            $out .= $char;
            $i++;
        }

        if ($index !== count($bindings)) {
            throw self::countMismatch($index, count($bindings));
        }

        return $out;
    }

    /**
     * @param callable(string): string $escape
     */
    private static function literal(mixed $value, callable $escape): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => self::floatLiteral($value),
            is_string($value), $value instanceof \Stringable => "'" . $escape((string) $value) . "'",
            default => throw new \InvalidArgumentException('Unsupported binding type: ' . get_debug_type($value)),
        };
    }

    private static function floatLiteral(float $value): string
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException('MySQL cannot store INF or NAN.');
        }

        // Locale-independent and exact enough to round-trip a double.
        $literal = sprintf('%.17g', $value);

        return str_contains($literal, '.') || str_contains($literal, 'e') ? $literal : $literal . '.0';
    }

    /**
     * Offset right after the closing quote; handles backslash escapes (when
     * enabled) and doubled quotes.
     */
    private static function quotedEnd(string $sql, int $start, string $quote, bool $backslashEscapes): int
    {
        $length = strlen($sql);
        $i = $start + 1;

        while ($i < $length) {
            if ($backslashEscapes && $sql[$i] === '\\') {
                $i += 2;
                continue;
            }

            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;
                    continue;
                }

                return $i + 1;
            }

            $i++;
        }

        return $length;
    }

    private static function lineCommentEnd(string $sql, int $start): int
    {
        $newline = strpos($sql, "\n", $start);

        return $newline === false ? strlen($sql) : $newline + 1;
    }

    private static function blockCommentEnd(string $sql, int $start): int
    {
        $close = strpos($sql, '*/', $start + 2);

        return $close === false ? strlen($sql) : $close + 2;
    }

    private static function countMismatch(int $placeholders, int $bindings): QueryException
    {
        return new QueryException("The query has {$placeholders} placeholders but {$bindings} bindings were given.", 'HY093');
    }
}
