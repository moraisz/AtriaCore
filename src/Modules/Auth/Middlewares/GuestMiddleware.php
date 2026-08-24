<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Middlewares;

use Atria\Http\AbstractClasses\Middleware;
use Atria\Modules\Auth\AuthManager;
use Atria\Modules\Auth\Exceptions\AuthenticationException;
use Atria\Modules\Auth\Exceptions\InvalidAccessTokenException;
use Atria\Http\Request;
use Atria\Http\Response;

final class GuestMiddleware extends Middleware
{
    public function __construct(private AuthManager $authManager) {}

    public function handle(Request $request, Response $response, callable $next): Response
    {
        $accessToken = $request->getCookie($this->authManager->accessCookieName());

        if ($accessToken === null) {
            return $next($request, $response);
        }

        try {
            $payload = $this->authManager->decodeAccessToken($accessToken);
            $expiresAt = $payload['exp'] ?? null;

            if (!is_int($expiresAt)) {
                throw new InvalidAccessTokenException();
            }

            if ($expiresAt > time() || $request->getCookie($this->authManager->refreshCookieName()) !== null) {
                return $response->redirect($this->authManager->redirectAuthenticatedTo());
            }
        } catch (AuthenticationException) {
        }

        $this->authManager->clearCookies($response);
        $result = $next($request, $response);

        return $result;
    }
}
