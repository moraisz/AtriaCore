<?php

declare(strict_types=1);

use Atria\Http\Request;
use Atria\Http\Response;
use Atria\Http\Router;
use Atria\Http\Exceptions\RouteDispatchException;
use Atria\System\Container;
use Atria\Http\AbstractClasses\Controller;
use Atria\Http\AbstractClasses\Middleware;
use Atria\Modules\Csrf\CsrfManager;
use Atria\Modules\Vite\ViteConfig;
use Atria\Modules\Vite\ViteManager;
use Atria\Modules\View\ViewConfig;
use Atria\Modules\View\ViewManager;

class RouterTestController extends Controller
{
    public function index(): Response
    {
        return $this->response->json(['called' => 'index']);
    }

    public function show(): Response
    {
        $id = $this->request->getParam('id');
        return $this->response->json(['called' => 'show', 'id' => $id]);
    }
}

class RouterTestMiddleware extends Middleware
{
    public function handle(Request $request, Response $response, callable $next): Response
    {
        $response->setHeader('X-Middleware', 'passed');
        return $next($request, $response);
    }
}

class RouterTestSecondMiddleware extends Middleware
{
    public function handle(Request $request, Response $response, callable $next): Response
    {
        $response->setHeader('X-Second', 'passed');
        return $next($request, $response);
    }
}

function createRouterWithView(): Router
{
    $container = new Container();
    $container->bind(RouterTestController::class);
    $container->bind(RouterTestMiddleware::class);
    $container->bind(RouterTestSecondMiddleware::class);

    $container->singleton(ViewManager::class, fn() => routerTestViewManager());

    return new Router($container);
}

function routerTestViewManager(): ViewManager
{
    return new ViewManager(
        new ViewConfig(__DIR__ . '/../../../app/Views'),
        new CsrfManager(),
        new ViteManager(new ViteConfig([], '/', sys_get_temp_dir())),
    );
}

test('matches route with closure callback', function () {
    $router = createRouterWithView();
    $router->get('/hello', function (Request $req, Response $res) {
        return $res->json(['from' => 'closure']);
    });

    $request = new Request();
    $ref = new ReflectionClass($request);
    $methodProp = $ref->getProperty('method');
    $methodProp->setValue($request, 'GET');
    $pathProp = $ref->getProperty('path');
    $pathProp->setValue($request, '/hello');

    $response = $router->run($request);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('{"from":"closure"}');
});

test('matches route with controller method', function () {
    $router = createRouterWithView();
    $router->get('/index', [RouterTestController::class, 'index']);

    $request = new Request();
    $ref = new ReflectionClass($request);
    $methodProp = $ref->getProperty('method');
    $methodProp->setValue($request, 'GET');
    $pathProp = $ref->getProperty('path');
    $pathProp->setValue($request, '/index');

    $response = $router->run($request);

    expect($response->getContent())->toBe('{"called":"index"}');
});

test('extracts named route parameters', function () {
    $router = createRouterWithView();
    $router->get('/user/{id}', [RouterTestController::class, 'show']);

    $request = new Request();
    $ref = new ReflectionClass($request);
    $methodProp = $ref->getProperty('method');
    $methodProp->setValue($request, 'GET');
    $pathProp = $ref->getProperty('path');
    $pathProp->setValue($request, '/user/42');

    $response = $router->run($request);

    expect($response->getContent())->toBe('{"called":"show","id":"42"}');
});

test('returns 404 for unmatched route with json request', function () {
    $router = createRouterWithView();
    $router->get('/exists', function (Request $req, Response $res) {
        return $res->json(['ok' => true]);
    });

    $request = new Request();
    $ref = new ReflectionClass($request);
    $methodProp = $ref->getProperty('method');
    $methodProp->setValue($request, 'GET');
    $pathProp = $ref->getProperty('path');
    $pathProp->setValue($request, '/nowhere');
    $serverProp = $ref->getProperty('server');
    $serverProp->setValue($request, ['HTTP_CONTENT_TYPE' => 'application/json']);

    $response = $router->run($request);

    expect($response->getStatusCode())->toBe(404);
    expect($response->getContent())->toContain('Route not found');
});

test('middleware chain executes in order', function () {
    $router = createRouterWithView();
    $router->get('/protected', function (Request $req, Response $res) {
        return $res->json(['ok' => true]);
    }, [RouterTestMiddleware::class, RouterTestSecondMiddleware::class]);

    $request = new Request();
    $ref = new ReflectionClass($request);
    $methodProp = $ref->getProperty('method');
    $methodProp->setValue($request, 'GET');
    $pathProp = $ref->getProperty('path');
    $pathProp->setValue($request, '/protected');

    $response = $router->run($request);

    expect($response->getHeader('X-Middleware'))->toBe('passed');
    expect($response->getHeader('X-Second'))->toBe('passed');
    expect($response->getContent())->toBe('{"ok":true}');
});

