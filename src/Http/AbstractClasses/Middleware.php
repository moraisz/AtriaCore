<?php

declare(strict_types=1);

namespace Atria\Http\AbstractClasses;

use Atria\Http\Request;
use Atria\Http\Response;

abstract class Middleware
{
    /**
     * @param Request $request
     * @param Response $response
     * @param callable(Request, Response): Response $next
     */
    abstract public function handle(Request $request, Response $response, callable $next): Response;
}
