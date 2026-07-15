<?php

namespace Tests\Unit;

use App\Services\Ingestion\SourceApiClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SourceApiClientTest extends TestCase
{
    public function test_retries_transient_server_errors_with_backoff(): void
    {
        config([
            'ingestion.source_api_url' => 'http://source.test/records',
            'ingestion.max_retries' => 3,
            'ingestion.backoff_base_ms' => 1,
            'ingestion.backoff_max_ms' => 2,
        ]);

        Http::fake([
            'source.test/*' => Http::sequence()
                ->push(['error' => 'fail'], 500)
                ->push(['data' => [['external_id' => 'rec-1']], 'next_cursor' => null], 200),
        ]);

        $client = new SourceApiClient;
        $page = $client->fetchPage(null);

        $this->assertCount(1, $page['data']);
        Http::assertSentCount(2);
    }

    public function test_respects_retry_after_header_on_rate_limit(): void
    {
        config([
            'ingestion.source_api_url' => 'http://source.test/records',
            'ingestion.max_retries' => 3,
            'ingestion.backoff_base_ms' => 1,
            'ingestion.backoff_max_ms' => 2,
        ]);

        Http::fake([
            'source.test/*' => Http::sequence()
                ->push(['error' => 'slow down'], 429, ['Retry-After' => '0'])
                ->push(['data' => [], 'next_cursor' => null], 200),
        ]);

        $client = new SourceApiClient;
        $page = $client->fetchPage('page-2');

        $this->assertSame([], $page['data']);
        Http::assertSentCount(2);
    }

    public function test_throws_after_exhausting_retries(): void
    {
        config([
            'ingestion.source_api_url' => 'http://source.test/records',
            'ingestion.max_retries' => 2,
            'ingestion.backoff_base_ms' => 1,
            'ingestion.backoff_max_ms' => 2,
        ]);

        Http::fake([
            'source.test/*' => Http::response(['error' => 'fail'], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('after 2 retries');

        (new SourceApiClient)->fetchPage(null);
    }
}
