<?php

declare(strict_types=1);

namespace Atria\Modules\View;

use Atria\Modules\Csrf\CsrfManager;
use Atria\Modules\Vite\ViteManager;
use Atria\Modules\View\Exceptions\InvalidViewPathException;
use Atria\Modules\View\Exceptions\ViewNotFoundException;

final class ViewManager
{
    private ?string $layout = null;
    /** @var array<string, string> */
    private array $sections = [];
    private ?string $currentSection = null;
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly ViewConfig $config,
        private readonly CsrfManager $csrfManager,
        private readonly ViteManager $viteManager,
    ) {}

    /** @param array<string, mixed> $data */
    public function render(string $viewName, array $data = []): string
    {
        $this->reset();
        $this->data = $data;
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            include $this->resolvePath($viewName);
            $viewContent = ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        $viewContent = $viewContent !== false ? $viewContent : '';

        if ($this->layout === null) {
            return $viewContent;
        }

        extract($this->data, EXTR_SKIP);
        ob_start();
        try {
            include $this->resolvePath($this->layout);
            $layoutContent = ob_get_clean();

            return $layoutContent !== false ? $layoutContent : '';
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }

    public function extends(string $layout): void
    {
        $this->layout = $layout;
    }

    public function section(string $name): void
    {
        if ($this->currentSection !== null) {
            throw new \LogicException("Cannot open section '{$name}': section '{$this->currentSection}' is still open.");
        }

        $this->currentSection = $name;
        ob_start();
    }

    public function endSection(): void
    {
        if ($this->currentSection === null) {
            throw new \LogicException('endSection() called without an open section.');
        }

        $content = ob_get_clean();
        $this->sections[$this->currentSection] = $content !== false ? $content : '';
        $this->currentSection = null;
    }

    public function yield(string $section, string $default = ''): string
    {
        return $this->sections[$section] ?? $default;
    }

    public function getLayout(): ?string
    {
        return $this->layout;
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars(match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '',
            default => '',
        }, ENT_QUOTES, 'UTF-8');
    }

    /** @param array<string, mixed> $data */
    public function include(string $component, array $data = []): void
    {
        ob_start();
        try {
            extract($data, EXTR_SKIP);
            include $this->resolvePath($component);
            echo ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }

    public function csrfToken(): string
    {
        return $this->e($this->csrfManager->currentToken());
    }
    public function viteTags(): string
    {
        return $this->viteManager->tags();
    }
    /** @param array<int, string>|string $entries */
    public function viteTagsFor(array|string $entries, bool $includeDevClient = false): string
    {
        return $this->viteManager->tagsFor($entries, $includeDevClient);
    }

    private function resolvePath(string $name): string
    {
        $realPath = realpath($this->config->viewsPath . '/' . $name . '.php');
        $realBase = realpath($this->config->viewsPath);

        if ($realPath === false || $realBase === false) {
            throw new ViewNotFoundException("View not found: '{$name}'.");
        }

        if (!str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
            throw new InvalidViewPathException("View '{$name}' is outside the allowed views directory.");
        }

        return $realPath;
    }

    private function reset(): void
    {
        $this->sections = [];
        $this->currentSection = null;
        $this->layout = null;
        $this->data = [];
    }
}
