<?php

declare(strict_types=1);

namespace Atria\System\Contracts;

interface Resettable
{
    public function reset(): void;
}
