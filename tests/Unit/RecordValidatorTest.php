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
        return [
            'missing id' => [
                [
                    'name' => 'Alice',
                    'email' => 'alice@example.com',
                    'status' => 'active',
                    'version' => 1,
                    'updated_at' => '2024-01-01T10:00:00Z',
                ],
                'id',
            ],
            'invalid email type' => [
                [
                    'id' => 'customer-bad-email-int',
                    'name' => 'Bad Email',
                    'email' => 12345,
                    'status' => 'active',
                    'version' => 1,
                    'updated_at' => '2024-01-01T10:00:00Z',
                ],
                'email',
            ],
            'invalid status' => [
                [
                    'id' => 'customer-bad-status',
                    'name' => 'Bad Status',
                    'email' => 'bad@example.com',
                    'status' => 'archived',
                    'version' => 1,
                    'updated_at' => '2024-01-01T10:00:00Z',
                ],
                'status',
            ],
            'invalid version string' => [
                [
                    'id' => 'customer-bad-version',
                    'name' => 'Bad Version',
                    'email' => 'bad@example.com',
                    'status' => 'active',
                    'version' => 'two',
                    'updated_at' => '2024-01-01T10:00:00Z',
                ],
                'version',
            ],
            'invalid version zero' => [
                [
                    'id' => 'customer-bad-version-zero',
                    'name' => 'Bad Version Zero',
                    'email' => 'bad@example.com',
                    'status' => 'active',
                    'version' => 0,
                    'updated_at' => '2024-01-01T10:00:00Z',
                ],
                'version',
            ],
            'invalid date' => [
                [
                    'id' => 'customer-bad-date',
                    'name' => 'Bad Date',
                    'email' => 'bad@example.com',
                    'status' => 'active',
                    'version' => 1,
                    'updated_at' => 'not-a-date',
                ],
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
