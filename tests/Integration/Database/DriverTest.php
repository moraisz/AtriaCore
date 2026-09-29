<?php

declare(strict_types=1);

use Atria\Database\AbstractClasses\Model;
use Atria\Database\Contracts\DatabaseConnection;
use Atria\Database\Contracts\QueryBuilder;
use Atria\Database\Drivers;
use Atria\Database\Migrator;
use Atria\Database\Schema\Blueprint;
use Atria\Database\Schema\Schema;
use Atria\Modules\Auth\AuthConfig;
use Atria\Modules\Auth\AuthManager;
use Atria\Modules\Auth\Exceptions\InvalidRefreshTokenException;
use Atria\Modules\Auth\Services\AuthTokenService;

/*
 * Runs against real databases. SQLite always runs in memory; PostgreSQL and
 * MySQL run only when DB_TEST_{PGSQL,MYSQL}_HOST is set, using the matching
 * _PORT, _DATABASE, _USERNAME and _PASSWORD variables. A `+emulated` suffix
 * runs the same driver with PDO::ATTR_EMULATE_PREPARES enabled.
 */

final class IntegrationItem extends Model
{
    protected static function table(): string
    {
        return 'integration_items';
    }

    protected static function fillable(): array
    {
        return ['name', 'active'];
    }
}

/**
 * @return array{connection: DatabaseConnection, queryBuilder: Closure(): QueryBuilder, schema: Schema}|null
 */
function integrationDriver(string $variant): ?array
{
    [$driver, $mode] = explode('+', $variant) + [1 => ''];

    if ($driver === 'sqlite') {
        $config = ['database' => ':memory:'];
    } else {
        $prefix = 'DB_TEST_' . strtoupper($driver) . '_';
        $host = getenv($prefix . 'HOST');
        if (!is_string($host) || $host === '') {
            return null;
        }

        $config = [
            'host' => $host,
            'port' => (string) getenv($prefix . 'PORT'),
            'database' => (string) getenv($prefix . 'DATABASE'),
            'username' => (string) getenv($prefix . 'USERNAME'),
            'password' => (string) getenv($prefix . 'PASSWORD'),
        ];
    }

    if ($mode === 'emulated') {
        $config['options'] = [PDO::ATTR_EMULATE_PREPARES => true];
    }

    $resolved = Drivers::resolve($driver);
    assert($resolved !== null);

    $connection = new $resolved['connection']($config);
    $builder = $resolved['query_builder'];
    $grammar = $resolved['schema_grammar'];

    return [
        'connection' => $connection,
        'queryBuilder' => static fn(): QueryBuilder => new $builder($connection),
        'schema' => new Schema($connection, new $grammar()),
    ];
}

/**
 * @return array{connection: DatabaseConnection, queryBuilder: Closure(): QueryBuilder, schema: Schema}
 */
function freshIntegrationDatabase(string $variant): array
{
    $db = integrationDriver($variant);
    if ($db === null) {
        test()->markTestSkipped('DB_TEST_' . strtoupper(explode('+', $variant)[0]) . '_HOST is not set');
    }

    foreach (['refresh_tokens', 'users', 'integration_items', 'migrations'] as $table) {
        $db['schema']->drop($table);
    }

    $db['schema']->create('integration_items', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->boolean('active')->default(true);
        $table->timestamps();
    });

    Model::setResolver($db['queryBuilder']);

    return $db;
}

function integrationAuthManager(Closure $queryBuilder): AuthManager
{
    return new AuthManager(
        $queryBuilder,
        new AuthTokenService('integration-secret'),
        new AuthConfig(
            driver: 'standard',
            secret: 'integration-secret',
            accessTtl: 300,
            refreshTtl: 86400,
            accessCookie: 'access_token',
            refreshCookie: 'refresh_token',
            cookieSecure: true,
            cookieSameSite: 'Strict',
            redirectGuestTo: '/login',
            redirectAuthenticatedTo: '/',
            usersTable: 'users',
            refreshTokensTable: 'refresh_tokens',
        ),
    );
}

dataset('drivers', ['sqlite', 'pgsql', 'mysql', 'pgsql+emulated', 'mysql+emulated']);

