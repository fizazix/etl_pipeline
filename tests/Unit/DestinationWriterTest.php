<?php

namespace Tests\Unit;

use App\Services\Ingestion\DestinationWriter;
use App\Models\DestinationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationWriterTest extends TestCase
{
    use RefreshDatabase;

    private DestinationWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('DestinationWriter tests require MySQL.');
        }

        $this->writer = new DestinationWriter;
    }

    public function test_inserts_new_record(): void
    {
        $this->writer->upsert([
            'external_id' => 'rec-100',
            'version' => 1,
            'source_updated_at' => '2024-01-01 10:00:00',
            'payload' => ['name' => 'Test User', 'email' => 'test@example.com'],
        ]);

        $record = DestinationRecord::where('external_id', 'rec-100')->first();

        $this->assertNotNull($record);
        $this->assertSame(1, $record->version);
        $this->assertSame('Test User', $record->payload['name']);
    }

    public function test_accepts_newer_version(): void
    {
        $this->writer->upsert([
            'external_id' => 'rec-100',
            'version' => 1,
            'source_updated_at' => '2024-01-01 10:00:00',
            'payload' => ['name' => 'Version One', 'email' => null],
        ]);

        $this->writer->upsert([
            'external_id' => 'rec-100',
            'version' => 2,
            'source_updated_at' => '2024-02-01 10:00:00',
            'payload' => ['name' => 'Version Two', 'email' => null],
        ]);

        $record = DestinationRecord::where('external_id', 'rec-100')->first();

        $this->assertSame(2, $record->version);
        $this->assertSame('Version Two', $record->payload['name']);
    }

    public function test_rejects_older_version(): void
    {
        $this->writer->upsert([
            'external_id' => 'rec-100',
            'version' => 3,
            'source_updated_at' => '2024-03-01 10:00:00',
            'payload' => ['name' => 'Current', 'email' => null],
        ]);

        $this->writer->upsert([
            'external_id' => 'rec-100',
            'version' => 1,
            'source_updated_at' => '2024-01-01 10:00:00',
            'payload' => ['name' => 'Stale', 'email' => null],
        ]);

        $record = DestinationRecord::where('external_id', 'rec-100')->first();

        $this->assertSame(3, $record->version);
        $this->assertSame('Current', $record->payload['name']);
    }

    public function test_tie_breaks_equal_versions_by_source_updated_at(): void
    {
        $this->writer->upsert([
            'external_id' => 'rec-100',
            'version' => 2,
            'source_updated_at' => '2024-02-01 10:00:00',
            'payload' => ['name' => 'Older Same Version', 'email' => null],
        ]);

        $this->writer->upsert([
            'external_id' => 'rec-100',
            'version' => 2,
            'source_updated_at' => '2024-02-15 10:00:00',
            'payload' => ['name' => 'Newer Same Version', 'email' => null],
        ]);

        $record = DestinationRecord::where('external_id', 'rec-100')->first();

        $this->assertSame(2, $record->version);
        $this->assertSame('Newer Same Version', $record->payload['name']);
    }
}
