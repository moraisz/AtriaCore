<?php

declare(strict_types=1);

use Atria\Database\Connections\PgSqlPlaceholders;

test('rewrites positional placeholders into numbered ones', function (string $sql, string $expected) {
    expect(PgSqlPlaceholders::convert($sql))->toBe($expected);
})->with([
    'no placeholders' => ['SELECT 1', 'SELECT 1'],
    'in order' => ['SELECT * FROM t WHERE a = ? AND b IN (?, ?)', 'SELECT * FROM t WHERE a = $1 AND b IN ($2, $3)'],
    'string literal' => ["SELECT '?', ? FROM t", "SELECT '?', \$1 FROM t"],
    'escaped quote in literal' => ["SELECT 'it''s ?', ?", "SELECT 'it''s ?', \$1"],
    'E-string with backslash escape' => ["SELECT E'\\' ?', ?", "SELECT E'\\' ?', \$1"],
    'identifier ending in e is not an E-string' => ["SELECT name = '\\', ?", "SELECT name = '\\', \$1"],
    'quoted identifier' => ['SELECT "col?" FROM t WHERE x = ?', 'SELECT "col?" FROM t WHERE x = $1'],
    'line comment' => ["SELECT ? -- why?\nFROM t WHERE x = ?", "SELECT \$1 -- why?\nFROM t WHERE x = \$2"],
    'block comment' => ['SELECT /* ? */ ?', 'SELECT /* ? */ $1'],
    'dollar quoted' => ['SELECT $$ ? $$, ?', 'SELECT $$ ? $$, $1'],
    'tagged dollar quoted' => ['SELECT $fn$ a ? $$ b $fn$, ?', 'SELECT $fn$ a ? $$ b $fn$, $1'],
    'dollar inside identifier' => ['SELECT price$usd$ FROM t WHERE x = ?', 'SELECT price$usd$ FROM t WHERE x = $1'],
    'escaped operator' => ["SELECT data ?? 'key' FROM t WHERE id = ?", "SELECT data ? 'key' FROM t WHERE id = \$1"],
]);
