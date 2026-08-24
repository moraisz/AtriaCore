<?php

declare(strict_types=1);

namespace Atria\Database\AbstractClasses;

use Closure;
use Atria\Database\Contracts\QueryBuilder;

abstract class Model
{
    /** @var Closure(): QueryBuilder|null */
    private static ?Closure $resolver = null;

    abstract protected static function table(): string;

    /** @return array<int, string> */
    abstract protected static function fillable(): array;

    /** @param Closure(): QueryBuilder $resolver */
    public static function setResolver(Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    protected static function queryBuilder(): QueryBuilder
    {
        if (self::$resolver === null) {
            throw new \RuntimeException('Nenhum resolver de query builder foi configurado.');
        }

        return (self::$resolver)();
    }

    /** @return array<string, mixed>|null */
    public static function findById(int|string $id): ?array
    {
        $result = static::queryBuilder()
            ->select(['*'])
            ->from(static::table())
            ->where('id', '=', $id)
            ->execute();

        return $result[0] ?? null;
    }

    /** @return array<int, array<string, mixed>>|null */
    public static function findAll(): ?array
    {
        $result = static::queryBuilder()
            ->select(['*'])
            ->from(static::table())
            ->execute();

        return $result !== [] ? $result : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function create(array $data): array
    {
        $data = array_intersect_key($data, array_flip(static::fillable()));

        $result = static::queryBuilder()
            ->insertInto(static::table(), array_keys($data))
            ->values(array_values($data))
            ->execute();

        return $result[0];
    }
}
