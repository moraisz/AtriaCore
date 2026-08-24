<?php

declare(strict_types=1);

namespace Atria\Http;

use Atria\Modules\Csrf\Exceptions\CsrfTokenValidationException;
use Atria\Http\Exceptions\HttpException;
use Atria\Modules\Mercure\Exceptions\MercureTransportException;
use Atria\Modules\Csrf\CsrfManager;

final class HttpExceptionHandler
{
    public function __construct(private readonly CsrfManager $csrfManager) {}

    public function handle(\Throwable $exception, ?Request $request): Response
    {
        if ($exception instanceof MercureTransportException) {
            return (new Response())->json([
                'error' => $exception->getMessage(),
            ], 502);
        }

        if (
            $exception instanceof HttpException
            && $request?->getMethod() === 'POST'
            && !$request->isJson()
        ) {
            $_SESSION['error'] = $exception->getMessage();
            return (new Response())->redirect($this->redirectPath($request));
        }

        $statusCode = $exception instanceof HttpException ? $exception->getStatusCode() : 500;
        $response = (new Response())->json([
            'error' => $statusCode === 500 ? 'Internal Server Error' : $exception->getMessage(),
        ], $statusCode);

        if (
            $exception instanceof CsrfTokenValidationException
            && $request?->isJson()
        ) {
            $response->setHeader('X-CSRF-Token', $this->csrfManager->rotateToken());
        }

        return $response;
    }

    private function redirectPath(Request $request): string
    {
        $refererPath = parse_url($request->getHeader('Referer') ?? '', PHP_URL_PATH);
        if (is_string($refererPath) && str_starts_with($refererPath, '/') && !str_starts_with($refererPath, '//')) {
            return $refererPath;
        }

        return $request->getPath() === '/logout' ? '/' : $request->getPath();
    }
}
