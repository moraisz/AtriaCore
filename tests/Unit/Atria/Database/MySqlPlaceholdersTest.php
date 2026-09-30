<?php

declare(strict_types=1);

use Atria\Database\Connections\MySqlPlaceholders;
use Atria\Database\Exceptions\QueryException;

/**
 * Mimics mysqli::real_escape_string for the characters it escapes.
 */
function fakeMySqlEscape(string $value): string
{
    return strtr($value, ["\\" => "\\\\", "'" => "\\'", '"' => '\\"', "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z']);
}

test('interpolates bindings as typed literals', function (string $sql, array $bindings, string $expected) {
    expect(MySqlPlaceholders::interpolate($sql, $bindings, fakeMySqlEscape(...)))->toBe($expected);
})->with([
    'types' => ['SELECT ?, ?, ?, ?, ?', [null, true, false, 42, 'x'], "SELECT NULL, 1, 0, 42, 'x'"],
    'floats keep a decimal point' => ['SELECT ?, ?', [1.5, 2.0], 'SELECT 1.5, 2.0'],
    'no placeholders' => ['SELECT 1', [], 'SELECT 1'],
    'single-quoted string' => ["SELECT '?', ?", [1], "SELECT '?', 1"],
    'backslash escape in string' => ["SELECT 'a\\' ?', ?", [1], "SELECT 'a\\' ?', 1"],
    'doubled quote in string' => ["SELECT 'it''s ?', ?", [1], "SELECT 'it''s ?', 1"],
    'double-quoted string' => ['SELECT "?", ?', [1], 'SELECT "?", 1'],
    'backtick identifier' => ['SELECT `col?` FROM t WHERE x = ?', [1], 'SELECT `col?` FROM t WHERE x = 1'],
    'hash comment' => ["SELECT ? # why?\nFROM t", [1], "SELECT 1 # why?\nFROM t"],
    'dash comment' => ["SELECT ? -- why?\nFROM t", [1], "SELECT 1 -- why?\nFROM t"],
    'block comment' => ['SELECT /* ? */ ?', [1], 'SELECT /* ? */ 1'],
]);

test('escapes strings so they cannot break out of the literal', function () {
    $sql = MySqlPlaceholders::interpolate('SELECT * FROM users WHERE name = ?', ["x' OR '1'='1"], fakeMySqlEscape(...));

    expect($sql)->toBe("SELECT * FROM users WHERE name = 'x\\' OR \\'1\\'=\\'1'");
});

test('rejects a placeholder and binding count mismatch', function (string $sql, array $bindings) {
    expect(fn() => MySqlPlaceholders::interpolate($sql, $bindings, fakeMySqlEscape(...)))
        ->toThrow(QueryException::class, 'placeholders');
})->with([
    'too few bindings' => ['SELECT ?, ?', [1]],
    'too many bindings' => ['SELECT ?', [1, 2]],
    'bindings without placeholders' => ['SELECT 1', [1]],
]);

test('rejects values MySQL cannot store', function () {
    expect(fn() => MySqlPlaceholders::interpolate('SELECT ?', [INF], fakeMySqlEscape(...)))
        ->toThrow(InvalidArgumentException::class);
});
