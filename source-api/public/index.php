<?php

declare(strict_types=1);

$records = require dirname(__DIR__).'/data/records.php';

$cursor = $_GET['cursor'] ?? null;
if ($cursor === '') {
    $cursor = null;
}

$stateDir = sys_get_temp_dir().'/source-api-state';
if (! is_dir($stateDir)) {
    mkdir($stateDir, 0777, true);
}

function readAttempt(string $stateDir, string $key): int
{
    $file = $stateDir.'/'.$key.'.txt';

    if (! file_exists($file)) {
        return 0;
    }

    return (int) file_get_contents($file);
}

function writeAttempt(string $stateDir, string $key, int $attempt): void
{
    file_put_contents($stateDir.'/'.$key.'.txt', (string) $attempt);
}

header('Content-Type: application/json');

if ($cursor === null) {
    $cursor = 'page-1';
}

if ($cursor === 'fail-500') {
    $attempt = readAttempt($stateDir, 'fail-500') + 1;
    writeAttempt($stateDir, 'fail-500', $attempt);

    if ($attempt < 3) {
        http_response_code(500);
        echo json_encode(['error' => 'Transient server failure', 'attempt' => $attempt]);
        exit;
    }

    $cursor = 'page-3';
}

if ($cursor === 'fail-429') {
    $attempt = readAttempt($stateDir, 'fail-429') + 1;
    writeAttempt($stateDir, 'fail-429', $attempt);

    if ($attempt < 2) {
        http_response_code(429);
        header('Retry-After: 1');
        echo json_encode(['error' => 'Rate limit exceeded', 'attempt' => $attempt]);
        exit;
    }

    $cursor = 'page-4';
}

if (! array_key_exists($cursor, $records)) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown cursor: '.$cursor]);
    exit;
}

$page = $records[$cursor];

echo json_encode([
    'data' => $page['data'],
    'next_cursor' => $page['next_cursor'],
]);
