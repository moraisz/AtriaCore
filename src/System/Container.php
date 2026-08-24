<?php

declare(strict_types=1);

namespace Atria\System;

use Atria\System\Contracts\Resettable;
use ReflectionClass;
use ReflectionException;
use Exception;

class Container
{
    /**
     * @var array<string, mixed> Bindings of interfaces to implementations
     */
    private array $bindings = [];

    /**
     * @var array<string, bool> Singleton flags
     */
    private array $singletons = [];

    /**
     * @var array<string, object> Singleton instances
     */
    private array $instances = [];

    /** @var array<string, bool> Request-scoped flags */
    private array $scoped = [];

    /** @var array<string, object> Request-scoped instances */
    private array $scopedInstances = [];

    /**
     * Bind an interface to a concrete implementation
     *
     * @param string $abstract
     * @param callable|string|null $concrete
     * @return void
     */
    public function bind(string $abstract, callable|string|null $concrete = null): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        $this->bindings[$abstract] = $concrete;
        $this->singletons[$abstract] = false;
        unset($this->scoped[$abstract], $this->instances[$abstract], $this->scopedInstances[$abstract]);
    }

    /**
     * Bind a singleton instance
     *
     * @param string $abstract
     * @param callable|string|null $concrete
     * @return void
     */
    public function singleton(string $abstract, callable|string|null $concrete = null): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        $this->bindings[$abstract] = $concrete;
        $this->singletons[$abstract] = true;
        unset($this->scoped[$abstract], $this->instances[$abstract], $this->scopedInstances[$abstract]);
    }

    /**
     * Bind a service that is shared only for the current request.
     *
     * @param string $abstract
     * @param callable|string|null $concrete
     */
    public function scoped(string $abstract, callable|string|null $concrete = null): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        $this->bindings[$abstract] = $concrete;
        $this->singletons[$abstract] = false;
        $this->scoped[$abstract] = true;
        unset($this->instances[$abstract], $this->scopedInstances[$abstract]);
    }

    /**
     * Resolve a class from the container
     *
     * @param string $abstract
     * @return mixed
     * @throws ReflectionException
     * @throws Exception
     */
    public function make(string $abstract): mixed
    {
        if (($this->scoped[$abstract] ?? false) && isset($this->scopedInstances[$abstract])) {
            return $this->scopedInstances[$abstract];
        }

        // Check if we have a singleton instance
        if (($this->singletons[$abstract] ?? false) && isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Get the concrete implementation
        $concrete = $this->bindings[$abstract] ?? $abstract;

        // If concrete is a callable, call it
        if (is_callable($concrete)) {
            $instance = $concrete($this);
        } else {
            /** @var class-string $concreteClass */
            $concreteClass = $concrete;
            $instance = $this->build($concreteClass);
        }

        // Store singleton instances
        if ($this->singletons[$abstract] ?? false) {
            /** @var object $instance */
            $this->instances[$abstract] = $instance;
        }

        if ($this->scoped[$abstract] ?? false) {
            /** @var object $instance */
            $this->scopedInstances[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * Clears request-scoped services and resets opted-in persistent services.
     */
    public function flushRequestScope(): void
    {
        /** @var array<int, true> $reset */
        $reset = [];

        foreach ([$this->instances, $this->scopedInstances] as $services) {
            foreach ($services as $service) {
                if (!$service instanceof Resettable) {
                    continue;
                }

                $objectId = spl_object_id($service);

                if (isset($reset[$objectId])) {
                    continue;
                }

                $service->reset();
                $reset[$objectId] = true;
            }
        }

        $this->scopedInstances = [];
    }

    public function has(string $abstract): bool
    {
        return array_key_exists($abstract, $this->bindings) || array_key_exists($abstract, $this->instances);
    }

    /**
     * Build a concrete class instance with dependency injection
     *
     * @param string $concrete
     * @return object
     * @throws ReflectionException
     * @throws Exception
     */
    /**
     * @param class-string $concrete
     */
    private function build(string $concrete): object
    {
        $reflection = new ReflectionClass($concrete);

        if (!$reflection->isInstantiable()) {
            throw new Exception("Class {$concrete} is not instantiable");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type === null || !$type instanceof \ReflectionNamedType) {
                throw new Exception("Cannot resolve parameter {$parameter->getName()} in class {$concrete}");
            }

            $typeName = $type->getName();
            $dependencies[] = $this->make($typeName);
        }

        return $reflection->newInstanceArgs($dependencies);
    }
}
