<?php

declare(strict_types=1);

namespace Atria\Database\Connections;

/**
 * Rewrites positional `?` placeholders into PostgreSQL's `$1..$n`.
 *
 * Question marks inside string literals, quoted identifiers, dollar-quoted
 * strings and comments are left alone, and `??` becomes a literal `?` (the
 * escape PDO used, for operators such as jsonb `?`).
 *
 * @internal
 */
final class PgSqlPlaceholders
{
    public static function convert(string $sql): string
    {
        if (!str_contains($sql, '?')) {
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
                $char === "'" => self::quotedEnd($sql, $i, "'", self::backslashEscapes($sql, $i)),
                $char === '"' => self::quotedEnd($sql, $i, '"', false),
                $char === '-' && $next === '-' => self::lineCommentEnd($sql, $i),
                $char === '/' && $next === '*' => self::blockCommentEnd($sql, $i),
                $char === '$' => self::dollarQuotedEnd($sql, $i),
                default => null,
            };

            if ($end !== null) {
                $out .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($char === '?') {
                if ($next === '?') {
                    $out .= '?';
                    $i += 2;
                    continue;
                }

                $out .= '$' . ++$index;
                $i++;
                continue;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }

    /**
     * E'...' strings treat backslash as an escape character.
     */
    private static function backslashEscapes(string $sql, int $quote): bool
    {
        if ($quote === 0 || !in_array($sql[$quote - 1], ['E', 'e'], true)) {
            return false;
        }

        return $quote === 1 || !ctype_alnum($sql[$quote - 2]) && $sql[$quote - 2] !== '_';
    }

    /**
     * Offset right after the closing quote; a doubled quote is an escaped quote.
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

    /**
     * Offset after a $tag$...$tag$ string, or null when `$` does not open one
     * (e.g. an existing `$1` placeholder).
     */
    private static function dollarQuotedEnd(string $sql, int $start): ?int
    {
        // `$` inside an identifier (e.g. `price$usd`) does not open a string.
        if ($start > 0 && (ctype_alnum($sql[$start - 1]) || $sql[$start - 1] === '_')) {
            return null;
        }

        if (preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $match, 0, $start) !== 1) {
            return null;
        }

        $tag = $match[0];
        $close = strpos($sql, $tag, $start + strlen($tag));

        return $close === false ? strlen($sql) : $close + strlen($tag);
    }
}
