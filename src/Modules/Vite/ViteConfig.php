<?php

declare(strict_types=1);

namespace Atria\Modules\Vite;

use InvalidArgumentException;

final class ViteConfig
{
    /** @param array<int, string> $entryPaths */
    public function __construct(
        public readonly array $entryPaths,
        public readonly string $basePath,
        public readonly string $buildDir,
    ) {
        if ($this->basePath === '' || $this->buildDir === '') {
            throw new InvalidArgumentException('Vite base path and build directory must be non-empty.');
        }

        foreach ($this->entryPaths as $entryPath) {
            if ($entryPath === '') {
                throw new InvalidArgumentException('Vite entry paths must be non-empty strings.');
            }
        }
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $entryPaths = $config['entry_path'] ?? null;
        $basePath = $config['base_path'] ?? null;
        $buildDir = $config['build_dir'] ?? null;

        if (!is_array($entryPaths) || array_filter($entryPaths, 'is_string') !== $entryPaths) {
            throw new InvalidArgumentException('Vite config entry_path must be an array of strings.');
        }

        if (!is_string($basePath) || !is_string($buildDir)) {
            throw new InvalidArgumentException('Vite config base_path and build_dir must be strings.');
        }

        /** @var array<int, string> $entryPaths */
        return new self(array_values($entryPaths), $basePath, $buildDir);
    }

    public function hotFile(): string
    {
        return $this->buildDir . '/hot';
    }
    public function manifestFile(): string
    {
        return $this->buildDir . '/.vite/manifest.json';
    }
}
