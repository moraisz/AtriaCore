<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Data;

final readonly class AuthenticatedPrincipal
{
    public function __construct(
        public int $id,
        public string $email,
        public ?string $name = null,
    ) {}
}
