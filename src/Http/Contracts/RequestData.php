<?php

declare(strict_types=1);

namespace Atria\Http\Contracts;

use Atria\Http\Request;

interface RequestData
{
    public static function fromRequest(Request $request): self;
}
