<?php

namespace Tests\Feature;

use App\Models\DestinationRecord;
use App\Models\IngestionError;
use App\Models\PipelineCheckpoint;
use App\Services\Ingestion\IngestionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Ingestion API feature tests require MySQL.');
        }
    }

    public function test_status_returns_empty_state_when_no_checkpoint_exists(): void
    {
        $response = $this->getJson('/api/status');

        $response->assertOk()
            ->assertJsonPath('pipeline.name', config('ingestion.pipeline_name'))
            ->assertJsonPath('pipeline.status', PipelineCheckpoint::STATUS_PENDING)
            ->assertJsonPath('pipeline.next_cursor', null)
            ->assertJsonPath('pipeline.started_at', null)
            ->assertJsonPath('pipeline.completed_at', null)
            ->assertJsonPath('pipeline.last_successful_page_at', null)
            ->assertJsonPath('pipeline.last_error', null)
            ->assertJsonPath('records_loaded', 0)
            ->assertJsonPath('isolated_errors', 0)
            ->assertJsonPath('error_occurrences', 0);
    }

    public function test_status_returns_completed_checkpoint_details(): void
    {
        $startedAt = now()->subHour();
        $completedAt = now()->subMinutes(30);
        $lastPageAt = now()->subMinutes(31);

        PipelineCheckpoint::create([
            'pipeline_name' => IngestionPipeline::PIPELINE_NAME,
            'next_cursor' => null,
            'status' => PipelineCheckpoint::STATUS_COMPLETED,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'last_successful_page_at' => $lastPageAt,
            'last_error' => null,
        ]);

        $response = $this->getJson('/api/status');

        $response->assertOk()
            ->assertJsonPath('pipeline.name', IngestionPipeline::PIPELINE_NAME)
            ->assertJsonPath('pipeline.status', PipelineCheckpoint::STATUS_COMPLETED)
            ->assertJsonPath('pipeline.next_cursor', null)
            ->assertJsonPath('pipeline.last_error', null);

        $this->assertNotNull($response->json('pipeline.started_at'));
        $this->assertNotNull($response->json('pipeline.completed_at'));
        $this->assertNotNull($response->json('pipeline.last_successful_page_at'));
    }

    public function test_status_returns_correct_loaded_count(): void
    {
        $this->createDestinationRecord('customer-001', 'active');
        $this->createDestinationRecord('customer-002', 'inactive');

        $this->getJson('/api/status')
            ->assertOk()
            ->assertJsonPath('records_loaded', 2);
    }

    public function test_status_returns_correct_isolated_error_and_occurrence_counts(): void
    {
        $this->createIngestionError('customer-bad-1', '150', 'invalid_version', 3);
        $this->createIngestionError('customer-bad-2', '150', 'invalid_name', 2);

        $this->getJson('/api/status')
            ->assertOk()
            ->assertJsonPath('isolated_errors', 2)
            ->assertJsonPath('error_occurrences', 5);
    }

    public function test_records_endpoint_supports_pagination(): void
    {
        $this->createDestinationRecord('customer-001', 'active');
        $this->createDestinationRecord('customer-002', 'inactive');

        $response = $this->getJson('/api/records?per_page=1');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_id', 'customer-001');
    }

    public function test_records_endpoint_filters_by_source_id(): void
    {
        $this->createDestinationRecord('customer-001', 'active');
        $this->createDestinationRecord('customer-002', 'inactive');

        $response = $this->getJson('/api/records?source_id=customer-001');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source_id', 'customer-001');
    }

    public function test_records_endpoint_filters_by_status(): void
    {
        $this->createDestinationRecord('customer-001', 'active');
        $this->createDestinationRecord('customer-002', 'inactive');
        $this->createDestinationRecord('customer-003', 'active');

        $response = $this->getJson('/api/records?status=active');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2);

        $statuses = collect($response->json('data'))->pluck('status')->unique()->all();
        $this->assertSame(['active'], $statuses);
    }

    public function test_errors_endpoint_supports_pagination(): void
    {
        $this->createIngestionError('customer-bad-1', '150', 'invalid_version');
        $this->createIngestionError('customer-bad-2', '151', 'invalid_name');

        $response = $this->getJson('/api/errors?per_page=1');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'source_id',
                    'source_cursor',
                    'error_type',
                    'error_details',
                    'raw_payload',
                    'occurrence_count',
                    'first_seen_at',
                    'last_seen_at',
                ]],
            ]);
    }

    public function test_errors_endpoint_filters_by_error_type(): void
    {
        $this->createIngestionError('customer-bad-1', '150', 'invalid_version');
        $this->createIngestionError('customer-bad-2', '151', 'invalid_name');

        $response = $this->getJson('/api/errors?error_type=invalid_version');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.error_type', 'invalid_version');
    }

    public function test_records_endpoint_rejects_invalid_query_parameters(): void
    {
        $this->getJson('/api/records?status=bad')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/records?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_record_detail_endpoint_returns_record_or_not_found(): void
    {
        $this->createDestinationRecord('customer-001', 'active', 2);

        $this->getJson('/api/records/customer-001')
            ->assertOk()
            ->assertJsonPath('source_id', 'customer-001')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('version', 2);

        $this->getJson('/api/records/customer-missing')
            ->assertNotFound()
            ->assertJsonPath('message', 'Record not found.');
    }

    private function createDestinationRecord(string $sourceId, string $status, int $version = 1): DestinationRecord
    {
        return DestinationRecord::create([
            'source_id' => $sourceId,
            'name' => 'Customer '.$sourceId,
            'email' => $sourceId.'@example.com',
            'status' => $status,
            'version' => $version,
            'source_updated_at' => now(),
            'raw_payload' => [
                'id' => $sourceId,
                'name' => 'Customer '.$sourceId,
                'email' => $sourceId.'@example.com',
                'status' => $status,
                'version' => $version,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function createIngestionError(
        string $sourceId,
        string $sourceCursor,
        string $errorType,
        int $occurrenceCount = 1,
    ): IngestionError {
        $now = now();
        $rawPayload = ['id' => $sourceId, 'error' => $errorType];
        $fingerprint = hash('sha256', $sourceCursor.json_encode($rawPayload, JSON_THROW_ON_ERROR).$errorType);

        return IngestionError::create([
            'source_id' => $sourceId,
            'source_cursor' => $sourceCursor,
            'error_type' => $errorType,
            'error_details' => ['field' => $errorType],
            'raw_payload' => $rawPayload,
            'fingerprint' => $fingerprint,
            'occurrence_count' => $occurrenceCount,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }
}