test('model create returns the inserted row', function (string $driver) {
    freshIntegrationDatabase($driver);

    $row = IntegrationItem::create(['name' => 'first', 'active' => false, 'ignored' => 'x']);

    expect((int) $row['id'])->toBeGreaterThan(0);
    expect($row['name'])->toBe('first');
    expect((bool) $row['active'])->toBeFalse();
    expect($row['created_at'])->not->toBeNull();
    expect(IntegrationItem::findById((int) $row['id'])['name'] ?? null)->toBe('first');
})->with('drivers');

test('select honours limit and offset', function (string $driver) {
    $db = freshIntegrationDatabase($driver);
    foreach (['a', 'b', 'c', 'd'] as $name) {
        IntegrationItem::create(['name' => $name]);
    }

    $page = ($db['queryBuilder'])()->select(['name'])->from('integration_items')->orderBy('name')->limit(2)->offset(1)->execute();
    $tail = ($db['queryBuilder'])()->select(['name'])->from('integration_items')->orderBy('name')->offset(3)->execute();

    expect(array_column($page, 'name'))->toBe(['b', 'c']);
    expect(array_column($tail, 'name'))->toBe(['d']);
})->with('drivers');

test('affected counts matched rows', function (string $driver) {
    $db = freshIntegrationDatabase($driver);
    IntegrationItem::create(['name' => 'a']);
    IntegrationItem::create(['name' => 'b']);

    $updated = ($db['queryBuilder'])()->update('integration_items')->set(['active' => true])->affected();
    $deleted = ($db['queryBuilder'])()->deleteFrom('integration_items')->where('name', '=', 'a')->affected();

    expect($updated)->toBe(2);
    expect($deleted)->toBe(1);
})->with('drivers');

test('transaction rolls back on failure', function (string $driver) {
    $db = freshIntegrationDatabase($driver);
    $queryBuilder = ($db['queryBuilder'])();

    expect(fn() => $queryBuilder->transaction(function (QueryBuilder $qb): void {
        $qb->insertInto('integration_items', ['name'])->values(['rolled back'])->execute();
        throw new RuntimeException('abort');
    }))->toThrow(RuntimeException::class, 'abort');

    expect($db['connection']->inTransaction())->toBeFalse();
    expect(IntegrationItem::findAll())->toBeNull();
})->with('drivers');

test('migrator runs and rolls back the auth migrations', function (string $driver) {
    $db = freshIntegrationDatabase($driver);
    $migrator = new Migrator(($db['queryBuilder'])(), $db['schema'], dirname(__DIR__, 3) . '/src/Modules/Auth/Migrations');

    ob_start();
    $migrator->run();
    $migrator->run();
    ob_end_clean();

    $migrated = ($db['queryBuilder'])()->select(['migration'])->from('migrations')->orderBy('migration')->execute();
    expect(array_column($migrated, 'migration'))->toBe([
        '0000_00_00_000000_create_users_table',
        '0000_00_00_000001_create_refresh_tokens_table',
    ]);

    ob_start();
    $migrator->rollback();
    ob_end_clean();

    expect(($db['queryBuilder'])()->select(['migration'])->from('migrations')->execute())->toBe([]);
})->with('drivers');

test('auth refresh rotates once and rejects the consumed token', function (string $driver) {
    $db = freshIntegrationDatabase($driver);
    ob_start();
    new Migrator(($db['queryBuilder'])(), $db['schema'], dirname(__DIR__, 3) . '/src/Modules/Auth/Migrations')->run();
    ob_end_clean();

    ($db['queryBuilder'])()
        ->insertInto('users', ['name', 'email', 'password_hash'])
        ->values(['Ana', 'ana@example.com', password_hash('secret', PASSWORD_DEFAULT)])
        ->execute();

    $auth = integrationAuthManager($db['queryBuilder']);
    $login = $auth->attempt('ana@example.com', 'secret', 'browser');
    $rotated = $auth->refresh($login['refresh_token'], 'browser');

    expect($rotated['refresh_token'])->not->toBe($login['refresh_token']);
    expect(fn() => $auth->refresh($login['refresh_token']))->toThrow(InvalidRefreshTokenException::class);
    expect($db['connection']->inTransaction())->toBeFalse();

    $active = ($db['queryBuilder'])()->select(['id'])->from('refresh_tokens')->whereNull('revoked_at')->execute();
    expect($active)->toHaveCount(1);
})->with('drivers');