test('POST route only matches POST method', function () {
    $router = createRouterWithView();
    $router->post('/submit', function (Request $req, Response $res) {
        return $res->json(['posted' => true]);
    });

    $request = new Request();
    $ref = new ReflectionClass($request);
    $methodProp = $ref->getProperty('method');
    $methodProp->setValue($request, 'POST');
    $pathProp = $ref->getProperty('path');
    $pathProp->setValue($request, '/submit');
    $serverProp = $ref->getProperty('server');
    $serverProp->setValue($request, ['HTTP_CONTENT_TYPE' => 'application/json']);

    $response = $router->run($request);

    expect($response->getContent())->toBe('{"posted":true}');
});

test('POST route rejects GET request', function () {
    $router = createRouterWithView();
    $router->post('/submit', function (Request $req, Response $res) {
        return $res->json(['posted' => true]);
    });

    $request = new Request();
    $ref = new ReflectionClass($request);
    $methodProp = $ref->getProperty('method');
    $methodProp->setValue($request, 'GET');
    $pathProp = $ref->getProperty('path');
    $pathProp->setValue($request, '/submit');
    $serverProp = $ref->getProperty('server');
    $serverProp->setValue($request, ['HTTP_CONTENT_TYPE' => 'application/json']);

    $response = $router->run($request);

    expect($response->getStatusCode())->toBe(404);
});

test('middleware that halts chain returns early', function () {
    $haltingMiddleware = new class extends Middleware {
        public function handle(Request $request, Response $response, callable $next): Response
        {
            return $response->text('blocked', 403);
        }
    };

    $container = new Container();
    $container->bind($haltingMiddleware::class, fn() => $haltingMiddleware);
    $container->bind(ViewManager::class, fn() => routerTestViewManager());
    $router = new Router($container);

    $router->get('/blocked', function (Request $req, Response $res) {
        return $res->json(['never' => true]);
    }, [$haltingMiddleware::class]);

    $request = new Request();
    $ref = new ReflectionClass($request);
    $methodProp = $ref->getProperty('method');
    $methodProp->setValue($request, 'GET');
    $pathProp = $ref->getProperty('path');
    $pathProp->setValue($request, '/blocked');

    $response = $router->run($request);

    expect($response->getStatusCode())->toBe(403);
    expect($response->getContent())->toBe('blocked');
});

test('invalid controller binding throws route dispatch exception', function () {
    $container = new Container();
    $container->bind(RouterTestController::class, fn() => new stdClass());
    $router = new Router($container);

    $router->get('/broken', [RouterTestController::class, 'index']);

    $request = new Request();
    $ref = new ReflectionClass($request);
    $ref->getProperty('method')->setValue($request, 'GET');
    $ref->getProperty('path')->setValue($request, '/broken');

    $router->run($request);
})->throws(RouteDispatchException::class, 'Invalid controller');

test('invalid view binding throws route dispatch exception during controller dispatch', function () {
    $container = new Container();
    $container->bind(RouterTestController::class);
    $container->bind(ViewManager::class, fn() => new stdClass());
    $router = new Router($container);

    $router->get('/broken-view', [RouterTestController::class, 'index']);

    $request = new Request();
    $ref = new ReflectionClass($request);
    $ref->getProperty('method')->setValue($request, 'GET');
    $ref->getProperty('path')->setValue($request, '/broken-view');

    $router->run($request);
})->throws(RouteDispatchException::class, 'Invalid view');

test('invalid middleware binding throws route dispatch exception', function () {
    $container = new Container();
    $container->bind(RouterTestMiddleware::class, fn() => new stdClass());
    $router = new Router($container);

    $router->get('/broken-middleware', function (Request $req, Response $res) {
        return $res->json(['ok' => true]);
    }, [RouterTestMiddleware::class]);

    $request = new Request();
    $ref = new ReflectionClass($request);
    $ref->getProperty('method')->setValue($request, 'GET');
    $ref->getProperty('path')->setValue($request, '/broken-middleware');

    $router->run($request);
})->throws(RouteDispatchException::class, 'Invalid middleware');

test('missing html error view service throws route dispatch exception', function () {
    $container = new Container();
    $container->bind(ViewManager::class, fn() => new stdClass());
    $router = new Router($container);

    $request = new Request();
    $ref = new ReflectionClass($request);
    $ref->getProperty('method')->setValue($request, 'GET');
    $ref->getProperty('path')->setValue($request, '/nowhere');

    $router->run($request);
})->throws(RouteDispatchException::class, 'View service not available');

test('memory leak across repeated route dispatches', function () {
    $router = createRouterWithView();
    $router->get('/hello', function (Request $req, Response $res) {
        return $res->json(['msg' => 'ok']);
    });

    gc_collect_cycles();
    $startMemory = memory_get_usage();

    for ($i = 0; $i < 5000; $i++) {
        $request = new Request();
        $ref = new ReflectionClass($request);
        $methodProp = $ref->getProperty('method');
        $methodProp->setValue($request, 'GET');
        $pathProp = $ref->getProperty('path');
        $pathProp->setValue($request, '/hello');

        $response = $router->run($request);
    }

    gc_collect_cycles();
    $endMemory = memory_get_usage();

    $growth = $endMemory - $startMemory;
    expect($growth)->toBeLessThan(100_000);
});
