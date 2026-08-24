<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Middlewares;

use Atria\Http\AbstractClasses\Middleware;
use Atria\Modules\Auth\AuthManager;
use Atria\Modules\Auth\Exceptions\AuthenticationException;
use Atria\Modules\Auth\Exceptions\InvalidAccessTokenException;
use Atria\Modules\Auth\Exceptions\InvalidRefreshTokenException;
use Atria\Http\Request;
use Atria\Http\Response;

final class AuthMiddleware extends Middleware
{
    public function __construct(private AuthManager $authManager) {}

    public function handle(Request $request, Response $response, callable $next): Response
    {
        try {
            $accessToken = $request->getCookie($this->authManager->accessCookieName());

            if ($accessToken === null) {
                throw new InvalidAccessTokenException('Access token not found');
            }

            $payload = $this->authManager->decodeAccessToken($accessToken);
            $tokens = null;
            $accessExpiresAt = $payload['exp'] ?? null;

            if (!is_int($accessExpiresAt)) {
                throw new InvalidAccessTokenException();
            }

            if ($accessExpiresAt <= time()) {
                $refreshToken = $request->getCookie($this->authManager->refreshCookieName());

                if ($refreshToken === null) {
                    throw new InvalidRefreshTokenException('Refresh token not found');
                }

                $tokens = $this->authManager->refresh($refreshToken, $request->getHeader('User-Agent') ?? 'unknown');
                $payload = $this->authManager->decodeAccessToken($tokens['access_token']);
            }

            $user = $this->authManager->authenticatedUserFromPayload($payload);
            $request->setAttribute('auth_user', $user);
            $request->setAttribute('auth_user_id', $user->id);
            $request->setAttribute('auth_email', $user->email);

            $result = $next($request, $response);

            if ($tokens !== null) {
                $this->authManager->attachCookies($result, $tokens);
            }

            return $result;
        } catch (AuthenticationException) {
            $this->authManager->clearCookies($response);

            return $response->redirect($this->authManager->redirectGuestTo());
        }
    }
}
