<?php

namespace App\Services\Ingestion;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SourceApiClient
{
    private const RETRYABLE_STATUS_CODES = [408, 429, 500, 502, 503, 504];

    private const NON_RETRYABLE_STATUS_CODES = [400, 401, 403, 404, 422];

    private ?float $lastRequestAt = null;

    public function __construct(
        private $sleeper = null,
    ) {}

    public function fetchPage(?string $cursor, int $limit): array
    {
        $url = config('ingestion.source_api_url');
        $maxAttempts = (int) config('ingestion.max_attempts');
        $timeout = (int) config('ingestion.request_timeout_seconds');

        $query = ['limit' => $limit];

        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }

        $attempt = 1;
        $lastException = null;
        $lastStatus = null;

        while ($attempt <= $maxAttempts) {
            $this->paceBeforeRequest();
            $this->lastRequestAt = microtime(true);

            try {
                $response = $this->performGet($url, $query, $timeout);

                if ($response->successful()) {
                    return $this->validateAndNormalizeEnvelope($response);
                }

                $status = $response->status();
                $lastStatus = $status;

                if (in_array($status, self::NON_RETRYABLE_STATUS_CODES, true)) {
                    throw new RuntimeException(
                        'Source API returned HTTP '.$status.': '.$this->responseSnippet($response)
                    );
                }

                if (! in_array($status, self::RETRYABLE_STATUS_CODES, true)) {
                    throw new RuntimeException(
                        'Source API returned HTTP '.$status.': '.$this->responseSnippet($response)
                    );
                }

                if ($attempt >= $maxAttempts) {
                    Log::error('Source API request exhausted retry attempts.', [
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'status' => $status,
                    ]);

                    throw new RuntimeException(
                        'Source API returned HTTP '.$status.' after '.$maxAttempts.' attempts.'
                    );
                }

                Log::warning('Source API transient HTTP failure, retrying.', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'status' => $status,
                ]);

                $this->delayBeforeRetry($attempt, $status === 429 ? $response->header('Retry-After') : null);
                $attempt++;
            } catch (InvalidSourceApiEnvelopeException $exception) {
                throw $exception;
            } catch (ConnectionException|RequestException $exception) {
                $lastException = $exception;

                if ($attempt >= $maxAttempts) {
                    Log::error('Source API request exhausted retry attempts.', [
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);

                    throw new RuntimeException(
                        'Source API request failed after '.$maxAttempts.' attempts: '.$exception->getMessage(),
                        0,
                        $exception
                    );
                }

                Log::warning('Source API transient request failure, retrying.', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                $this->delayBeforeRetry($attempt, null);
                $attempt++;
            } catch (RuntimeException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Source API request failed: '.$exception->getMessage(),
                    0,
                    $exception
                );
            }
        }

        if ($lastException !== null) {
            throw new RuntimeException(
                'Source API request failed after '.$maxAttempts.' attempts: '.$lastException->getMessage(),
                0,
                $lastException
            );
        }

        throw new RuntimeException(
            'Source API returned HTTP '.$lastStatus.' after '.$maxAttempts.' attempts.'
        );
    }

    protected function performGet(string $url, array $query, int $timeout): Response
    {
        return Http::timeout($timeout)
            ->acceptJson()
            ->get($url, $query);
    }

    private function validateAndNormalizeEnvelope(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            Log::warning('Source API returned an invalid response envelope.', [
                'status' => $response->status(),
                'reason' => 'response is not valid JSON object',
            ]);

            throw new InvalidSourceApiEnvelopeException('Source API response is not valid JSON.');
        }

        if (! array_key_exists('data', $body) || ! is_array($body['data'])) {
            Log::warning('Source API returned an invalid response envelope.', [
                'status' => $response->status(),
                'reason' => 'data is missing or not an array',
            ]);

            throw new InvalidSourceApiEnvelopeException('Source API response is missing a valid data array.');
        }

        if (! array_key_exists('has_more', $body) || ! is_bool($body['has_more'])) {
            Log::warning('Source API returned an invalid response envelope.', [
                'status' => $response->status(),
                'reason' => 'has_more is missing or not boolean',
            ]);

            throw new InvalidSourceApiEnvelopeException('Source API response is missing a valid has_more flag.');
        }

        $hasMore = $body['has_more'];
        $nextCursor = array_key_exists('next_cursor', $body) ? $body['next_cursor'] : null;

        if ($hasMore && $nextCursor === null) {
            Log::warning('Source API returned an invalid response envelope.', [
                'status' => $response->status(),
                'reason' => 'has_more is true but next_cursor is missing',
            ]);

            throw new InvalidSourceApiEnvelopeException(
                'Source API response indicates more pages but is missing next_cursor.'
            );
        }

        return [
            'data' => $body['data'],
            'next_cursor' => $nextCursor === null ? null : (string) $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    private function paceBeforeRequest(): void
    {
        $requestsPerSecond = max(1, (int) config('ingestion.requests_per_second'));
        $minIntervalUs = (int) (1_000_000 / $requestsPerSecond);

        if ($this->lastRequestAt === null) {
            return;
        }

        $elapsedUs = (int) ((microtime(true) - $this->lastRequestAt) * 1_000_000);
        $remainingUs = $minIntervalUs - $elapsedUs;

        if ($remainingUs > 0) {
            $this->sleep($remainingUs);
        }
    }

    private function delayBeforeRetry(int $attempt, ?string $retryAfter): void
    {
        if ($retryAfter !== null && is_numeric($retryAfter) && (float) $retryAfter > 0) {
            $this->sleep((int) ((float) $retryAfter * 1_000_000));

            return;
        }

        $delaySeconds = 2 ** ($attempt - 1);
        $this->sleep($delaySeconds * 1_000_000);
    }

    private function sleep(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        ($this->sleeper ?? fn (int $us) => usleep($us))($microseconds);
    }

    private function responseSnippet(Response $response): string
    {
        $body = trim($response->body());

        if ($body === '') {
            return '(empty body)';
        }

        return strlen($body) > 200 ? substr($body, 0, 200).'...' : $body;
    }
}
