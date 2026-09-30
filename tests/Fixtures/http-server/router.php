<?php

declare(strict_types=1);

// Router for `php -S` used by HttpClientTest.

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/echo') {
    header('Content-Type: application/json');
    header('X-Multi: first');
    header('X-Multi: second', false);

    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'query' => $_GET,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
        'custom' => $_SERVER['HTTP_X_CUSTOM'] ?? null,
        'body' => file_get_contents('php://input'),
    ]);
    return true;
}

if ($path === '/sleep') {
    usleep((int) ($_GET['ms'] ?? 0) * 1000);
    echo 'slept';
    return true;
}

if (preg_match('#^/status/(\d{3})$#', (string) $path, $match) === 1) {
    http_response_code((int) $match[1]);
    echo 'status ' . $match[1];
    return true;
}

if ($path === '/redirect') {
    header('Location: /echo?redirected=1', true, 302);
    return true;
}

http_response_code(404);
return true;
