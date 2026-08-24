<?php

declare(strict_types=1);

use Atria\Database\Migrator;

test('constructor creates migrations table', function () {
    $qb = new MockQueryBuilder();
    new Migrator($qb, '/fake/path');

    $createCalls = array_values(array_filter($qb->log, fn($e) => $e['method'] === 'createTable'));
    expect($createCalls)->toHaveCount(1);
    expect($createCalls[0]['table'])->toBe('migrations');
    expect($createCalls[0]['columns'])->toHaveKeys(['id', 'migration', 'batch', 'executed_at']);
});

test('run with no migration files', function () {
    $qb = new MockQueryBuilder();
    $migrator = new Migrator($qb, '/nonexistent/path/that/glob/returns/empty');

    ob_start();
    $migrator->run();
    $output = ob_get_clean();

    expect($output)->toContain('No migrations found');
});

test('run skips already executed migrations', function () {
    $qb = new MockQueryBuilder();
    $qb->setReturnRows([
        ['migration' => '0000_init'],
        ['migration' => '0001_users'],
    ]);

    $tmpDir = sys_get_temp_dir() . '/atria-core_migrator_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/0000_init.php', '<?php return new class extends \Atria\Database\AbstractClasses\Migration { public function up(): void { $this->queryBuilder->createTable("t1",["col"=>"INT"])->execute(); } public function down(): void {} };');
    file_put_contents($tmpDir . '/0001_users.php', '<?php return new class extends \Atria\Database\AbstractClasses\Migration { public function up(): void { $this->queryBuilder->createTable("t2",["col"=>"INT"])->execute(); } public function down(): void {} };');

    $migrator = new Migrator($qb, $tmpDir);

    ob_start();
    $migrator->run();
    $output = ob_get_clean();

    expect($output)->toContain('All migrations are already executed');

    array_map('unlink', glob($tmpDir . '/*.*') ?: []);
    rmdir($tmpDir);
});

test('run executes pending migrations', function () {
    $qb = new MockQueryBuilder();
    // Simulate no executed migrations yet
    $qb->setReturnRows([]);

    $tmpDir = sys_get_temp_dir() . '/atria-core_migrator_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/0000_init.php', '<?php return new class extends \Atria\Database\AbstractClasses\Migration { public function up(): void { $this->queryBuilder->createTable("t1",["col"=>"INT"])->execute(); } public function down(): void {} };');

    $migrator = new Migrator($qb, $tmpDir);

    ob_start();
    $migrator->run();
    $output = ob_get_clean();

    expect($output)->toContain('Migrating: 0000_init');
    expect($output)->toContain('Migrated: 0000_init');

    $insertCalls = array_values(array_filter($qb->log, fn($e) => $e['method'] === 'insertInto'));
    expect($insertCalls)->toHaveCount(1);
    expect($insertCalls[0]['table'])->toBe('migrations');

    array_map('unlink', glob($tmpDir . '/*.*') ?: []);
    rmdir($tmpDir);
});

test('rollback with nothing to roll back', function () {
    $qb = new MockQueryBuilder();
    // Simulate no batches
    $qb->setReturnRows([]);

    $migrator = new Migrator($qb, '/fake/path');

    ob_start();
    $migrator->rollback(1);
    $output = ob_get_clean();

    expect($output)->toContain('Nothing to rollback');
});

test('rollback executes down on latest batch', function () {
    $qb = new MockQueryBuilder();

    // Sequence: constructor createMigrationsTable → DISTINCT batch → migrations in batch → deleteFrom
    $qb->setReturnSequence([
        [],                                // 0: createTable migrations in constructor
        [['batch' => 2]],                  // 1: SELECT DISTINCT batch
        [['migration' => '0001_users']],   // 2: SELECT migration WHERE batch IN (2)
        [],                                // 3: DELETE FROM migrations (removeMigration)
    ]);

    $tmpDir = sys_get_temp_dir() . '/atria-core_migrator_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/0001_users.php', '<?php return new class extends \Atria\Database\AbstractClasses\Migration { public function up(): void {} public function down(): void { $this->queryBuilder->dropTable("users")->execute(); } };');

    $migrator = new Migrator($qb, $tmpDir);

    ob_start();
    $migrator->rollback(1);
    $output = ob_get_clean();

    expect($output)->toContain('Rolling back: 0001_users');
    expect($output)->toContain('Rolled back: 0001_users');

    $dropCalls = array_values(array_filter($qb->log, fn($e) => $e['method'] === 'dropTable'));
    expect($dropCalls)->toHaveCount(1);
    expect($dropCalls[0]['table'])->toBe('users');

    array_map('unlink', glob($tmpDir . '/*.*') ?: []);
    rmdir($tmpDir);
});

