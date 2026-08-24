<?php

declare(strict_types=1);

namespace Atria\Modules\View;

use InvalidArgumentException;

final class ViewConfig
{
    public function __construct(public readonly string $viewsPath)
    {
        if ($this->viewsPath === '') {
            throw new InvalidArgumentException('View path must be non-empty.');
        }
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $viewsPath = $config['views'] ?? null;

        if (!is_string($viewsPath)) {
            throw new InvalidArgumentException('View config views must be a string.');
        }

        return new self($viewsPath);
    }
}
