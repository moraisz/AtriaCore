<?php

declare(strict_types=1);

namespace Atria\Database;

/**
 * Rows and affected row count of an executed statement, independent of the driver.
 */
final readonly class Result
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public array $rows = [],
        public int $affectedRows = 0,
    ) {}
}
