<?php

declare(strict_types=1);

namespace Atria\Modules\Vite;

use Atria\Modules\Vite\Exceptions\ViteEntryNotFoundException;
use Atria\Modules\Vite\Exceptions\ViteManifestNotFoundException;

final class ViteManager
{
    public function __construct(private readonly ViteConfig $config) {}

    public function tags(): string
    {
        return $this->tagsFor($this->config->entryPaths, true);
    }

    /** @param array<int, string>|string $entries */
    public function tagsFor(array|string $entries, bool $includeDevClient = false): string
    {
        $entryPaths = is_array($entries) ? $entries : [$entries];

        return file_exists($this->config->hotFile())
            ? $this->devModeTags($entryPaths, $includeDevClient)
            : $this->productionModeTags($entryPaths);
    }

    /** @param array<int, string> $entryPaths */
    private function devModeTags(array $entryPaths, bool $includeDevClient): string
    {
        $hotContent = file_get_contents($this->config->hotFile());
        $base = trim($hotContent !== false ? $hotContent : 'http://localhost:5173') . $this->config->basePath;
        $tags = $includeDevClient ? ['<script type="module" src="' . $base . '@vite/client"></script>'] : [];

        foreach ($entryPaths as $path) {
            $url = $base . $path;
            $tags[] = str_ends_with($path, '.css')
                ? '<link rel="stylesheet" href="' . $url . '">' : '<script type="module" src="' . $url . '"></script>';
        }

        return implode("\n", $tags);
    }

    /** @param array<int, string> $entryPaths */
    private function productionModeTags(array $entryPaths): string
    {
        if (!file_exists($this->config->manifestFile())) {
            throw new ViteManifestNotFoundException("Vite manifest not found. Run 'npm run build' first.");
        }

        $manifestContent = file_get_contents($this->config->manifestFile());
        /** @var array<string, array{file?: string, css?: array<int, string>}> $manifest */
        $manifest = json_decode($manifestContent !== false ? $manifestContent : '{}', true);
        $tags = [];

        foreach ($entryPaths as $path) {
            if (!isset($manifest[$path])) {
                throw new ViteEntryNotFoundException("Vite entry '{$path}' was not found in the manifest.");
            }

            $entry = $manifest[$path];
            foreach ($entry['css'] ?? [] as $css) {
                $tags[] = '<link rel="stylesheet" href="' . $this->config->basePath . $css . '">';
            }

            $file = is_string($entry['file'] ?? null) ? $entry['file'] : '';
            $tags[] = str_ends_with($path, '.css')
                ? '<link rel="stylesheet" href="' . $this->config->basePath . $file . '">' : '<script type="module" src="' . $this->config->basePath . $file . '"></script>';
        }

        return implode("\n", $tags);
    }
}
