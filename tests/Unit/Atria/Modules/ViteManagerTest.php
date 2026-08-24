<?php

declare(strict_types=1);

use Atria\Modules\Vite\ViteConfig;
use Atria\Modules\Vite\Exceptions\ViteEntryNotFoundException;
use Atria\Modules\Vite\Exceptions\ViteManifestNotFoundException;
use Atria\Modules\Vite\ViteManager;

function viteTestManager(string $buildDir, array $entryPath): ViteManager
{
    return new ViteManager(new ViteConfig($entryPath, '/assets/', $buildDir));
}

test('tags in dev mode includes @vite/client and entry scripts', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_vite_test_' . uniqid();
    mkdir($tmpDir);
    mkdir($tmpDir . '/build');

    file_put_contents($tmpDir . '/build/hot', 'http://localhost:5173');

    $vite = viteTestManager($tmpDir . '/build', [
        'app/main.js',
        'app/style.css',
    ]);

    $tags = $vite->tags();

    expect($tags)->toContain('@vite/client');
    expect($tags)->toContain('http://localhost:5173/assets/app/main.js');
    expect($tags)->toContain('http://localhost:5173/assets/app/style.css');

    array_map('unlink', glob($tmpDir . '/build/*') ?: []);
    rmdir($tmpDir . '/build');
    rmdir($tmpDir);
});

test('tags in production mode uses manifest', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_vite_test_' . uniqid();
    mkdir($tmpDir);
    mkdir($tmpDir . '/build/.vite', 0o777, true);

    $manifest = [
        'app/main.js' => [
            'file' => 'assets/main-abc123.js',
            'css' => ['assets/main-def456.css'],
        ],
        'app/style.css' => [
            'file' => 'assets/style-ghi789.css',
        ],
    ];
    file_put_contents($tmpDir . '/build/.vite/manifest.json', json_encode($manifest));

    $vite = viteTestManager($tmpDir . '/build', [
        'app/main.js',
        'app/style.css',
    ]);

    $tags = $vite->tags();

    expect($tags)->toContain('/assets/assets/main-abc123.js');
    expect($tags)->toContain('/assets/assets/main-def456.css');
    expect($tags)->toContain('/assets/assets/style-ghi789.css');
    expect($tags)->not->toContain('@vite/client');

    array_map('unlink', glob($tmpDir . '/build/.vite/*') ?: []);
    rmdir($tmpDir . '/build/.vite');
    rmdir($tmpDir . '/build');
    rmdir($tmpDir);
});

test('tags throws when manifest file is missing in production', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_vite_test_' . uniqid();
    mkdir($tmpDir);
    mkdir($tmpDir . '/build/.vite', 0o777, true);
    // No manifest file

    $vite = viteTestManager($tmpDir . '/build', ['app/main.js']);

    $vite->tags();
})->throws(ViteManifestNotFoundException::class);

test('ViteConfig rejects a non-string entry path', function () {
    ViteConfig::fromArray(['entry_path' => ['app/main.js', 1], 'base_path' => '/assets/', 'build_dir' => '/tmp/build']);
})->throws(\InvalidArgumentException::class, 'Vite config entry_path must be an array of strings.');

test('tagsFor renders a specific entry without all default entries', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_vite_test_' . uniqid();
    mkdir($tmpDir);
    mkdir($tmpDir . '/build');

    file_put_contents($tmpDir . '/build/hot', 'http://localhost:5173');

    $vite = viteTestManager($tmpDir . '/build', [
        'app/main.js',
        'core/mercure.js',
    ]);

    $tags = $vite->tagsFor('core/mercure.js');

    expect($tags)->toContain('http://localhost:5173/assets/core/mercure.js')
        ->and($tags)->not->toContain('http://localhost:5173/assets/app/main.js');

    array_map('unlink', glob($tmpDir . '/build/*') ?: []);
    rmdir($tmpDir . '/build');
    rmdir($tmpDir);
});

test('tagsFor throws when an entry is absent from the manifest', function () {
    $tmpDir = sys_get_temp_dir() . '/atria-core_vite_test_' . uniqid();
    mkdir($tmpDir . '/build/.vite', 0o777, true);
    file_put_contents($tmpDir . '/build/.vite/manifest.json', '{}');

    viteTestManager($tmpDir . '/build', ['app/main.js'])->tags();
})->throws(ViteEntryNotFoundException::class);
