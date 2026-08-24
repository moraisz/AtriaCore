<?php

declare(strict_types=1);

namespace Atria\Modules\Csrf\Middlewares;

use Atria\Http\AbstractClasses\Middleware;
use Atria\Http\Request;
use Atria\Http\Response;
use Atria\Modules\Csrf\Exceptions\CsrfTokenValidationException;
use Atria\Modules\Csrf\CsrfManager;

class CsrfMiddleware extends Middleware
{
    public function __construct(private readonly CsrfManager $csrfManager) {}

    /**
     * Handle CSRF token validation
     *
     * @param Request $request
     * @param callable $next
     * @return Response
     */
    public function handle(Request $request, Response $response, callable $next): Response
    {
        // Skip CSRF check for GET requests
        if ($request->getMethod() === 'GET') {
            $result = $next($request, $response);
            return $result instanceof Response ? $result : $response;
        }

        // Validate CSRF token for POST, PUT, DELETE, PATCH requests
        $token = $request->getBody('csrf_token') ?? $request->getHeader('X-CSRF-Token');

        if (!is_string($token) || !$this->csrfManager->validateToken($token)) {
            throw new CsrfTokenValidationException();
        }

        $result = $next($request, $response);
        $finalResponse = $result instanceof Response ? $result : $response;

        $finalResponse->setHeader('X-CSRF-Token', $this->csrfManager->rotateToken());

        return $finalResponse;
    }
}
