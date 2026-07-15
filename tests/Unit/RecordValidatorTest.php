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
        $record = [
            'id' => 'customer-001',
            'name' => 'Alice',
            'email' => '  Alice@Example.COM ',
            'status' => 'active',
            'version' => 1,
            'updated_at' => '2024-01-01T10:00:00Z',
        ];

        $result = $this->validator->validate($record);

        $this->assertTrue($result['valid']);
        $this->assertSame('customer-001', $result['normalized']['source_id']);
        $this->assertSame('Alice', $result['normalized']['name']);
        $this->assertSame('alice@example.com', $result['normalized']['email']);
        $this->assertSame('active', $result['normalized']['status']);
        $this->assertSame(1, $result['normalized']['version']);
        $this->assertSame('2024-01-01 10:00:00', $result['normalized']['source_updated_at']);
        $this->assertSame($record, $result['normalized']['raw_payload']);
    }

    #[DataProvider('invalidRecordProvider')]
    public function test_rejects_invalid_records(mixed $record, string $expectedField): void
    {
        $result = $this->validator->validate($record);

        $this->assertFalse($result['valid']);
        $this->assertSame('validation_error', $result['error_type']);
        $this->assertArrayHasKey('messages', $result['error_details']);

        $fields = array_column($result['error_details']['messages'], 'field');
        $this->assertContains($expectedField, $fields);
    }

    public static function invalidRecordProvider(): array
    {
        $base = [
            'id' => 'customer-001',
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'status' => 'active',
            'version' => 1,
            'updated_at' => '2024-01-01T10:00:00Z',
        ];

        return [
            'missing id' => [
                array_diff_key($base, ['id' => true]),
                'id',
            ],
            'non-string id' => [
                array_merge($base, ['id' => 12345]),
                'id',
            ],
            'id too long' => [
                array_merge($base, ['id' => str_repeat('a', 101)]),
                'id',
            ],
            'missing name' => [
                array_diff_key($base, ['name' => true]),
                'name',
            ],
            'non-string name' => [
                array_merge($base, ['name' => 12345]),
                'name',
            ],
            'missing email' => [
                array_diff_key($base, ['email' => true]),
                'email',
            ],
            'invalid email format' => [
                array_merge($base, ['email' => 'not-an-email']),
                'email',
            ],
            'invalid email type' => [
                array_merge($base, ['email' => 12345]),
                'email',
            ],
            'missing status' => [
                array_diff_key($base, ['status' => true]),
                'status',
            ],
            'invalid status' => [
                array_merge($base, ['status' => 'archived']),
                'status',
            ],
            'missing version' => [
                array_diff_key($base, ['version' => true]),
                'version',
            ],
            'numeric string version' => [
                array_merge($base, ['version' => '1']),
                'version',
            ],
            'invalid version string' => [
                array_merge($base, ['version' => 'two']),
                'version',
            ],
            'invalid version zero' => [
                array_merge($base, ['version' => 0]),
                'version',
            ],
            'missing updated_at' => [
                array_diff_key($base, ['updated_at' => true]),
                'updated_at',
            ],
            'invalid date' => [
                array_merge($base, ['updated_at' => 'not-a-date']),
                'updated_at',
            ],
        ];
    }

    public function test_rejects_non_array_payload(): void
    {
        $result = $this->validator->validate('invalid-record');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['source_id']);
        $this->assertSame('invalid-record', $result['raw_payload']);
        $this->assertSame('validation_error', $result['error_type']);
        $this->assertSame(
            'The record must be an object.',
            $result['error_details']['messages'][0]['message']
        );
    }
}
