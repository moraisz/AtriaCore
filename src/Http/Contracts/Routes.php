<?php

declare(strict_types=1);

namespace Atria\Http\Contracts;

use Atria\Http\Router;

interface Routes
{
    public static function register(Router $router): void;
}
