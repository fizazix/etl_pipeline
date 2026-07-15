<?php

namespace App\Services\Ingestion;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SourceApiClient
{
    public function fetchPage(?string $cursor): array
    {
        $url = config('ingestion.source_api_url');
        $maxRetries = config('ingestion.max_retries');
        $backoffBaseMs = config('ingestion.backoff_base_ms');
        $backoffMaxMs = config('ingestion.backoff_max_ms');
        $timeout = config('ingestion.request_timeout_seconds');

        $attempt = 0;

        while (true) {
            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->get($url, array_filter(['cursor' => $cursor]));

                if ($response->status() === 429) {
                    $this->sleepForRateLimit($response->header('Retry-After'), $attempt, $backoffBaseMs, $backoffMaxMs);
                    $attempt++;

                    if ($attempt > $maxRetries) {
                        throw new RuntimeException('Source API rate limit exceeded after '.$maxRetries.' retries.');
                    }

                    continue;
                }

                if ($response->serverError()) {
                    $attempt++;

                    if ($attempt > $maxRetries) {
                        throw new RuntimeException(
                            'Source API returned HTTP '.$response->status().' after '.$maxRetries.' retries.'
                        );
                    }

                    $this->sleepWithBackoff($attempt, $backoffBaseMs, $backoffMaxMs);

                    continue;
                }

                if ($response->failed()) {
                    throw new RuntimeException(
                        'Source API returned HTTP '.$response->status().': '.$response->body()
                    );
                }

                $body = $response->json();

                if (! is_array($body) || ! array_key_exists('data', $body)) {
                    throw new RuntimeException('Source API response is missing the data key.');
                }

                return [
                    'data' => $body['data'],
                    'next_cursor' => $body['next_cursor'] ?? null,
                ];
            } catch (ConnectionException|RequestException $exception) {
                $attempt++;

                if ($attempt > $maxRetries) {
                    throw new RuntimeException(
                        'Source API request failed after '.$maxRetries.' retries: '.$exception->getMessage(),
                        0,
                        $exception
                    );
                }

                $this->sleepWithBackoff($attempt, $backoffBaseMs, $backoffMaxMs);
            }
        }
    }

    private function sleepForRateLimit(?string $retryAfter, int $attempt, int $backoffBaseMs, int $backoffMaxMs): void
    {
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            usleep((int) ($retryAfter * 1_000_000));

            return;
        }

        $this->sleepWithBackoff($attempt, $backoffBaseMs, $backoffMaxMs);
    }

    private function sleepWithBackoff(int $attempt, int $backoffBaseMs, int $backoffMaxMs): void
    {
        $delayMs = min($backoffBaseMs * (2 ** ($attempt - 1)), $backoffMaxMs);
        usleep($delayMs * 1000);
    }
}
