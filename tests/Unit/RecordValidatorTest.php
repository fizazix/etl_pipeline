<?php

namespace Tests\Unit;

use App\Services\Ingestion\RecordValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecordValidatorTest extends TestCase
{
    private RecordValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new RecordValidator;
    }

    public function test_accepts_valid_record(): void
    {
        $this->assertNull($this->validator->validate([
            'external_id' => 'rec-001',
            'version' => 1,
            'updated_at' => '2024-01-01T10:00:00Z',
            'name' => 'Alice',
        ]));
    }

    #[DataProvider('invalidRecordProvider')]
    public function test_rejects_invalid_records(array $record, string $expectedCode): void
    {
        $error = $this->validator->validate($record);

        $this->assertNotNull($error);
        $this->assertSame($expectedCode, $error['error_code']);
    }

    public static function invalidRecordProvider(): array
    {
        return [
            'missing external_id' => [
                ['version' => 1, 'updated_at' => '2024-01-01T10:00:00Z', 'name' => 'Alice'],
                'missing_external_id',
            ],
            'invalid version type' => [
                ['external_id' => 'rec-001', 'version' => 'one', 'updated_at' => '2024-01-01T10:00:00Z', 'name' => 'Alice'],
                'invalid_version',
            ],
            'missing updated_at' => [
                ['external_id' => 'rec-001', 'version' => 1, 'name' => 'Alice'],
                'missing_updated_at',
            ],
            'invalid updated_at' => [
                ['external_id' => 'rec-001', 'version' => 1, 'updated_at' => 'not-a-date', 'name' => 'Alice'],
                'invalid_updated_at',
            ],
            'missing name' => [
                ['external_id' => 'rec-001', 'version' => 1, 'updated_at' => '2024-01-01T10:00:00Z', 'name' => ''],
                'missing_name',
            ],
            'invalid name type' => [
                ['external_id' => 'rec-001', 'version' => 1, 'updated_at' => '2024-01-01T10:00:00Z', 'name' => 123],
                'invalid_name',
            ],
        ];
    }
}
