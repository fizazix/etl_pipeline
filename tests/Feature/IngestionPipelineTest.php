<?php

namespace Tests\Feature;

use App\Models\DestinationRecord;
use App\Models\IngestionError;
use App\Models\PipelineCheckpoint;
use App\Services\Ingestion\IngestionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IngestionPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Ingestion pipeline feature tests require MySQL.');
        }
    }

    public function test_pipeline_loads_valid_records_and_rejects_malformed_ones(): void
    {
        $this->fakeSourcePages();

        app(IngestionPipeline::class)->run();

        $this->assertSame(6, DestinationRecord::count());
        $this->assertSame(4, IngestionError::count());

        $alice = DestinationRecord::where('external_id', 'rec-001')->first();
        $this->assertSame(2, $alice->version);
        $this->assertSame('alice.updated@example.com', $alice->payload['email']);

        $checkpoint = PipelineCheckpoint::where('pipeline_name', 'default')->first();
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $checkpoint->status);
        $this->assertNull($checkpoint->cursor);
    }

    public function test_pipeline_is_idempotent_on_rerun(): void
    {
        $this->fakeSourcePages();

        $pipeline = app(IngestionPipeline::class);
        $pipeline->run();
        $pipeline->run();

        $this->assertSame(6, DestinationRecord::count());
        $this->assertSame(4, IngestionError::count());
    }

    public function test_pipeline_resumes_from_checkpoint_without_duplicates(): void
    {
        $this->fakeSourcePages();

        PipelineCheckpoint::create([
            'pipeline_name' => 'default',
            'cursor' => 'page-3',
            'status' => PipelineCheckpoint::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        DestinationRecord::create([
            'external_id' => 'rec-001',
            'version' => 2,
            'source_updated_at' => '2024-02-01 10:00:00',
            'payload' => ['name' => 'Alice Johnson', 'email' => 'alice.updated@example.com'],
        ]);

        DestinationRecord::create([
            'external_id' => 'rec-002',
            'version' => 1,
            'source_updated_at' => '2024-01-02 10:00:00',
            'payload' => ['name' => 'Bob Smith', 'email' => 'bob@example.com'],
        ]);

        DestinationRecord::create([
            'external_id' => 'rec-003',
            'version' => 1,
            'source_updated_at' => '2024-01-03 10:00:00',
            'payload' => ['name' => 'Carol White', 'email' => 'carol@example.com'],
        ]);

        app(IngestionPipeline::class)->run();

        $this->assertSame(6, DestinationRecord::count());
        $this->assertSame(4, IngestionError::count());
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, PipelineCheckpoint::first()->status);
    }

    public function test_older_version_does_not_overwrite_newer_record(): void
    {
        config(['ingestion.source_api_url' => 'http://source.test/records']);

        Http::fake([
            'source.test/*' => Http::response([
                'data' => [
                    [
                        'external_id' => 'rec-001',
                        'version' => 1,
                        'updated_at' => '2024-01-01T10:00:00Z',
                        'name' => 'Stale Alice',
                        'email' => 'stale@example.com',
                    ],
                ],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        DestinationRecord::create([
            'external_id' => 'rec-001',
            'version' => 2,
            'source_updated_at' => '2024-02-01 10:00:00',
            'payload' => ['name' => 'Current Alice', 'email' => 'current@example.com'],
        ]);

        PipelineCheckpoint::create([
            'pipeline_name' => 'default',
            'cursor' => null,
            'status' => PipelineCheckpoint::STATUS_IDLE,
        ]);

        app(IngestionPipeline::class)->run();

        $record = DestinationRecord::where('external_id', 'rec-001')->first();
        $this->assertSame(2, $record->version);
        $this->assertSame('Current Alice', $record->payload['name']);
    }

    private function fakeSourcePages(): void
    {
        config(['ingestion.source_api_url' => 'http://source.test/records']);

        $pages = [
            'page-1' => [
                'data' => [
                    ['external_id' => 'rec-001', 'version' => 1, 'updated_at' => '2024-01-01T10:00:00Z', 'name' => 'Alice Johnson', 'email' => 'alice@example.com'],
                    ['external_id' => 'rec-002', 'version' => 1, 'updated_at' => '2024-01-02T10:00:00Z', 'name' => 'Bob Smith', 'email' => 'bob@example.com'],
                    ['external_id' => 'rec-001', 'version' => 1, 'updated_at' => '2024-01-01T10:00:00Z', 'name' => 'Alice Johnson', 'email' => 'alice@example.com'],
                ],
                'next_cursor' => 'page-2',
                'has_more' => true,
            ],
            'page-2' => [
                'data' => [
                    ['external_id' => 'rec-003', 'version' => 1, 'updated_at' => '2024-01-03T10:00:00Z', 'name' => 'Carol White', 'email' => 'carol@example.com'],
                    ['external_id' => 'rec-001', 'version' => 2, 'updated_at' => '2024-02-01T10:00:00Z', 'name' => 'Alice Johnson', 'email' => 'alice.updated@example.com'],
                ],
                'next_cursor' => 'page-3',
                'has_more' => true,
            ],
            'page-3' => [
                'data' => [
                    ['external_id' => 'rec-004', 'version' => 2, 'updated_at' => '2024-02-10T10:00:00Z', 'name' => 'David Lee', 'email' => 'david@example.com'],
                    ['external_id' => 'rec-001', 'version' => 1, 'updated_at' => '2024-01-01T10:00:00Z', 'name' => 'Alice Johnson Old', 'email' => 'alice.old@example.com'],
                ],
                'next_cursor' => 'page-4',
                'has_more' => true,
            ],
            'page-4' => [
                'data' => [
                    ['external_id' => 'rec-005', 'version' => 1, 'updated_at' => '2024-03-01T10:00:00Z', 'name' => 'Eve Adams', 'email' => 'eve@example.com'],
                    ['version' => 1, 'updated_at' => '2024-03-02T10:00:00Z', 'name' => 'Missing ID'],
                    ['external_id' => 'rec-bad-version', 'version' => 'two', 'updated_at' => '2024-03-03T10:00:00Z', 'name' => 'Bad Version'],
                ],
                'next_cursor' => 'page-5',
                'has_more' => true,
            ],
            'page-5' => [
                'data' => [
                    ['external_id' => 'rec-006', 'version' => 1, 'updated_at' => '2024-04-01T10:00:00Z', 'name' => 'Frank Miller', 'email' => 'frank@example.com'],
                    ['external_id' => 'rec-bad-date', 'version' => 1, 'updated_at' => 'not-a-date', 'name' => 'Bad Date'],
                    ['external_id' => 'rec-bad-name', 'version' => 1, 'updated_at' => '2024-04-02T10:00:00Z', 'name' => 12345],
                ],
                'next_cursor' => null,
                'has_more' => false,
            ],
        ];

        Http::fake(function ($request) use ($pages) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $cursor = $query['cursor'] ?? 'page-1';

            if (! array_key_exists($cursor, $pages)) {
                return Http::response(['error' => 'unknown'], 404);
            }

            return Http::response($pages[$cursor], 200);
        });
    }
}
