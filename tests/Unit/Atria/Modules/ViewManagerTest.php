<?php

declare(strict_types=1);

use Atria\Modules\Csrf\CsrfManager;
use Atria\Modules\Vite\ViteConfig;
use Atria\Modules\Vite\ViteManager;
use Atria\Modules\View\ViewConfig;
use Atria\Modules\View\Exceptions\InvalidViewPathException;
use Atria\Modules\View\Exceptions\ViewNotFoundException;
use Atria\Modules\View\ViewManager;

function createView(string $viewsPath): ViewManager
{
    return new ViewManager(
        new ViewConfig($viewsPath),
        new CsrfManager(),
        new ViteManager(new ViteConfig([], '/', sys_get_temp_dir())),
    );
}

test('e escapes HTML entities', function () {
    expect(createView(sys_get_temp_dir())->e('<script>alert("xss")</script>'))
        ->toBe('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;');
});

test('e converts int and float to string', function () {
    expect(createView(sys_get_temp_dir())->e(42))->toBe('42');
    expect(createView(sys_get_temp_dir())->e(3.14))->toBe('3.14');
});

test('e converts true to 1 and false to empty', function () {
    expect(createView(sys_get_temp_dir())->e(true))->toBe('1');
    expect(createView(sys_get_temp_dir())->e(false))->toBe('');
});

test('e returns empty for array and object', function () {
    expect(createView(sys_get_temp_dir())->e(['a' => 1]))->toBe('');
    expect(createView(sys_get_temp_dir())->e(new stdClass()))->toBe('');
});

test('e returns empty for null', function () {
    expect(createView(sys_get_temp_dir())->e(null))->toBe('');
});

test('ViewConfig validates the configured views path', function () {
    ViewConfig::fromArray(['views' => 123]);
})->throws(InvalidArgumentException::class, 'View config views must be a string.');

test('render simple view without layout', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_view_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/hello.php', '<h1><?= $title ?></h1>');

    $view = createView($tmpDir);
    $html = $view->render('hello', ['title' => 'World']);

    expect($html)->toBe('<h1>World</h1>');

    unlink($tmpDir . '/hello.php');
    rmdir($tmpDir);
});

test('render with layout and sections', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_view_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/child.php', '<?php $this->extends("layout"); ?><?php $this->section("content"); ?><p>body</p><?php $this->endSection(); ?>');
    file_put_contents($tmpDir . '/layout.php', '<html><?= $this->yield("content") ?></html>');

    $view = createView($tmpDir);
    $html = $view->render('child');

    expect($html)->toBe('<html><p>body</p></html>');

    array_map('unlink', glob($tmpDir . '/*.*') ?: []);
    rmdir($tmpDir);
});

test('render with layout and default section fallback', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_view_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/child.php', '<?php $this->extends("layout"); ?>');
    file_put_contents($tmpDir . '/layout.php', '<?= $this->yield("content", "default text") ?>');

    $view = createView($tmpDir);
    $html = $view->render('child');

    expect($html)->toBe('default text');

    unlink($tmpDir . '/child.php');
    unlink($tmpDir . '/layout.php');
    rmdir($tmpDir);
});

test('render passes extracted data to view file', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_view_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/data.php', '<?= $user ?? "no-user" ?>');

    $view = createView($tmpDir);
    $html = $view->render('data', ['user' => 'Alice']);

    expect($html)->toBe('Alice');

    unlink($tmpDir . '/data.php');
    rmdir($tmpDir);
});

test('render resets state between calls', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_view_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/first.php', '<?php $this->extends("layout"); ?><?php $this->section("a"); ?>A<?php $this->endSection(); ?>');
    file_put_contents($tmpDir . '/layout.php', '<?= $this->yield("a") ?>');
    file_put_contents($tmpDir . '/second.php', '<p>plain</p>');

    $view = createView($tmpDir);
    $view->render('first'); // sets layout, sections
    $html = $view->render('second'); // should not leak layout

    expect($html)->toBe('<p>plain</p>');
    expect($view->getLayout())->toBeNull();

    array_map('unlink', glob($tmpDir . '/*.*') ?: []);
    rmdir($tmpDir);
});

test('section without endSection silently discards content', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_view_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/broken.php', '<?php $this->extends("layout"); ?><?php $this->section("content"); ?>lost content');
    file_put_contents($tmpDir . '/layout.php', '<?= $this->yield("content", "default") ?>');

    $view = createView($tmpDir);
    $html = $view->render('broken');

    expect($html)->toBe('default');

    array_map('unlink', glob($tmpDir . '/*.*') ?: []);
    rmdir($tmpDir);
})->skip('Pest marks as risky due to nested ob_start in section without endSection');

test('endSection without open section throws', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_view_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/broken.php', '<?php $this->extends("layout"); ?><?php $this->endSection(); ?>');
    file_put_contents($tmpDir . '/layout.php', 'empty');

    $view = createView($tmpDir);
    $view->render('broken');
})->throws(\LogicException::class);

test('render throws when a view is not found', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_view_test_' . uniqid();
    mkdir($tmpDir);

    createView($tmpDir)->render('missing');
})->throws(ViewNotFoundException::class);

test('render throws on path traversal attempt', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_view_test_' . uniqid();
    mkdir($tmpDir);
    $externalFile = dirname($tmpDir) . '/atria-core_external_view_' . uniqid() . '.php';
    file_put_contents($externalFile, 'external');

    try {
        createView($tmpDir)->render('../' . basename($externalFile, '.php'));
        test()->fail('Expected InvalidViewPathException was not thrown');
    } catch (InvalidViewPathException) {
        expect(true)->toBeTrue();
    } finally {
        unlink($externalFile);
        rmdir($tmpDir);
    }
});

test('e memory leak across repeated calls', function () {
    gc_collect_cycles();
    $startMemory = memory_get_usage();

    for ($i = 0; $i < 10000; $i++) {
        $view ??= createView(sys_get_temp_dir());
        $view->e('<script>alert(' . $i . ')</script>');
    }

    gc_collect_cycles();
    $endMemory = memory_get_usage();

    $growth = $endMemory - $startMemory;
    expect($growth)->toBeLessThan(20_000);
});
