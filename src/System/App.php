<?php

declare(strict_types=1);

namespace Atria\System;

use Atria\Http\Request;
use Atria\Http\HttpExceptionHandler;
use Atria\Database\Migrator;
use Atria\System\Container;
use Atria\System\Config;
use Atria\Http\Router;
use Atria\System\Contracts\WorkerRuntime;

class App
{
    private Config $config;
    private Router $router;
    private Container $container;
    private readonly WorkerRuntime $workerRuntime;

    public function __construct(private readonly string $configPath, ?WorkerRuntime $workerRuntime = null)
    {
        $this->workerRuntime = $workerRuntime ?? new FrankenPhpWorkerRuntime();
    }

    public function run(): void
    {
        $this->container = new Container();
        $this->container->singleton(Container::class, fn() => $this->container);
        $this->router = new Router($this->container);

        $this->config = new Config($this->configPath);
        $this->config->configureApp($this->container, $this->router);
        $this->container->singleton(Config::class, fn() => $this->config);

        if ($this->isFrankenPhpWorkerMode()) {
            $this->runWorkerMode();
        } else {
            $this->handleRequest();
        }
    }

    /**
     * Checks whether FrankenPHP worker mode is configured
     */
    private function isFrankenPhpWorkerMode(): bool
    {
        /** @var array{worker_mode: bool, early_hints: bool, max_requests: int} $frankenConfig */
        $frankenConfig = $this->config->getFrankenConfig();

        $workerMode = filter_var($frankenConfig['worker_mode'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return (bool) $workerMode;
    }

    /**
     * Runs the FrankenPHP worker loop
     */
    private function runWorkerMode(): void
    {
        /** @var array{worker_mode: bool, early_hints: bool, max_requests: int} $frankenConfig */
        $frankenConfig = $this->config->getFrankenConfig();

        $maxRequestsValue = $frankenConfig['max_requests'];
        $maxRequests = (int) $maxRequestsValue;

        // main loop to handle requests with FrankenPHP Worker mode
        for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
            $keepRunning = $this->workerRuntime->handle(function (): void {
                $this->handleRequest();
            });

            if (!$keepRunning) {
                break;
            }
        }
    }

    // handler function for FrankenPHP
    private function handleRequest(): void
    {
        $request = null;

        try {
            $frankenConfig = $this->config->getFrankenConfig();

            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            // get all request data
            $request = Request::createFromGlobals();

            // dispatch and get response
            $response = $this->router->run($request);

            // send response
            $response->send();
        } catch (\Throwable $e) {
            $exceptionHandler = $this->container->make(HttpExceptionHandler::class);

            if (!$exceptionHandler instanceof HttpExceptionHandler) {
                throw new \RuntimeException('Invalid HTTP exception handler binding.');
            }

            $errorResponse = $exceptionHandler->handle($e, $request);
            $errorResponse->send();
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $this->container->flushRequestScope();
            gc_collect_cycles();
        }
    }

    public function handleCommand(): int
    {
        global $argv;

        if (!isset($argv[1])) {
            echo "No command provided\n";
            return 1;
        }

        $this->container = new Container();

        $this->config = new Config($this->configPath);
        $this->config->configureCli($this->container);

        if ($argv[1] === 'migrate') {
            $config = $this->config;
            $migrator = $this->container->make(Migrator::class);

            if (!$migrator instanceof Migrator) {
                echo "Failed to create migrator\n";
                return 1;
            }

            if (isset($argv[2]) && $argv[2] === 'run') {
                echo "Starting migrations...\n";
                $migrator->run();
                return 0;
            }

            if (isset($argv[2]) && $argv[2] === 'rollback') {
                echo "Starting rollback migrations...\n";
                $steps = (int) ($argv[3] ?? 1);
                $migrator->rollback($steps);
                return 0;
            }

            echo "Unknown migrate command...\n";
            return 1;
        }

        if ($argv[1] === 'app_key') {
            if (isset($argv[2]) && $argv[2] === 'generate') {
                echo "Generate App Key...\n";
                echo base64_encode(random_bytes(32)) . "\n";
                return 0;
            }

            echo "Unknown app_key command...\n";
            return 1;
        }

        echo "Unknown command: {$argv[1]}\n";
        return 1;
    }
}
