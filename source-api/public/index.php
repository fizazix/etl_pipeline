<?php

declare(strict_types=1);

const RATE_LIMIT_PER_SECOND = 5;
const STATE_DIR = '/source-api-state';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

match ($path) {
    '/health' => handleHealth(),
    '/records' => handleRecords(),
    default => respondJson(404, ['error' => 'Not found']),
};

function handleHealth(): void
{
    respondJson(200, ['status' => 'ok']);
}

function handleRecords(): void
{
    $cursor = $_GET['cursor'] ?? '0';
    $limit = $_GET['limit'] ?? '50';

    $validationError = validatePagination($cursor, $limit);
    if ($validationError !== null) {
        respondJson(422, ['error' => $validationError]);

        return;
    }

    $stateDir = stateDirectory();

    if (! checkRateLimit($stateDir)) {
        respondJson(429, ['error' => 'Rate limit exceeded'], ['Retry-After' => '1']);

        return;
    }

    $transientFailure = checkTransientFailure($stateDir, $cursor);
    if ($transientFailure !== null) {
        respondJson(
            $transientFailure['status'],
            $transientFailure['body'],
            $transientFailure['headers'] ?? []
        );

        return;
    }

    $dataset = require dirname(__DIR__).'/data/records.php';
    $offset = (int) $cursor;
    $pageSize = (int) $limit;
    $data = array_slice($dataset, $offset, $pageSize);
    $nextOffset = $offset + $pageSize;
    $hasMore = $nextOffset < count($dataset);

    respondJson(200, [
        'data' => $data,
        'next_cursor' => $hasMore ? (string) $nextOffset : null,
        'has_more' => $hasMore,
    ]);
}

function validatePagination(string $cursor, string $limit): ?string
{
    if (! ctype_digit($cursor)) {
        return 'cursor must be a non-negative integer';
    }

    if (! ctype_digit($limit) || (int) $limit < 1) {
        return 'limit must be an integer between 1 and 100';
    }

    if ((int) $limit > 100) {
        return 'limit must be an integer between 1 and 100';
    }

    return null;
}

function stateDirectory(): string
{
    $stateDir = sys_get_temp_dir().STATE_DIR;

    if (! is_dir($stateDir)) {
        mkdir($stateDir, 0777, true);
    }

    return $stateDir;
}

function checkRateLimit(string $stateDir): bool
{
    $bucket = (string) time();
    $file = $stateDir.'/rate-'.$bucket.'.txt';
    $count = file_exists($file) ? (int) file_get_contents($file) : 0;

    if ($count >= RATE_LIMIT_PER_SECOND) {
        return false;
    }

    file_put_contents($file, (string) ($count + 1));

    return true;
}

function checkTransientFailure(string $stateDir, string $cursor): ?array
{
    $failures = [
        '100' => ['status' => 500, 'body' => ['error' => 'Transient server failure']],
        '200' => ['status' => 429, 'body' => ['error' => 'Transient rate limit failure'], 'headers' => ['Retry-After' => '1']],
    ];

    if (! array_key_exists($cursor, $failures)) {
        return null;
    }

    $attempt = incrementAttempt($stateDir, 'cursor-'.$cursor);

    if ($attempt === 1) {
        return $failures[$cursor];
    }

    return null;
}

function incrementAttempt(string $stateDir, string $key): int
{
    $file = $stateDir.'/'.$key.'.txt';
    $attempt = (file_exists($file) ? (int) file_get_contents($file) : 0) + 1;
    file_put_contents($file, (string) $attempt);

    return $attempt;
}

function respondJson(int $status, array $body, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');

    foreach ($headers as $name => $value) {
        header($name.': '.$value);
    }

    echo json_encode($body);
    exit;
}
