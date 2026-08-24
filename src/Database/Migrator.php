<?php

declare(strict_types=1);

namespace Atria\Database;

use Atria\Database\AbstractClasses\Migration;
use Atria\Database\Contracts\QueryBuilder;

class Migrator
{
    private QueryBuilder $queryBuilder;
    /** @var array<int, string> */
    private array $migrationPaths;

    /**
     * @param string|array<int, string> $migrationsPath
     */
    public function __construct(QueryBuilder $queryBuilder, string|array $migrationsPath)
    {
        $this->queryBuilder = $queryBuilder;
        if (is_array($migrationsPath)) {
            $paths = array_values(array_filter($migrationsPath, 'is_string'));
        } else {
            $paths = [$migrationsPath];
        }

        $this->migrationPaths = $paths;
        $this->createMigrationsTable();
    }

    private function createMigrationsTable(): void
    {
        $this->queryBuilder
            ->createTable('migrations', [
                'id' => 'SERIAL PRIMARY KEY',
                'migration' => 'VARCHAR(255) NOT NULL',
                'batch' => 'INTEGER NOT NULL',
                'executed_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            ])
            ->execute();
    }

    public function run(): void
    {
        echo "Running migrations...\n";

        $files = $this->migrationFiles();

        $executed = $this->getExecutedMigrations();
        $batch = $this->getNextBatch();

        if (empty($files)) {
            echo "No migrations found\n";
            return;
        }

        if (count($executed) === count($files)) {
            echo "All migrations are already executed\n";
            return;
        }

        foreach ($files as $migration => $file) {

            if (in_array($migration, $executed)) {
                continue;
            }

            echo "Migrating: {$migration}\n";

            $instance = require $file;

            if (!$instance instanceof Migration) {
                throw new \Exception("Migration {$migration} must return a Migration instance");
            }

            $instance->setQueryBuilder($this->queryBuilder);
            $instance->up();

            $this->logMigration($migration, $batch);
            echo "Migrated: {$migration}\n";
        }
    }

    public function rollback(int $steps = 1): void
    {
        echo "Running rollback migrations...\n";

        $files = $this->migrationFiles();

        $batches = $this->queryBuilder
            ->select(['DISTINCT batch'])
            ->from('migrations')
            ->orderBy('batch', 'DESC');

        if ($steps > 0) {
            $batches->limit($steps);
        }

        $batches = $batches->execute();

        if (empty($batches)) {
            echo "Nothing to rollback\n";
            return;
        }

        /** @var array<int, array<string, mixed>> $migrations */
        $migrations = $this->queryBuilder
            ->select(['migration'])
            ->from('migrations')
            ->where('batch', 'IN', array_column($batches, 'batch'))
            ->orderBy('id', 'DESC')
            ->execute();

        foreach ($migrations as $migration) {
            $migrationName = is_string($migration['migration'] ?? null) ? $migration['migration'] : '';
            $file = $files[$migrationName] ?? null;

            if (!is_string($file)) {
                throw new \RuntimeException("Migration file not found for {$migrationName}");
            }

            echo "Rolling back: {$migrationName}\n";

            $instance = require $file;

            if (!$instance instanceof Migration) {
                throw new \Exception("Migration must return a Migration instance");
            }

            $instance->setQueryBuilder($this->queryBuilder);
            $instance->down();

            $this->removeMigration($migrationName);
            echo "Rolled back: {$migrationName}\n";
        }
    }

    /**
     * @return array<int, string>
     */
    private function getExecutedMigrations(): array
    {
        $result = $this->queryBuilder
            ->select(['migration'])
            ->from('migrations')
            ->execute();

        /** @var array<int, string> $migrations */
        $migrations = array_column($result, 'migration');
        return $migrations;
    }

    private function getNextBatch(): int
    {
        $result = $this->queryBuilder
            ->select(['MAX(batch) as batch'])
            ->from('migrations')
            ->execute();

        $batchValue = $result[0]['batch'] ?? 0;
        $batch = is_numeric($batchValue) ? (int) $batchValue : 0;
        return $batch + 1;
    }

    private function logMigration(string $migration, int $batch): void
    {
        $this->queryBuilder
            ->insertInto('migrations', [
                'migration',
                'batch',
            ])
            ->values([
                $migration,
                $batch,
            ])
            ->execute();
    }

    private function removeMigration(string $migration): void
    {
        $this->queryBuilder
            ->deleteFrom('migrations')
            ->where('migration', '=', $migration)
            ->execute();
    }

    /**
     * @return array<string, string>
     */
    private function migrationFiles(): array
    {
        $files = [];

        foreach ($this->migrationPaths as $path) {
            foreach (glob($path . '/*.php') ?: [] as $file) {
                $migration = basename($file, '.php');

                if (isset($files[$migration]) && $files[$migration] !== $file) {
                    throw new \RuntimeException("Duplicate migration detected: {$migration}");
                }

                $files[$migration] = $file;
            }
        }

        ksort($files);

        return $files;
    }
}
