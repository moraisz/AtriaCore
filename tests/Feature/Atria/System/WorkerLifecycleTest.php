<?php

declare(strict_types=1);

use Atria\Http\AbstractClasses\Controller;
use Atria\Http\Request;
use Atria\Http\Response;
use Atria\Http\Router;
use Atria\System\App;
use Atria\System\Contracts\Resettable;
use Atria\System\Contracts\WorkerRuntime;
use Atria\System\Config;
use Atria\System\Container;

final class WorkerLifecyclePayload
{
    public function __construct(public string $contents) {}
}

final class WorkerLifecycleController extends Controller
{
    public static ?WeakReference $payloadReference = null;

    public function render(): Response
    {
        $payload = new WorkerLifecyclePayload(str_repeat('x', 1_000_000));
        self::$payloadReference = WeakReference::create($payload);

        return $this->renderView('payload', ['payload' => $payload]);
    }

    public function renderThenFail(): Response
    {
        $payload = new WorkerLifecyclePayload(str_repeat('x', 1_000_000));
        self::$payloadReference = WeakReference::create($payload);
        $this->view->render('payload', ['payload' => $payload]);

        throw new RuntimeException('Expected lifecycle failure.');
    }
}

final class WorkerLifecycleRoutes
{
    public static function register(Router $router): void
    {
        $router->get('/render', [WorkerLifecycleController::class, 'render']);
        $router->get('/fail', [WorkerLifecycleController::class, 'renderThenFail']);
        $router->get('/json', static fn(Request $request, Response $response): Response => $response->json(['ok' => true]));
    }
}

final class WorkerLifecycleFakeRuntime implements WorkerRuntime
{
    /** @var list<string> */
    private array $paths;

    /** @param list<string> $paths */
    public function __construct(array $paths)
    {
        $this->paths = $paths;
    }

    public function handle(callable $handler): bool
    {
        $path = array_shift($this->paths);

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
        ];

        $handler();

        return $this->paths !== [];
    }
}

final class WorkerLifecycleResettable implements Resettable
{
    public int $resetCalls = 0;

    public function reset(): void
    {
        ++$this->resetCalls;
    }
}

function workerLifecycleConfigPath(): string
{
    return dirname(__DIR__, 3) . '/Fixtures/worker-lifecycle/config';
}

beforeEach(function () {
    putenv('AUTH_DRIVER=off');
    putenv('DB_CONNECTION=pgsql');
    putenv('MERCURE_ENABLED=0');
    WorkerLifecycleController::$payloadReference = null;
});

afterEach(function () {
    putenv('AUTH_DRIVER');
    putenv('DB_CONNECTION');
    putenv('MERCURE_ENABLED');
    WorkerLifecycleController::$payloadReference = null;
    $_GET = [];
    $_POST = [];
    $_COOKIE = [];
    $_SERVER = [];
});

test('flushRequestScope releases scoped instances and resets resettable services', function () {
    $container = new Container();
    $container->scoped(WorkerLifecyclePayload::class, static fn(): WorkerLifecyclePayload => new WorkerLifecyclePayload('request'));
    $container->singleton(WorkerLifecycleResettable::class);

    $payload = $container->make(WorkerLifecyclePayload::class);
    $resettable = $container->make(WorkerLifecycleResettable::class);

    expect($payload)->toBeInstanceOf(WorkerLifecyclePayload::class);
    expect($resettable)->toBeInstanceOf(WorkerLifecycleResettable::class);

    $reference = WeakReference::create($payload);
    unset($payload);

    $container->flushRequestScope();
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
    expect($resettable->resetCalls)->toBe(1);
    expect($container->make(WorkerLifecyclePayload::class))->toBeInstanceOf(WorkerLifecyclePayload::class);
});

test('config registers custom scoped services', function () {
    $container = new Container();
    $config = new Config(workerLifecycleConfigPath());

    $config->configureCli($container);
    $first = $container->make(stdClass::class);
    $container->flushRequestScope();
    $second = $container->make(stdClass::class);

    expect($first)->toBeInstanceOf(stdClass::class);
    expect($second)->toBeInstanceOf(stdClass::class);
    expect($second)->not->toBe($first);
});

test('worker releases view payloads between requests', function () {
    $app = new App(
        workerLifecycleConfigPath(),
        new WorkerLifecycleFakeRuntime(['/render', '/json']),
    );

    ob_start();
    $app->run();
    ob_end_clean();

    gc_collect_cycles();

    expect(WorkerLifecycleController::$payloadReference)->not->toBeNull();
    expect(WorkerLifecycleController::$payloadReference?->get())->toBeNull();
});

test('worker releases view payloads after an exception', function () {
    $app = new App(
        workerLifecycleConfigPath(),
        new WorkerLifecycleFakeRuntime(['/fail', '/json']),
    );

    ob_start();
    $app->run();
    ob_end_clean();

    gc_collect_cycles();

    expect(WorkerLifecycleController::$payloadReference)->not->toBeNull();
    expect(WorkerLifecycleController::$payloadReference?->get())->toBeNull();
});
