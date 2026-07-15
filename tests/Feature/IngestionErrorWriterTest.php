<?php

namespace Tests\Feature;

use App\Models\IngestionError;
use App\Services\Ingestion\IngestionErrorWriter;
use App\Services\Ingestion\RecordValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IngestionErrorWriterTest extends TestCase
{
    use RefreshDatabase;

    private RecordValidator $validator;

    private IngestionErrorWriter $errorWriter;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Ingestion error writer tests require MySQL.');
        }

        $this->validator = new RecordValidator;
        $this->errorWriter = new IngestionErrorWriter;
    }

    public function test_same_malformed_record_twice_creates_one_error_row(): void
    {
        $invalidResult = $this->validator->validate([
            'name' => 'Missing ID',
            'email' => 'missing@example.com',
            'status' => 'active',
            'version' => 1,
            'updated_at' => '2024-06-01T10:00:00Z',
        ]);

        DB::transaction(function () use ($invalidResult): void {
            $this->errorWriter->upsertValidationError($invalidResult, 'page-4');
            $this->errorWriter->upsertValidationError($invalidResult, 'page-4');
        });

        $this->assertSame(1, IngestionError::count());
    }

    public function test_occurrence_count_increases_on_duplicate_fingerprint(): void
    {
        $invalidResult = $this->validator->validate([
            'id' => 'customer-bad-version',
            'name' => 'Bad Version',
            'email' => 'bad@example.com',
            'status' => 'active',
            'version' => 'two',
            'updated_at' => '2024-06-01T10:00:00Z',
        ]);

        DB::transaction(function () use ($invalidResult): void {
            $this->errorWriter->upsertValidationError($invalidResult, 'page-4');
            $this->errorWriter->upsertValidationError($invalidResult, 'page-4');
        });

        $error = IngestionError::first();

        $this->assertSame(2, $error->occurrence_count);
    }

    public function test_first_seen_at_remains_unchanged_on_duplicate_fingerprint(): void
    {
        $invalidResult = $this->validator->validate([
            'id' => 'customer-bad-date',
            'name' => 'Bad Date',
            'email' => 'bad@example.com',
            'status' => 'active',
            'version' => 1,
            'updated_at' => 'not-a-date',
        ]);

        DB::transaction(function () use ($invalidResult): void {
            $this->errorWriter->upsertValidationError($invalidResult, 'page-5');
        });

        $firstSeenAt = IngestionError::first()->first_seen_at;

        $this->travel(5)->seconds();

        DB::transaction(function () use ($invalidResult): void {
            $this->errorWriter->upsertValidationError($invalidResult, 'page-5');
        });

        $error = IngestionError::first();

        $this->assertTrue($firstSeenAt->equalTo($error->first_seen_at));
        $this->assertTrue($error->last_seen_at->greaterThan($firstSeenAt));
    }

    public function test_different_malformed_payloads_create_different_fingerprints(): void
    {
        $missingIdResult = $this->validator->validate([
            'name' => 'Missing ID',
            'email' => 'missing@example.com',
            'status' => 'active',
            'version' => 1,
            'updated_at' => '2024-06-01T10:00:00Z',
        ]);

        $badVersionResult = $this->validator->validate([
            'id' => 'customer-bad-version',
            'name' => 'Bad Version',
            'email' => 'bad@example.com',
            'status' => 'active',
            'version' => 'two',
            'updated_at' => '2024-06-01T10:00:00Z',
        ]);

        DB::transaction(function () use ($missingIdResult, $badVersionResult): void {
            $this->errorWriter->upsertValidationError($missingIdResult, 'page-4');
            $this->errorWriter->upsertValidationError($badVersionResult, 'page-4');
        });

        $errors = IngestionError::orderBy('id')->get();

        $this->assertSame(2, $errors->count());
        $this->assertNotSame($errors[0]->fingerprint, $errors[1]->fingerprint);
    }
}