test('run sets query builder on migration instance before calling up', function () {
    $qb = new MockQueryBuilder();
    $qb->setReturnRows([]);

    $tmpDir = sys_get_temp_dir() . '/atria-core_migrator_test_' . uniqid();
    mkdir($tmpDir);

    // Migration that asserts query builder was set
    $code = <<<'PHP'
        <?php
        $received = null;
        return new class($received) extends \Atria\Database\AbstractClasses\Migration {
            private $receivedRef;
            public function __construct(&$received) { $this->receivedRef = &$received; }
            public function up(): void { $this->receivedRef = $this->queryBuilder; $this->queryBuilder->createTable("ok",["x"=>"INT"])->execute(); }
            public function down(): void {}
        };
        PHP;
    file_put_contents($tmpDir . '/0000_test.php', $code);

    $migrator = new Migrator($qb, $tmpDir);

    ob_start();
    $migrator->run();
    ob_end_clean();

    $createCalls = array_values(array_filter($qb->log, fn($e) => $e['method'] === 'createTable' && $e['table'] !== 'migrations'));
    expect($createCalls)->toHaveCount(1);
    expect($createCalls[0]['table'])->toBe('ok');

    array_map('unlink', glob($tmpDir . '/*.*') ?: []);
    rmdir($tmpDir);
});

test('run calculates next batch correctly', function () {
    $qb = new MockQueryBuilder();
    // Return existing max batch = 3
    $qb->setReturnRows([['batch' => 3]]);

    $tmpDir = sys_get_temp_dir() . '/atria-core_migrator_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/0000_fresh.php', '<?php return new class extends \Atria\Database\AbstractClasses\Migration { public function up(): void { $this->queryBuilder->createTable("batch_test",["col"=>"INT"])->execute(); } public function down(): void {} };');

    $migrator = new Migrator($qb, $tmpDir);

    ob_start();
    $migrator->run();
    ob_end_clean();

    $insertValues = array_values(array_filter($qb->log, fn($e) => $e['method'] === 'values'));
    expect($insertValues)->toHaveCount(1);
    // The second value is the batch number, should be 4 (existing max 3 + 1)
    expect($insertValues[0]['data'][1])->toBe(4);

    array_map('unlink', glob($tmpDir . '/*.*') ?: []);
    rmdir($tmpDir);
});

test('run executes pending migrations across multiple directories', function () {
    $qb = new MockQueryBuilder();
    $qb->setReturnRows([]);

    $firstDir = sys_get_temp_dir() . '/atria-core_migrator_test_a_' . uniqid();
    $secondDir = sys_get_temp_dir() . '/atria-core_migrator_test_b_' . uniqid();
    mkdir($firstDir);
    mkdir($secondDir);
    file_put_contents($firstDir . '/0000_first.php', '<?php return new class extends \Atria\Database\AbstractClasses\Migration { public function up(): void { $this->queryBuilder->createTable("first",["col"=>"INT"])->execute(); } public function down(): void {} };');
    file_put_contents($secondDir . '/0001_second.php', '<?php return new class extends \Atria\Database\AbstractClasses\Migration { public function up(): void { $this->queryBuilder->createTable("second",["col"=>"INT"])->execute(); } public function down(): void {} };');

    $migrator = new Migrator($qb, [$firstDir, $secondDir]);

    ob_start();
    $migrator->run();
    $output = ob_get_clean();

    expect($output)->toContain('Migrating: 0000_first')
        ->and($output)->toContain('Migrating: 0001_second');

    $createCalls = array_values(array_filter($qb->log, fn($e) => $e['method'] === 'createTable' && $e['table'] !== 'migrations'));
    expect($createCalls)->toHaveCount(2);

    array_map('unlink', glob($firstDir . '/*.*') ?: []);
    array_map('unlink', glob($secondDir . '/*.*') ?: []);
    rmdir($firstDir);
    rmdir($secondDir);
});

test('rollback resolves migration files across multiple directories', function () {
    $qb = new MockQueryBuilder();
    $qb->setReturnSequence([
        [],
        [['batch' => 2]],
        [['migration' => '0001_second']],
        [],
    ]);

    $firstDir = sys_get_temp_dir() . '/atria-core_migrator_test_c_' . uniqid();
    $secondDir = sys_get_temp_dir() . '/atria-core_migrator_test_d_' . uniqid();
    mkdir($firstDir);
    mkdir($secondDir);
    file_put_contents($secondDir . '/0001_second.php', '<?php return new class extends \Atria\Database\AbstractClasses\Migration { public function up(): void {} public function down(): void { $this->queryBuilder->dropTable("second")->execute(); } };');

    $migrator = new Migrator($qb, [$firstDir, $secondDir]);

    ob_start();
    $migrator->rollback(1);
    $output = ob_get_clean();

    expect($output)->toContain('Rolling back: 0001_second');

    $dropCalls = array_values(array_filter($qb->log, fn($e) => $e['method'] === 'dropTable'));
    expect($dropCalls)->toHaveCount(1);
    expect($dropCalls[0]['table'])->toBe('second');

    array_map('unlink', glob($secondDir . '/*.*') ?: []);
    rmdir($firstDir);
    rmdir($secondDir);
});
