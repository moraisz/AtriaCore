<?php

declare(strict_types=1);

namespace Atria\Helpers;

class EnvHelper
{
    public static function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        return $value;
    }
}
