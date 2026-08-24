<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Database;

use Atria\Database\Drivers;

test('resolve returns connection and query_builder for pgsql', function () {
    $result = Drivers::resolve('pgsql');

    expect($result)->toBeArray();
    expect($result['connection'])->toBeString();
    expect($result['query_builder'])->toBeString();
});

test('resolve returns null for unregistered driver', function () {
    expect(Drivers::resolve('sqlite'))->toBeNull();
    expect(Drivers::resolve(''))->toBeNull();
    expect(Drivers::resolve('unknown'))->toBeNull();
});
