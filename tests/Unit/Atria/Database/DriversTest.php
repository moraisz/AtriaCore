<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Database;

use Atria\Database\Drivers;

test('resolve returns connection, query builder and schema grammar for every driver', function (string $driver) {
    $result = Drivers::resolve($driver);

    expect($result)->toBeArray();
    expect(class_exists($result['connection'] ?? ''))->toBeTrue();
    expect(class_exists($result['query_builder'] ?? ''))->toBeTrue();
    expect(class_exists($result['schema_grammar'] ?? ''))->toBeTrue();
})->with(['pgsql', 'sqlite', 'mysql', 'mariadb']);

test('mariadb resolves to the mysql driver', function () {
    expect(Drivers::resolve('mariadb'))->toBe(Drivers::resolve('mysql'));
});

test('resolve returns null for unregistered driver', function () {
    expect(Drivers::resolve('sqlsrv'))->toBeNull();
    expect(Drivers::resolve(''))->toBeNull();
    expect(Drivers::resolve('unknown'))->toBeNull();
});
