<?php

namespace Tests\Feature;

use App\Models\DestinationRecord;
use App\Models\IngestionError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RequiresMySql;
use Tests\TestCase;

class EtlSchemaTest extends TestCase
{
    use RefreshDatabase;
    use RequiresMySql;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRequiresMySql();
    }

    public function test_destination_record_json_round_trip(): void
    {
        $record = DestinationRecord::create([
            'source_id' => 'rec-001',
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'status' => 'active',
            'version' => 2,
            'source_updated_at' => '2024-02-01 10:00:00',
            'raw_payload' => ['name' => 'Alice', 'email' => 'alice@example.com', 'version' => 2],
        ]);

        $fresh = DestinationRecord::find($record->id);

        $this->assertSame(['name' => 'Alice', 'email' => 'alice@example.com', 'version' => 2], $fresh->raw_payload);
        $this->assertSame(2, $fresh->version);
    }

    public function test_ingestion_error_json_round_trip(): void
    {
        $now = now();

        $error = IngestionError::create([
            'source_id' => 'rec-bad',
            'source_cursor' => 'page-4',
            'error_type' => 'invalid_version',
            'error_details' => ['field' => 'version', 'value' => 'two'],
            'raw_payload' => ['external_id' => 'rec-bad', 'version' => 'two'],
            'fingerprint' => hash('sha256', 'rec-bad-page-4-invalid_version'),
            'occurrence_count' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);

        $fresh = IngestionError::find($error->id);

        $this->assertSame(['field' => 'version', 'value' => 'two'], $fresh->error_details);
        $this->assertEquals(['external_id' => 'rec-bad', 'version' => 'two'], $fresh->raw_payload);
    }

    public function test_ingestion_error_fingerprint_is_idempotent(): void
    {
        $fingerprint = hash('sha256', 'duplicate-error');
        $now = now();

        IngestionError::create([
            'source_id' => 'rec-bad',
            'source_cursor' => 'page-4',
            'error_type' => 'invalid_version',
            'error_details' => ['field' => 'version'],
            'raw_payload' => ['version' => 'two'],
            'fingerprint' => $fingerprint,
            'occurrence_count' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);

        $updated = IngestionError::updateOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'source_id' => 'rec-bad',
                'source_cursor' => 'page-4',
                'error_type' => 'invalid_version',
                'error_details' => ['field' => 'version'],
                'raw_payload' => ['version' => 'two'],
                'occurrence_count' => 2,
                'first_seen_at' => $now,
                'last_seen_at' => now(),
            ]
        );

        $this->assertSame(1, IngestionError::count());
        $this->assertSame(2, $updated->occurrence_count);
    }

    public function test_unique_indexes_exist(): void
    {
        $destinationIndexes = collect(Schema::getIndexes('destination_records'));
        $errorIndexes = collect(Schema::getIndexes('ingestion_errors'));
        $checkpointIndexes = collect(Schema::getIndexes('pipeline_checkpoints'));

        $this->assertTrue(
            $destinationIndexes->contains(fn (array $index) => $index['unique'] && in_array('source_id', $index['columns'], true))
        );
        $this->assertTrue(
            $errorIndexes->contains(fn (array $index) => $index['unique'] && in_array('fingerprint', $index['columns'], true))
        );
        $this->assertTrue(
            $checkpointIndexes->contains(fn (array $index) => $index['unique'] && in_array('pipeline_name', $index['columns'], true))
        );
    }
}
