<?php

declare(strict_types=1);

namespace Atria\Database\Schema;

use Atria\Database\Contracts\DatabaseConnection;
use Atria\Database\Schema\Grammars\SchemaGrammar;
use Closure;

class Schema
{
    public function __construct(
        private DatabaseConnection $connection,
        private SchemaGrammar $grammar,
    ) {}

    /**
     * Creates the table and its indexes when the table does not exist yet.
     *
     * @param Closure(Blueprint): void $callback
     */
    public function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        foreach ($this->grammar->compileCreate($blueprint) as $sql) {
            $this->connection->execute($sql, []);
        }
    }

    public function drop(string $table): void
    {
        $this->connection->execute($this->grammar->compileDrop($table), []);
    }
}
