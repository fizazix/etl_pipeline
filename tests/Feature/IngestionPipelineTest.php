<?php

namespace Tests\Feature;

use App\Models\DestinationRecord;
use App\Models\IngestionError;
use App\Models\PipelineCheckpoint;
use App\Services\Ingestion\DestinationWriter;
use App\Services\Ingestion\IngestionErrorWriter;
use App\Services\Ingestion\IngestionPipeline;
use App\Services\Ingestion\RecordValidator;
use App\Services\Ingestion\SourceApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class IngestionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_VALID_RECORD_COUNT = 6;

    private const EXPECTED_MALFORMED_RECORD_COUNT = 4;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Ingestion pipeline feature tests require MySQL.');
        }
    }

    public function test_complete_ingestion(): void
    {
        $this->fakeSourcePages();

        app(IngestionPipeline::class)->run();

        $checkpoint = $this->checkpoint();

        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $checkpoint->status);
        $this->assertNull($checkpoint->next_cursor);
        $this->assertGreaterThan(0, DestinationRecord::count());
        $this->assertGreaterThan(0, IngestionError::count());
    }

    public function test_checkpoint_creation(): void
    {
        $this->fakeSourcePages();

        $this->assertDatabaseMissing('pipeline_checkpoints', [
            'pipeline_name' => IngestionPipeline::PIPELINE_NAME,
        ]);

        app(IngestionPipeline::class)->run(maxPages: 1);

        $this->assertDatabaseHas('pipeline_checkpoints', [
            'pipeline_name' => IngestionPipeline::PIPELINE_NAME,
            'status' => PipelineCheckpoint::STATUS_RUNNING,
        ]);
    }

    public function test_checkpoint_advancement(): void
    {
        $this->fakeSourcePages();

        app(IngestionPipeline::class)->run(maxPages: 1);

        $checkpoint = $this->checkpoint();

        $this->assertSame(PipelineCheckpoint::STATUS_RUNNING, $checkpoint->status);
        $this->assertSame('3', $checkpoint->next_cursor);
        $this->assertNotNull($checkpoint->last_successful_page_at);
    }

    public function test_checkpoint_completion(): void
    {
        $this->fakeSourcePages();

        app(IngestionPipeline::class)->run();

        $checkpoint = $this->checkpoint();

        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $checkpoint->status);
        $this->assertNull($checkpoint->next_cursor);
        $this->assertNotNull($checkpoint->completed_at);
    }

    public function test_resume_from_saved_cursor(): void
    {
        $this->fakeSourcePages();

        PipelineCheckpoint::create([
            'pipeline_name' => IngestionPipeline::PIPELINE_NAME,
            'next_cursor' => '5',
            'status' => PipelineCheckpoint::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        DestinationRecord::create([
            'source_id' => 'customer-001',
            'name' => 'Customer 1',
            'email' => 'alice.updated@example.com',
            'status' => 'active',
            'version' => 2,
            'source_updated_at' => '2024-02-01 10:00:00',
            'raw_payload' => ['id' => 'customer-001'],
        ]);

        DestinationRecord::create([
            'source_id' => 'customer-002',
            'name' => 'Customer 2',
            'email' => 'customer-002@example.com',
            'status' => 'active',
            'version' => 1,
            'source_updated_at' => '2024-01-02 10:00:00',
            'raw_payload' => ['id' => 'customer-002'],
        ]);

        DestinationRecord::create([
            'source_id' => 'customer-003',
            'name' => 'Customer 3',
            'email' => 'customer-003@example.com',
            'status' => 'inactive',
            'version' => 1,
            'source_updated_at' => '2024-01-03 10:00:00',
            'raw_payload' => ['id' => 'customer-003'],
        ]);

        app(IngestionPipeline::class)->run();

        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $this->checkpoint()->status);
    }

    public function test_malformed_records_do_not_stop_the_page(): void
    {
        config(['ingestion.source_api_url' => 'http://source.test/records']);

        Http::fake([
            'source.test/*' => Http::response([
                'data' => [
                    [
                        'id' => 'customer-valid',
                        'name' => 'Valid Customer',
                        'email' => 'valid@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-01-01T10:00:00Z',
                    ],
                    [
                        'name' => 'Missing ID',
                        'email' => 'missing@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-01-01T10:00:00Z',
                    ],
                ],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        app(IngestionPipeline::class)->run();

        $this->assertSame(1, DestinationRecord::count());
        $this->assertSame(1, IngestionError::count());
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $this->checkpoint()->status);
    }

    public function test_page_exception_rolls_back_destination_rows(): void
    {
        $this->fakeSourcePages();

        $destinationWriter = $this->createMock(DestinationWriter::class);
        $destinationWriter->expects($this->once())
            ->method('upsert')
            ->willThrowException(new RuntimeException('Destination write failed'));

        $this->bindPipeline($destinationWriter);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destination write failed');

        try {
            app(IngestionPipeline::class)->run();
        } finally {
            $this->assertSame(0, DestinationRecord::count());
        }
    }

    public function test_page_exception_rolls_back_error_rows(): void
    {
        config(['ingestion.source_api_url' => 'http://source.test/records']);

        Http::fake([
            'source.test/*' => Http::response([
                'data' => [
                    [
                        'name' => 'Missing ID',
                        'email' => 'missing@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-01-01T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-valid',
                        'name' => 'Valid Customer',
                        'email' => 'valid@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-01-01T10:00:00Z',
                    ],
                ],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $destinationWriter = $this->createMock(DestinationWriter::class);
        $destinationWriter->expects($this->once())
            ->method('upsert')
            ->willThrowException(new RuntimeException('Destination write failed'));

        $this->bindPipeline($destinationWriter);

        $this->expectException(RuntimeException::class);

        try {
            app(IngestionPipeline::class)->run();
        } finally {
            $this->assertSame(0, IngestionError::count());
        }
    }

    public function test_page_exception_does_not_advance_checkpoint(): void
    {
        $this->fakeSourcePages();

        $destinationWriter = $this->createMock(DestinationWriter::class);
        $destinationWriter->expects($this->once())
            ->method('upsert')
            ->willThrowException(new RuntimeException('Destination write failed'));

        $this->bindPipeline($destinationWriter);

        $this->expectException(RuntimeException::class);

        try {
            app(IngestionPipeline::class)->run();
        } finally {
            $checkpoint = $this->checkpoint();

            $this->assertSame(PipelineCheckpoint::STATUS_FAILED, $checkpoint->status);
            $this->assertNull($checkpoint->next_cursor);
            $this->assertNotNull($checkpoint->last_error);
        }
    }

    public function test_fetch_failure_marks_checkpoint_failed(): void
    {
        config(['ingestion.source_api_url' => 'http://source.test/records', 'ingestion.max_attempts' => 1]);

        Http::fake([
            'source.test/*' => Http::response(['error' => 'server error'], 500),
        ]);

        $this->expectException(RuntimeException::class);

        try {
            app(IngestionPipeline::class)->run();
        } finally {
            $checkpoint = $this->checkpoint();

            $this->assertSame(PipelineCheckpoint::STATUS_FAILED, $checkpoint->status);
            $this->assertNotNull($checkpoint->last_error);
            $this->assertNull($checkpoint->next_cursor);
        }
    }

    public function test_rerunning_completed_pipeline_exits_safely(): void
    {
        $this->fakeSourcePages();

        $pipeline = app(IngestionPipeline::class);
        $pipeline->run();
        $pipeline->run();

        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $this->checkpoint()->status);
    }

    public function test_forced_rerun_remains_idempotent(): void
    {
        $this->fakeSourcePages();

        $pipeline = app(IngestionPipeline::class);
        $pipeline->run();
        $pipeline->run(force: true);
        $pipeline->run(force: true);

        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $this->checkpoint()->status);
    }

    public function test_stop_after_two_pages_and_resume(): void
    {
        $this->fakeSourcePages();

        $pipeline = app(IngestionPipeline::class);
        $pipeline->run(maxPages: 2);

        $checkpoint = $this->checkpoint();
        $this->assertSame(PipelineCheckpoint::STATUS_RUNNING, $checkpoint->status);
        $this->assertSame('5', $checkpoint->next_cursor);

        $pipeline->run();

        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $this->checkpoint()->status);
    }

    public function test_final_destination_count_matches_expected_unique_valid_records(): void
    {
        $this->fakeSourcePages();

        app(IngestionPipeline::class)->run();

        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());

        $alice = DestinationRecord::where('source_id', 'customer-001')->first();
        $this->assertSame(2, $alice->version);
        $this->assertSame('alice.updated@example.com', $alice->email);
    }

    public function test_final_error_count_matches_expected_unique_malformed_records(): void
    {
        $this->fakeSourcePages();

        app(IngestionPipeline::class)->run();

        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
        $this->assertSame(1, IngestionError::whereNull('source_id')->count());
        $this->assertSame('validation_error', IngestionError::first()->error_type);
    }

    private function checkpoint(): PipelineCheckpoint
    {
        return PipelineCheckpoint::where('pipeline_name', IngestionPipeline::PIPELINE_NAME)->firstOrFail();
    }

    private function bindPipeline(DestinationWriter $destinationWriter): void
    {
        $this->app->instance(IngestionPipeline::class, new IngestionPipeline(
            app(SourceApiClient::class),
            app(RecordValidator::class),
            $destinationWriter,
            app(IngestionErrorWriter::class),
        ));
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
