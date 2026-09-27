<?php

declare(strict_types=1);

namespace Atria\Http;

use RuntimeException;

final class UploadedFile
{
    public function __construct(
        private readonly string $originalName,
        private readonly string $clientMediaType,
        private readonly string $temporaryPath,
        private readonly int $error,
        private readonly int $size,
    ) {}

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getClientMediaType(): string
    {
        return $this->clientMediaType;
    }

    public function getTemporaryPath(): string
    {
        return $this->temporaryPath;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->temporaryPath !== '';
    }

    /**
     * Moves the uploaded file to the application-selected destination.
     *
     * The destination must include the filename. Never use the original client
     * filename as the destination without generating or validating it first.
     */
    public function moveTo(string $destination): void
    {
        if (!$this->isValid()) {
            throw new RuntimeException('Cannot move an invalid uploaded file.');
        }

        if ($destination === '' || !is_dir(dirname($destination))) {
            throw new RuntimeException('The upload destination directory does not exist.');
        }

        if (!is_uploaded_file($this->temporaryPath)) {
            throw new RuntimeException('The temporary file is not a valid HTTP upload.');
        }

        if (!move_uploaded_file($this->temporaryPath, $destination)) {
            throw new RuntimeException('Unable to move the uploaded file.');
        }
    }

    /**
     * @return resource
     */
    public function openStream()
    {
        if (!$this->isValid()) {
            throw new RuntimeException('Cannot open an invalid uploaded file.');
        }

        if (!is_uploaded_file($this->temporaryPath)) {
            throw new RuntimeException('The temporary file is not a valid HTTP upload.');
        }

        $stream = fopen($this->temporaryPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Unable to open the uploaded file.');
        }

        return $stream;
    }
}
