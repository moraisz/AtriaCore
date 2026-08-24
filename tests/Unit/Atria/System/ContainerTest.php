<?php

declare(strict_types=1);

use Atria\System\Container;

class ContainerTestFoo {}

class ContainerTestBar
{
    public function __construct(public ContainerTestFoo $foo) {}
}

test('bind self resolves same class', function () {
    $c = new Container();
    $c->bind(ContainerTestFoo::class);
    $instance = $c->make(ContainerTestFoo::class);

    expect($instance)->toBeInstanceOf(ContainerTestFoo::class);
});

test('make without binding auto-resolves via reflection', function () {
    $c = new Container();
    $instance = $c->make(ContainerTestFoo::class);

    expect($instance)->toBeInstanceOf(ContainerTestFoo::class);
});

test('bind interface to concrete resolves implementation', function () {
    $interface = ContainerTestFoo::class;
    $concrete = ContainerTestFoo::class;
    $c = new Container();
    $c->bind($interface, $concrete);
    $instance = $c->make($interface);

    expect($instance)->toBeInstanceOf($concrete);
});

test('bind with callable factory', function () {
    $c = new Container();
    $c->bind('test-key', fn() => new ContainerTestFoo());

    expect($c->make('test-key'))->toBeInstanceOf(ContainerTestFoo::class);
});

test('singleton returns same instance on multiple resolves', function () {
    $c = new Container();
    $c->singleton(ContainerTestFoo::class);
    $first = $c->make(ContainerTestFoo::class);
    $second = $c->make(ContainerTestFoo::class);

    expect($first)->toBe($second);
});

test('singleton with callable factory is called only once', function () {
    $c = new Container();
    $count = 0;
    $c->singleton('counted', function () use (&$count) {
        $count++;
        return new ContainerTestFoo();
    });

    $c->make('counted');
    $c->make('counted');
    $c->make('counted');

    expect($count)->toBe(1);
});

test('bind non-singleton returns different instances', function () {
    $c = new Container();
    $c->bind(ContainerTestFoo::class);
    $first = $c->make(ContainerTestFoo::class);
    $second = $c->make(ContainerTestFoo::class);

    expect($first)->not->toBe($second);
});

test('build resolves typed constructor dependencies', function () {
    $c = new Container();
    $bar = $c->make(ContainerTestBar::class);

    expect($bar)->toBeInstanceOf(ContainerTestBar::class);
    expect($bar->foo)->toBeInstanceOf(ContainerTestFoo::class);
});

test('build throws on untyped constructor parameter', function () {
    $class = new class ('forced') {
        public function __construct(public $untyped) {}
    };

    $c = new Container();
    $c->make($class::class);
})->throws(\Exception::class);

test('make throws on non-instantiable class', function () {
    $c = new Container();
    $c->make(\Throwable::class);
})->throws(\Exception::class);

// Memory leak tests

test('no memory leak after repeated non-singleton resolves', function () {
    $c = new Container();
    $c->bind(ContainerTestFoo::class);

    gc_collect_cycles();
    $startMemory = memory_get_usage();

    for ($i = 0; $i < 5000; $i++) {
        $instance = $c->make(ContainerTestFoo::class);
    }

    gc_collect_cycles();
    $endMemory = memory_get_usage();

    $growth = $endMemory - $startMemory;
    expect($growth)->toBeLessThan(50_000);
});

test('no memory leak with singleton closure cycle', function () {
    $c = new Container();
    $c->singleton(Container::class, fn() => $c);

    gc_collect_cycles();
    $startMemory = memory_get_usage();

    for ($i = 0; $i < 10000; $i++) {
        $resolved = $c->make(Container::class);
    }

    gc_collect_cycles();
    $endMemory = memory_get_usage();

    $growth = $endMemory - $startMemory;
    expect($growth)->toBeLessThan(20_000);
});

test('no memory leak resolving dependencies across many requests', function () {
    $c = new Container();

    gc_collect_cycles();
    $startMemory = memory_get_usage();

    for ($i = 0; $i < 5000; $i++) {
        $bar = $c->make(ContainerTestBar::class);
    }

    gc_collect_cycles();
    $endMemory = memory_get_usage();

    $growth = $endMemory - $startMemory;
    expect($growth)->toBeLessThan(50_000);
});
