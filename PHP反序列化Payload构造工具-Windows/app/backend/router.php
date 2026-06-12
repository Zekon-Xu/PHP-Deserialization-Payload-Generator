<?php

require_once __DIR__ . '/phpggc_runner.php';

$root = realpath(__DIR__ . '/../..');
$runner = new PhpGgcRunner($root);

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

try {
    if (strpos($path, '/api/') === 0) {
        routeApi($runner, $path);
        exit;
    }

    if ($path === '/' || $path === '/index.html') {
        sendFile(findIndexHtml($root), 'text/html; charset=utf-8');
        exit;
    }

    $candidate = realpath($root . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $normalizedCandidate = $candidate ? str_replace('\\', '/', $candidate) : '';
    if ($candidate && strpos($normalizedCandidate, $normalizedRoot) === 0 && is_file($candidate)) {
        return false;
    }

    http_response_code(404);
    echo 'Not Found';
} catch (Throwable $error) {
    jsonResponse(['ok' => false, 'error' => $error->getMessage()], 500);
}

function routeApi(PhpGgcRunner $runner, string $path): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/env') {
        jsonResponse(['ok' => true, 'data' => $runner->env()]);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/chains') {
        jsonResponse(['ok' => true, 'data' => $runner->chains($_GET)]);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && strpos($path, '/api/chains/') === 0) {
        $chain = substr($path, strlen('/api/chains/'));
        jsonResponse(['ok' => true, 'data' => $runner->info($chain)]);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/generate') {
        $body = jsonInput();
        $result = $runner->generate(
            (string)($body['chain'] ?? ''),
            is_array($body['arguments'] ?? null) ? $body['arguments'] : [],
            is_array($body['options'] ?? null) ? $body['options'] : [],
            !empty($body['authorized'])
        );
        jsonResponse(['ok' => true, 'data' => $result]);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/audit') {
        $limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;
        jsonResponse(['ok' => true, 'data' => $runner->audit($limit)]);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/shutdown') {
        assertLocalControlRequest();
        requestWorkbenchControl(dirname(__DIR__, 2), 'shutdown');
        jsonResponse(['ok' => true, 'data' => ['message' => 'Local service is shutting down.']]);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/restart') {
        assertLocalControlRequest();
        requestWorkbenchControl(dirname(__DIR__, 2), 'restart');
        jsonResponse(['ok' => true, 'data' => ['message' => 'Local service is restarting.']]);
        return;
    }

    jsonResponse(['ok' => false, 'error' => 'API not found'], 404);
}

function assertLocalControlRequest(): void
{
    $header = $_SERVER['HTTP_X_LOCAL_WORKBENCH_CONTROL'] ?? ($_SERVER['HTTP_X_LOCAL_WORKBENCH_SHUTDOWN'] ?? '');
    if ($header !== 'confirm') {
        jsonResponse(['ok' => false, 'error' => 'Missing local service confirmation header'], 403);
        exit;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $expectedHttp = 'http://' . $host;
        $expectedHttps = 'https://' . $host;
        if ($origin !== $expectedHttp && $origin !== $expectedHttps) {
            jsonResponse(['ok' => false, 'error' => 'Cross-origin local service control is not allowed'], 403);
            exit;
        }
    }
}

function requestWorkbenchControl(string $root, string $action): void
{
    if ($action !== 'shutdown' && $action !== 'restart') {
        throw new InvalidArgumentException('Unsupported local service control action');
    }

    $shortcut = $root . DIRECTORY_SEPARATOR . 'Open-PHPGGC-Workbench.url';
    if (is_file($shortcut)) {
        @unlink($shortcut);
    }

    $runtime = $root . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($runtime)) {
        @mkdir($runtime, 0777, true);
    }

    $marker = $action === 'restart' ? 'restart.request' : 'shutdown.request';
    @file_put_contents($runtime . DIRECTORY_SEPARATOR . $marker, date('c'));
}

function jsonInput(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON request body');
    }
    return $data;
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function sendFile(string $path, string $contentType): void
{
    if (!is_file($path)) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

function findIndexHtml(string $root): string
{
    $files = glob($root . DIRECTORY_SEPARATOR . '*.html') ?: [];
    foreach ($files as $file) {
        if (strpos(basename($file), 'Payload') !== false) {
            return $file;
        }
    }
    if ($files) {
        return $files[0];
    }
    return $root . DIRECTORY_SEPARATOR . 'index.html';
}
