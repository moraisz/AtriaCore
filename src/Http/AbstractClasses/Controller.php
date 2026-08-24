<?php

declare(strict_types=1);

namespace Atria\Http\AbstractClasses;

use Atria\Http\Request;
use Atria\Http\Response;
use Atria\Modules\View\ViewManager;

abstract class Controller
{
    protected Request $request;
    protected Response $response;
    protected ViewManager $view;

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }

    public function setView(ViewManager $view): void
    {
        $this->view = $view;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function renderView(string $viewPath, array $data = [], int $statusCode = 200): Response
    {
        $view = $this->view;
        $html = $view->render($viewPath, $data);
        return $this->response->html($html, $statusCode);
    }

    protected function redirect(string $url, int $statusCode = 302): Response
    {
        return $this->response->redirect($url, $statusCode);
    }

    /**
     * @param array<mixed> $data
     */
    protected function jsonResponse(array $data, int $statusCode = 200): Response
    {
        return $this->response->json($data, $statusCode);
    }

    protected function cookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): void {
        $this->response->setCookie(
            name: $name,
            value: $value,
            expires: $expires,
            path: $path,
            domain: $domain,
            secure: $secure,
            httpOnly: $httpOnly,
            sameSite: $sameSite,
        );
    }
}
