<?php

namespace Tests\Feature;

use App\Models\DestinationRecord;
use App\Models\IngestionError;
use App\Models\PipelineCheckpoint;
use App\Services\Ingestion\IngestionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EtlRunCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_VALID_RECORD_COUNT = 6;

    private const EXPECTED_MALFORMED_RECORD_COUNT = 4;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('ETL command feature tests require MySQL.');
        }
    }

    public function test_etl_run_completes_successfully(): void
    {
        $this->fakeSourcePages();

        $exitCode = Artisan::call('etl:run');

        $this->assertSame(0, $exitCode);
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $this->checkpoint()->status);
        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
    }

    public function test_etl_run_exits_success_when_already_completed(): void
    {
        $this->fakeSourcePages();

        Artisan::call('etl:run');
        $destinationCount = DestinationRecord::count();
        $errorCount = IngestionError::count();

        $exitCode = Artisan::call('etl:run');

        $this->assertSame(0, $exitCode);
        $this->assertSame($destinationCount, DestinationRecord::count());
        $this->assertSame($errorCount, IngestionError::count());
        $this->assertStringContainsString('Pipeline already completed.', Artisan::output());
    }

    public function test_etl_run_force_remains_idempotent(): void
    {
        $this->fakeSourcePages();

        Artisan::call('etl:run');
        Artisan::call('etl:run', ['--force' => true]);
        Artisan::call('etl:run', ['--force' => true]);

        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $this->checkpoint()->status);
    }

    public function test_etl_run_max_pages_stops_and_resumes(): void
    {
        $this->fakeSourcePages();

        $exitCode = Artisan::call('etl:run', ['--max-pages' => 2]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(PipelineCheckpoint::STATUS_RUNNING, $this->checkpoint()->status);
        $this->assertSame('5', $this->checkpoint()->next_cursor);
        $this->assertStringContainsString('Stopped after 2 page(s)', Artisan::output());

        $exitCode = Artisan::call('etl:run');

        $this->assertSame(0, $exitCode);
        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $this->checkpoint()->status);
    }

    public function test_etl_run_rejects_invalid_max_pages(): void
    {
        $this->fakeSourcePages();

        $this->assertSame(1, Artisan::call('etl:run', ['--max-pages' => '0']));
        $this->assertStringContainsString('positive integer', Artisan::output());
        $this->assertDatabaseMissing('pipeline_checkpoints', [
            'pipeline_name' => IngestionPipeline::PIPELINE_NAME,
        ]);

        $this->assertSame(1, Artisan::call('etl:run', ['--max-pages' => 'abc']));
        $this->assertStringContainsString('positive integer', Artisan::output());
    }

    public function test_etl_run_returns_failure_on_fetch_error(): void
    {
        config(['ingestion.source_api_url' => 'http://source.test/records', 'ingestion.max_attempts' => 1]);

        Http::fake([
            'source.test/*' => Http::response(['error' => 'server error'], 500),
        ]);

        $exitCode = Artisan::call('etl:run');

        $this->assertSame(1, $exitCode);
        $this->assertSame(PipelineCheckpoint::STATUS_FAILED, $this->checkpoint()->status);
        $this->assertNotNull($this->checkpoint()->last_error);
    }

    public function test_ingestion_run_shares_same_behavior(): void
    {
        $this->fakeSourcePages();

        $exitCode = Artisan::call('ingestion:run', ['--max-pages' => 1]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(PipelineCheckpoint::STATUS_RUNNING, $this->checkpoint()->status);
        $this->assertSame('3', $this->checkpoint()->next_cursor);
        $this->assertStringContainsString('Starting pipeline from cursor 0', Artisan::output());
    }

    private function checkpoint(): PipelineCheckpoint
    {
        return PipelineCheckpoint::where('pipeline_name', IngestionPipeline::PIPELINE_NAME)->firstOrFail();
    }

    private function fakeSourcePages(): void
    {
        config(['ingestion.source_api_url' => 'http://source.test/records']);

        $pages = [
            '0' => [
                'data' => [
                    [
                        'id' => 'customer-001',
                        'name' => 'Customer 1',
                        'email' => 'alice@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-01-01T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-002',
                        'name' => 'Customer 2',
                        'email' => 'customer-002@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-01-02T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-001',
                        'name' => 'Customer 1',
                        'email' => 'alice@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-01-01T10:00:00Z',
                    ],
                ],
                'next_cursor' => '3',
                'has_more' => true,
            ],
            '3' => [
                'data' => [
                    [
                        'id' => 'customer-003',
                        'name' => 'Customer 3',
                        'email' => 'customer-003@example.com',
                        'status' => 'inactive',
                        'version' => 1,
                        'updated_at' => '2024-01-03T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-001',
                        'name' => 'Customer 1',
                        'email' => 'alice.updated@example.com',
                        'status' => 'pending',
                        'version' => 2,
                        'updated_at' => '2024-02-01T10:00:00Z',
                    ],
                ],
                'next_cursor' => '5',
                'has_more' => true,
            ],
            '5' => [
                'data' => [
                    [
                        'id' => 'customer-004',
                        'name' => 'Customer 4',
                        'email' => 'customer-004@example.com',
                        'status' => 'pending',
                        'version' => 2,
                        'updated_at' => '2024-02-10T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-001',
                        'name' => 'Customer 1 Old',
                        'email' => 'alice.old@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-01-01T10:00:00Z',
                    ],
                ],
                'next_cursor' => '7',
                'has_more' => true,
            ],
            '7' => [
                'data' => [
                    [
                        'id' => 'customer-005',
                        'name' => 'Customer 5',
                        'email' => 'customer-005@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-03-01T10:00:00Z',
                    ],
                    [
                        'name' => 'Missing ID',
                        'email' => 'missing@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-03-02T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-bad-version',
                        'name' => 'Bad Version',
                        'email' => 'badversion@example.com',
                        'status' => 'active',
                        'version' => 'two',
                        'updated_at' => '2024-03-03T10:00:00Z',
                    ],
                ],
                'next_cursor' => '10',
                'has_more' => true,
            ],
            '10' => [
                'data' => [
                    [
                        'id' => 'customer-006',
                        'name' => 'Customer 6',
                        'email' => 'customer-006@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-04-01T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-bad-date',
                        'name' => 'Bad Date',
                        'email' => 'baddate@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => 'not-a-date',
                    ],
                    [
                        'id' => 'customer-bad-name',
                        'name' => 12345,
                        'email' => 'badname@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-04-02T10:00:00Z',
                    ],
                ],
                'next_cursor' => null,
                'has_more' => false,
            ],
        ];

        Http::fake(function ($request) use ($pages) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $cursor = $query['cursor'] ?? '0';

            if (! array_key_exists($cursor, $pages)) {
                return Http::response(['error' => 'unknown cursor'], 404);
            }

            return Http::response($pages[$cursor], 200);
        });
    }
}
