<?php

namespace Tests\Unit;

use App\Services\Ingestion\InvalidSourceApiEnvelopeException;
use App\Services\Ingestion\SourceApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SourceApiClientTest extends TestCase
{
    public function test_returns_normalized_successful_response(): void
    {
        Http::fake([
            'source.test/*' => Http::response([
                'data' => [['external_id' => 'rec-1']],
                'next_cursor' => '50',
                'has_more' => true,
            ], 200),
        ]);

        $page = $this->client()->fetchPage('0', 50);

        $this->assertSame([['external_id' => 'rec-1']], $page['data']);
        $this->assertSame('50', $page['next_cursor']);
        $this->assertTrue($page['has_more']);
    }

    public function test_retries_transient_server_errors_with_backoff(): void
    {
        Http::fake([
            'source.test/*' => Http::sequence()
                ->push(['error' => 'fail'], 500)
                ->push([
                    'data' => [['external_id' => 'rec-1']],
                    'next_cursor' => null,
                    'has_more' => false,
                ], 200),
        ]);

        $page = $this->client()->fetchPage(null, 50);

        $this->assertCount(1, $page['data']);
        Http::assertSentCount(2);
    }

    public function test_http_429_is_retried_and_succeeds(): void
    {
        Http::fake([
            'source.test/*' => Http::sequence()
                ->push(['error' => 'slow down'], 429, ['Retry-After' => '2'])
                ->push([
                    'data' => [],
                    'next_cursor' => null,
                    'has_more' => false,
                ], 200),
        ]);

        $page = $this->client()->fetchPage('100', 25);

        $this->assertSame([], $page['data']);
        Http::assertSentCount(2);
    }

    public function test_retries_connection_or_timeout_failures(): void
    {
        Http::fake([
            'source.test/*' => Http::response([
                'data' => [['external_id' => 'rec-1']],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $client = new class(fn (int $us) => null) extends SourceApiClient {
            private int $calls = 0;

            protected function performGet(string $url, array $query, int $timeout): \Illuminate\Http\Client\Response
            {
                $this->calls++;

                if ($this->calls === 1) {
                    throw new ConnectionException('Connection refused');
                }

                return parent::performGet($url, $query, $timeout);
            }
        };

        config([
            'ingestion.source_api_url' => 'http://source.test/records',
            'ingestion.max_attempts' => 5,
            'ingestion.requests_per_second' => 4,
            'ingestion.request_timeout_seconds' => 5,
        ]);

        $page = $client->fetchPage(null, 50);

        $this->assertCount(1, $page['data']);
        Http::assertSentCount(1);
    }

    public function test_does_not_retry_permanent_client_errors(): void
    {
        Http::fake([
            'source.test/*' => Http::response(['error' => 'invalid limit'], 422),
        ]);

        try {
            $this->client()->fetchPage('bad', 50);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Source API returned HTTP 422', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_throws_after_exhausting_retries(): void
    {
        Http::fake([
            'source.test/*' => Http::response(['error' => 'fail'], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('after 2 attempts');

        $this->client(null, ['ingestion.max_attempts' => 2])->fetchPage(null, 50);
    }

    #[DataProvider('invalidEnvelopeProvider')]
    public function test_throws_for_invalid_json_or_envelope(
        mixed $responseBody,
        array $headers,
        string $expectedMessage,
    ): void {
        Http::fake([
            'source.test/*' => Http::response($responseBody, 200, $headers),
        ]);

        $this->expectException(InvalidSourceApiEnvelopeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->client()->fetchPage(null, 50);
    }

    public static function invalidEnvelopeProvider(): array
    {
        return [
            'invalid json' => ['not-json', ['Content-Type' => 'text/plain'], 'not valid JSON'],
            'missing has_more' => [['data' => [], 'next_cursor' => null], [], 'has_more'],
            'has_more without next_cursor' => [['data' => [], 'has_more' => true], [], 'next_cursor'],
        ];
    }

    public function test_sends_cursor_and_limit_query_parameters(): void
    {
        Http::fake([
            'source.test/*' => Http::response([
                'data' => [],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $this->client()->fetchPage('150', 25);

        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return parse_url($request->url(), PHP_URL_HOST) === 'source.test'
                && parse_url($request->url(), PHP_URL_PATH) === '/records'
                && ($query['cursor'] ?? null) === '150'
                && ($query['limit'] ?? null) === '25';
        });
    }

    private function client(?callable $sleeper = null, array $configOverrides = []): SourceApiClient
    {
        config(array_merge([
            'ingestion.source_api_url' => 'http://source.test/records',
            'ingestion.max_attempts' => 5,
            'ingestion.requests_per_second' => 4,
            'ingestion.request_timeout_seconds' => 5,
        ], $configOverrides));

        return new SourceApiClient($sleeper ?? fn (int $us) => null);
    }
}
