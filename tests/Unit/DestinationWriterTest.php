<?php

namespace Tests\Unit;

use App\Models\DestinationRecord;
use App\Services\Ingestion\DestinationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RequiresMySql;
use Tests\TestCase;

class DestinationWriterTest extends TestCase
{
    use RefreshDatabase;
    use RequiresMySql;

    private DestinationWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRequiresMySql();

        $this->writer = new DestinationWriter;
    }

    public function test_first_version_inserts(): void
    {
        $normalized = $this->normalized();

        $action = $this->writer->upsert($normalized);

        $this->assertSame(DestinationWriter::ACTION_INSERTED, $action);
        $this->assertSame(1, DestinationRecord::count());

        $record = DestinationRecord::where('source_id', 'customer-001')->first();

        $this->assertNotNull($record);
        $this->assertSame('Alice', $record->name);
        $this->assertSame('alice@example.com', $record->email);
        $this->assertSame('active', $record->status);
        $this->assertSame(1, $record->version);
        $this->assertSame('2024-01-01 10:00:00', $record->source_updated_at->format('Y-m-d H:i:s'));
        $this->assertSame($normalized['raw_payload'], $record->raw_payload);
    }

    public function test_exact_duplicate_does_not_create_another_row(): void
    {
        $normalized = $this->normalized();

        $this->assertSame(DestinationWriter::ACTION_INSERTED, $this->writer->upsert($normalized));
        $this->assertSame(DestinationWriter::ACTION_IGNORED, $this->writer->upsert($normalized));
        $this->assertSame(1, DestinationRecord::count());
    }

    public function test_newer_version_updates(): void
    {
        $this->writer->upsert($this->normalized([
            'version' => 1,
            'name' => 'Version One',
            'email' => 'v1@example.com',
            'status' => 'active',
            'raw_payload' => ['id' => 'customer-001', 'version' => 1, 'name' => 'Version One'],
        ]));

        $action = $this->writer->upsert($this->normalized([
            'version' => 2,
            'source_updated_at' => '2024-02-01 10:00:00',
            'name' => 'Version Two',
            'email' => 'v2@example.com',
            'status' => 'pending',
            'raw_payload' => ['id' => 'customer-001', 'version' => 2, 'name' => 'Version Two'],
        ]));

        $this->assertSame(DestinationWriter::ACTION_UPDATED, $action);

        $record = DestinationRecord::where('source_id', 'customer-001')->first();

        $this->assertSame(2, $record->version);
        $this->assertSame('Version Two', $record->name);
        $this->assertSame('v2@example.com', $record->email);
        $this->assertSame('pending', $record->status);
        $this->assertSame('customer-001', $record->raw_payload['id']);
        $this->assertSame(2, $record->raw_payload['version']);
        $this->assertSame('Version Two', $record->raw_payload['name']);
    }

    public function test_older_version_does_not_overwrite(): void
    {
        $this->writer->upsert($this->normalized([
            'version' => 3,
            'source_updated_at' => '2024-03-01 10:00:00',
            'name' => 'Current',
            'email' => 'current@example.com',
            'status' => 'inactive',
            'raw_payload' => ['id' => 'customer-001', 'version' => 3, 'name' => 'Current'],
        ]));

        $action = $this->writer->upsert($this->normalized([
            'version' => 1,
            'source_updated_at' => '2024-01-01 10:00:00',
            'name' => 'Stale',
            'email' => 'stale@example.com',
            'status' => 'active',
            'raw_payload' => ['id' => 'customer-001', 'version' => 1, 'name' => 'Stale'],
        ]));

        $this->assertSame(DestinationWriter::ACTION_IGNORED, $action);

        $record = DestinationRecord::where('source_id', 'customer-001')->first();

        $this->assertSame(3, $record->version);
        $this->assertSame('Current', $record->name);
        $this->assertSame('current@example.com', $record->email);
        $this->assertSame('inactive', $record->status);
        $this->assertSame('customer-001', $record->raw_payload['id']);
        $this->assertSame(3, $record->raw_payload['version']);
        $this->assertSame('Current', $record->raw_payload['name']);
    }

    public function test_equal_version_with_later_timestamp_updates(): void
    {
        $this->writer->upsert($this->normalized([
            'version' => 2,
            'source_updated_at' => '2024-02-01 10:00:00',
            'name' => 'Older Same Version',
            'raw_payload' => ['id' => 'customer-001', 'version' => 2, 'name' => 'Older Same Version'],
        ]));

        $action = $this->writer->upsert($this->normalized([
            'version' => 2,
            'source_updated_at' => '2024-02-15 10:00:00',
            'name' => 'Newer Same Version',
            'raw_payload' => ['id' => 'customer-001', 'version' => 2, 'name' => 'Newer Same Version'],
        ]));

        $this->assertSame(DestinationWriter::ACTION_UPDATED, $action);

        $record = DestinationRecord::where('source_id', 'customer-001')->first();

        $this->assertSame(2, $record->version);
        $this->assertSame('Newer Same Version', $record->name);
    }

    public function test_equal_version_with_earlier_timestamp_does_not_update(): void
    {
        $this->writer->upsert($this->normalized([
            'version' => 2,
            'source_updated_at' => '2024-02-15 10:00:00',
            'name' => 'Current Same Version',
            'raw_payload' => ['id' => 'customer-001', 'version' => 2, 'name' => 'Current Same Version'],
        ]));

        $action = $this->writer->upsert($this->normalized([
            'version' => 2,
            'source_updated_at' => '2024-02-01 10:00:00',
            'name' => 'Older Same Version',
            'raw_payload' => ['id' => 'customer-001', 'version' => 2, 'name' => 'Older Same Version'],
        ]));

        $this->assertSame(DestinationWriter::ACTION_IGNORED, $action);

        $record = DestinationRecord::where('source_id', 'customer-001')->first();

        $this->assertSame('Current Same Version', $record->name);
        $this->assertSame('2024-02-15 10:00:00', $record->source_updated_at->format('Y-m-d H:i:s'));
    }

    public function test_equal_version_and_timestamp_does_not_change_row(): void
    {
        $normalized = $this->normalized([
            'version' => 2,
            'source_updated_at' => '2024-02-15 10:00:00',
            'name' => 'Frozen Record',
            'raw_payload' => ['id' => 'customer-001', 'version' => 2, 'name' => 'Frozen Record'],
        ]);

        $this->writer->upsert($normalized);

        $record = DestinationRecord::where('source_id', 'customer-001')->first();
        $originalUpdatedAt = $record->updated_at;

        $this->travel(5)->seconds();

        $action = $this->writer->upsert($normalized);

        $this->assertSame(DestinationWriter::ACTION_IGNORED, $action);

        $record->refresh();

        $this->assertTrue($originalUpdatedAt->equalTo($record->updated_at));
        $this->assertSame('Frozen Record', $record->name);
    }

    public function test_raw_payload_follows_winning_version(): void
    {
        $this->writer->upsert($this->normalized([
            'version' => 1,
            'source_updated_at' => '2024-01-01 10:00:00',
            'raw_payload' => ['id' => 'customer-001', 'version' => 1, 'marker' => 'v1'],
        ]));

        $this->writer->upsert($this->normalized([
            'version' => 2,
            'source_updated_at' => '2024-02-01 10:00:00',
            'raw_payload' => ['id' => 'customer-001', 'version' => 2, 'marker' => 'v2'],
        ]));

        $this->writer->upsert($this->normalized([
            'version' => 1,
            'source_updated_at' => '2024-01-15 10:00:00',
            'raw_payload' => ['id' => 'customer-001', 'version' => 1, 'marker' => 'stale'],
        ]));

        $record = DestinationRecord::where('source_id', 'customer-001')->first();

        $this->assertSame(2, $record->version);
        $this->assertSame('v2', $record->raw_payload['marker']);
    }

    public function test_database_unique_constraint_prevents_duplicates(): void
    {
        $indexes = collect(Schema::getIndexes('destination_records'));

        $this->assertTrue(
            $indexes->contains(
                fn (array $index): bool => $index['unique'] && in_array('source_id', $index['columns'], true)
            )
        );

        DestinationRecord::create([
            'source_id' => 'customer-001',
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'status' => 'active',
            'version' => 1,
            'source_updated_at' => '2024-01-01 10:00:00',
            'raw_payload' => ['id' => 'customer-001'],
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DestinationRecord::create([
            'source_id' => 'customer-001',
            'name' => 'Duplicate',
            'email' => 'dup@example.com',
            'status' => 'active',
            'version' => 1,
            'source_updated_at' => '2024-01-02 10:00:00',
            'raw_payload' => ['id' => 'customer-001'],
        ]);
    }

    public function test_operation_behaves_correctly_inside_transaction(): void
    {
        $normalized = $this->normalized();

        DB::beginTransaction();

        try {
            $this->writer->upsert($normalized);
            DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $this->assertSame(0, DestinationRecord::count());

        DB::transaction(function () use ($normalized): void {
            $this->writer->upsert($normalized);
        });

        $this->assertSame(1, DestinationRecord::where('source_id', 'customer-001')->count());
    }

    private function normalized(array $overrides = []): array
    {
        return array_merge([
            'source_id' => 'customer-001',
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'status' => 'active',
            'version' => 1,
            'source_updated_at' => '2024-01-01 10:00:00',
            'raw_payload' => [
                'id' => 'customer-001',
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'status' => 'active',
                'version' => 1,
                'updated_at' => '2024-01-01T10:00:00Z',
            ],
        ], $overrides);
    }
}
