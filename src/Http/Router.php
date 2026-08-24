<?php

declare(strict_types=1);

namespace Atria\Http;

use Atria\Http\AbstractClasses\Middleware;
use Atria\Http\AbstractClasses\Controller;
use Atria\Http\Exceptions\RouteDispatchException;
use Atria\System\Container;
use Atria\Modules\View\ViewManager;
use Atria\Modules\Mercure\MercureManager;

class Router
{
    /** @var array<int, array<string, mixed>> */
    private array $routes = [];
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * @param callable(Request, Response): Response|array{class-string, string} $callback
     * @param array<int, class-string> $middleware
     */
    public function get(string $path, callable|array $callback, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $callback, $middleware);
    }

    /**
     * @param callable(Request, Response): Response|array{class-string, string} $callback
     * @param array<int, class-string> $middleware
     */
    public function post(string $path, callable|array $callback, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $callback, $middleware);
    }

    /**
     * @param callable(Request, Response): Response|array{class-string, string} $callback
     * @param array<int, class-string> $middleware
     */
    public function put(string $path, callable|array $callback, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $callback, $middleware);
    }

    /**
     * @param callable(Request, Response): Response|array{class-string, string} $callback
     * @param array<int, class-string> $middleware
     */
    public function delete(string $path, callable|array $callback, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $callback, $middleware);
    }

    /**
     * @param callable(Request, Response): Response|array{class-string, string} $callback
     * @param array<int, class-string> $middleware
     */
    private function addRoute(
        string $method,
        string $path,
        callable|array $callback,
        array $middleware = [],
    ): void {
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        /** @var array<string, mixed> $route */
        $route = [
            'method' => $method,
            'path' => $path,
            'pattern' => $pattern,
            'callback' => $callback,
            'middleware' => $middleware,
        ];
        $this->routes[] = $route;
    }

    public function run(Request $request): Response
    {
        // Create a response object
        $response = new Response()->setRequestContext($request);

        if ($this->container->has(MercureManager::class)) {
            $mercureManager = $this->container->make(MercureManager::class);

            if (!$mercureManager instanceof MercureManager) {
                throw new RouteDispatchException('Mercure manager not available');
            }

            $response->setMercureManager($mercureManager);
        }

        foreach ($this->routes as $route) {
            $routeMethod = is_string($route['method'] ?? null) ? $route['method'] : '';
            $routePattern = is_string($route['pattern'] ?? null) ? $route['pattern'] : '';

            if (
                $routeMethod === $request->getMethod()
                    && preg_match($routePattern, $request->getPath(), $matches)
            ) {
                // Remove indexed matches, keep only named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $request->setParams($params);

                /** @var array<int, class-string> $middlewares */
                $middlewares = $route['middleware'] ?? [];
                /** @var callable(Request, Response): Response|array{class-string, string} $callback */
                $callback = $route['callback'];

                $next = $this->buildRouteChain($middlewares, $callback);

                return $next($request, $response);
            }
        }

        // Route not found
        if ($request->isJson()) {
            return $response->json(['error' => 'Route not found'], 404);
        }

        $view = $this->container->make(ViewManager::class);

        if (!$view instanceof ViewManager) {
            throw new RouteDispatchException('View service not available');
        }

        $html = $view->render("pages/errors/404");
        return $response->html($html, 404);
    }

    /**
     * @param array<int, class-string> $middlewares
     * @param callable(Request, Response): Response|array{class-string, string} $callback
     * @return callable(Request, Response): Response
     */
    private function buildRouteChain(
        array $middlewares,
        callable|array $callback,
    ): callable {
        // Start with the final callback
        $next = function (Request $req, Response $res) use ($callback): Response {
            if (is_callable($callback)) {
                $result = call_user_func($callback, $req, $res);
                return $result instanceof Response ? $result : $res;
            }

            // $callback is array{class-string, string}
            /** @var class-string $controllerClass */
            $controllerClass = $callback[0];
            $methodName = $callback[1];

            $controller = $this->container->make($controllerClass);

            if (!$controller instanceof Controller) {
                throw new RouteDispatchException('Invalid controller');
            }

            $controller->setRequest($req);
            $view = $this->container->make(ViewManager::class);
            if (!$view instanceof ViewManager) {
                throw new RouteDispatchException('Invalid view');
            }
            $controller->setView($view);
            $controller->setResponse($res);

            $result = $controller->$methodName();
            return $result instanceof Response ? $result : $res;
        };

        // Wrap the callback with middlewares in reverse order
        foreach (array_reverse($middlewares) as $middlewareClass) {
            $currentNext = $next;

            $next = function (Request $req, Response $res) use ($middlewareClass, $currentNext): Response {
                $instance = $this->container->make($middlewareClass);
                if (!$instance instanceof Middleware) {
                    throw new RouteDispatchException('Invalid middleware');
                }

                return $instance->handle($req, $res, $currentNext);
            };
        }

        return $next;
    }
}
