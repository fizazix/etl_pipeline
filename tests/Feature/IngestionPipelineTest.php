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
use Tests\Concerns\RequiresMySql;
use Tests\Support\DeterministicSourcePages;
use Tests\TestCase;

class IngestionPipelineTest extends TestCase
{
    use DeterministicSourcePages;
    use RefreshDatabase;
    use RequiresMySql;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRequiresMySql();
    }

    public function test_complete_ingestion(): void
    {
        $this->fakeDeterministicSourcePages();

        app(IngestionPipeline::class)->run();

        $checkpoint = $this->checkpoint();

        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $checkpoint->status);
        $this->assertNull($checkpoint->next_cursor);
        $this->assertGreaterThan(0, DestinationRecord::count());
        $this->assertGreaterThan(0, IngestionError::count());
    }

    public function test_checkpoint_creation(): void
    {
        $this->fakeDeterministicSourcePages();

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
        $this->fakeDeterministicSourcePages();

        app(IngestionPipeline::class)->run(maxPages: 1);

        $checkpoint = $this->checkpoint();

        $this->assertSame(PipelineCheckpoint::STATUS_RUNNING, $checkpoint->status);
        $this->assertSame('3', $checkpoint->next_cursor);
        $this->assertNotNull($checkpoint->last_successful_page_at);
    }

    public function test_checkpoint_completion(): void
    {
        $this->fakeDeterministicSourcePages();

        app(IngestionPipeline::class)->run();

        $checkpoint = $this->checkpoint();

        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $checkpoint->status);
        $this->assertNull($checkpoint->next_cursor);
        $this->assertNotNull($checkpoint->completed_at);
    }

    public function test_successful_page_commits_destination_and_checkpoint_together(): void
    {
        $this->fakeDeterministicSourcePages();

        app(IngestionPipeline::class)->run(maxPages: 1);

        $this->assertSame(2, DestinationRecord::count());
        $this->assertSame('3', $this->checkpoint()->next_cursor);
        $this->assertTrue(DestinationRecord::where('source_id', 'customer-001')->exists());
        $this->assertTrue(DestinationRecord::where('source_id', 'customer-002')->exists());
    }

    public function test_resume_from_saved_cursor(): void
    {
        $this->fakeDeterministicSourcePages();

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
        $this->fakeDeterministicSourcePages();

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
        $this->fakeDeterministicSourcePages();

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

    public function test_failed_page_after_successful_pages_preserves_last_committed_cursor(): void
    {
        $this->fakeDeterministicSourcePages();

        $pipeline = app(IngestionPipeline::class);
        $pipeline->run(maxPages: 2);

        $this->assertSame('5', $this->checkpoint()->next_cursor);
        $this->assertSame(3, DestinationRecord::count());

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
            $this->assertSame('5', $checkpoint->next_cursor);
            $this->assertSame(3, DestinationRecord::count());
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
        $this->fakeDeterministicSourcePages();

        $pipeline = app(IngestionPipeline::class);
        $pipeline->run();
        $pipeline->run();

        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $this->checkpoint()->status);
    }

    public function test_forced_rerun_remains_idempotent(): void
    {
        $this->fakeDeterministicSourcePages();

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
        $this->fakeDeterministicSourcePages();

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

    public function test_end_to_end_pipeline_produces_expected_outcomes(): void
    {
        $this->fakeDeterministicSourcePages();

        app(IngestionPipeline::class)->run();

        $checkpoint = $this->checkpoint();

        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $checkpoint->status);
        $this->assertNull($checkpoint->next_cursor);

        $alice = DestinationRecord::where('source_id', 'customer-001')->firstOrFail();
        $this->assertSame(2, $alice->version);
        $this->assertSame('alice.updated@example.com', $alice->email);

        $customerFour = DestinationRecord::where('source_id', 'customer-004')->firstOrFail();
        $this->assertSame(2, $customerFour->version);

        $customerThree = DestinationRecord::where('source_id', 'customer-003')->firstOrFail();
        $this->assertSame(2, $customerThree->version);
        $this->assertSame('2024-03-01 10:00:00', $customerThree->source_updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('customer-003.later@example.com', $customerThree->email);

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
}
