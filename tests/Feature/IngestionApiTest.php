<?php

namespace Tests\Feature;

use App\Models\DestinationRecord;
use App\Models\IngestionError;
use App\Models\PipelineCheckpoint;
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

    public function test_pipeline_status_endpoint(): void
    {
        PipelineCheckpoint::create([
            'pipeline_name' => 'default',
            'cursor' => null,
            'status' => PipelineCheckpoint::STATUS_COMPLETED,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        $response = $this->getJson('/api/pipeline/status');

        $response->assertOk()
            ->assertJsonPath('pipeline_name', 'default')
            ->assertJsonPath('status', PipelineCheckpoint::STATUS_COMPLETED);
    }

    public function test_loaded_count_endpoint(): void
    {
        DestinationRecord::create([
            'external_id' => 'rec-001',
            'version' => 1,
            'source_updated_at' => now(),
            'payload' => ['name' => 'Alice', 'email' => null],
        ]);

        $this->getJson('/api/pipeline/loaded-count')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    public function test_rejected_count_endpoint(): void
    {
        IngestionError::create([
            'external_id' => '',
            'source_cursor' => 'page-4',
            'raw_payload' => ['name' => 'Bad'],
            'error_message' => 'Missing id',
            'error_code' => 'missing_external_id',
        ]);

        $this->getJson('/api/pipeline/rejected-count')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    public function test_rejected_details_endpoint(): void
    {
        IngestionError::create([
            'external_id' => 'rec-bad',
            'source_cursor' => 'page-4',
            'raw_payload' => ['external_id' => 'rec-bad'],
            'error_message' => 'Invalid',
            'error_code' => 'invalid_version',
        ]);

        $response = $this->getJson('/api/pipeline/rejected');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.error_code', 'invalid_version');
    }

    public function test_loaded_records_endpoint(): void
    {
        DestinationRecord::create([
            'external_id' => 'rec-001',
            'version' => 2,
            'source_updated_at' => now(),
            'payload' => ['name' => 'Alice', 'email' => 'alice@example.com'],
        ]);

        $response = $this->getJson('/api/pipeline/loaded');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.external_id', 'rec-001')
            ->assertJsonPath('data.0.version', 2);
    }
}
