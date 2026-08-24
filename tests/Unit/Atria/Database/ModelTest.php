<?php

declare(strict_types=1);

use Atria\Database\AbstractClasses\Model;

class TestUserModel extends Model
{
    protected static function table(): string
    {
        return 'users';
    }

    protected static function fillable(): array
    {
        return ['name', 'email', 'password'];
    }
}

test('findById assembles correct query', function () {
    $qb = new MockQueryBuilder();
    $qb->setReturnRows([['id' => 1, 'name' => 'John']]);

    Model::setResolver(fn() => $qb);

    $result = TestUserModel::findById(1);

    expect($qb->log[0]['method'])->toBe('select');
    expect($qb->log[0]['columns'])->toBe(['*']);
    expect($qb->log[1]['method'])->toBe('from');
    expect($qb->log[1]['table'])->toBe('users');
    expect($qb->log[2]['method'])->toBe('where');
    expect($qb->log[2]['column'])->toBe('id');
    expect($qb->log[2]['value'])->toBe(1);
    expect($result)->toBe(['id' => 1, 'name' => 'John']);
});

test('findById returns null when no rows', function () {
    $qb = new MockQueryBuilder();
    $qb->setReturnRows([]);

    Model::setResolver(fn() => $qb);

    expect(TestUserModel::findById(999))->toBeNull();
});

test('findAll returns all rows', function () {
    $qb = new MockQueryBuilder();
    $qb->setReturnRows([
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ]);

    Model::setResolver(fn() => $qb);

    $result = TestUserModel::findAll();

    expect($result)->toHaveCount(2);
    expect($result[0]['name'])->toBe('Alice');
    expect($result[1]['name'])->toBe('Bob');
});

test('create filters to fillable columns', function () {
    $qb = new MockQueryBuilder();
    $qb->setReturnRows([['id' => 1, 'name' => 'Eve', 'email' => 'eve@test.com']]);

    Model::setResolver(fn() => $qb);

    $result = TestUserModel::create([
        'name' => 'Eve',
        'email' => 'eve@test.com',
        'password' => 'secret',
        'extra_evil' => 'should be stripped',
    ]);

    $insertCall = array_values(array_filter($qb->log, fn($e) => $e['method'] === 'insertInto'))[0];
    expect($insertCall['columns'])->toBe(['name', 'email', 'password']);
    expect($insertCall['columns'])->not->toContain('extra_evil');

    $valuesCall = array_values(array_filter($qb->log, fn($e) => $e['method'] === 'values'))[0];
    expect($valuesCall['data'])->toBe(['Eve', 'eve@test.com', 'secret']);
    expect($result)->toBe(['id' => 1, 'name' => 'Eve', 'email' => 'eve@test.com']);
});

test('without resolver throws', function () {
    // Ensure resolver is cleared (test isolation may leave it from prior tests)
    $ref = new ReflectionClass(Model::class);
    $prop = $ref->getProperty('resolver');
    $prop->setValue(null, null);

    TestUserModel::findById(1);
})->throws(\RuntimeException::class);

test('each call gets a fresh query builder from resolver', function () {
    $count = 0;
    Model::setResolver(function () use (&$count) {
        $count++;
        $qb = new MockQueryBuilder();
        $qb->setReturnRows([]);
        return $qb;
    });

    TestUserModel::findById(1);
    TestUserModel::findById(2);
    TestUserModel::findAll();

    expect($count)->toBe(3);
});
