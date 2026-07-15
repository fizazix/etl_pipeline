<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

trait DeterministicSourcePages
{
    protected const EXPECTED_VALID_RECORD_COUNT = 6;

    protected const EXPECTED_MALFORMED_RECORD_COUNT = 4;

    protected function fakeDeterministicSourcePages(): void
    {
        config(['ingestion.source_api_url' => 'http://source.test/records']);

        $pages = [
            '0' => [
                'data' => [
                    [
                        'id' => 'customer-001',
                        'name' => 'Customer 1',
                        'email' => 'alice@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-01-01T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-002',
                        'name' => 'Customer 2',
                        'email' => 'customer-002@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-01-02T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-001',
                        'name' => 'Customer 1',
                        'email' => 'alice@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-01-01T10:00:00Z',
                    ],
                ],
                'next_cursor' => '3',
                'has_more' => true,
            ],
            '3' => [
                'data' => [
                    [
                        'id' => 'customer-003',
                        'name' => 'Customer 3',
                        'email' => 'customer-003@example.com',
                        'status' => 'inactive',
                        'version' => 1,
                        'updated_at' => '2024-01-03T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-001',
                        'name' => 'Customer 1',
                        'email' => 'alice.updated@example.com',
                        'status' => 'pending',
                        'version' => 2,
                        'updated_at' => '2024-02-01T10:00:00Z',
                    ],
                ],
                'next_cursor' => '5',
                'has_more' => true,
            ],
            '5' => [
                'data' => [
                    [
                        'id' => 'customer-004',
                        'name' => 'Customer 4',
                        'email' => 'customer-004@example.com',
                        'status' => 'pending',
                        'version' => 2,
                        'updated_at' => '2024-02-10T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-003',
                        'name' => 'Customer 3 Earlier',
                        'email' => 'customer-003.earlier@example.com',
                        'status' => 'inactive',
                        'version' => 2,
                        'updated_at' => '2024-02-01T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-003',
                        'name' => 'Customer 3 Later',
                        'email' => 'customer-003.later@example.com',
                        'status' => 'inactive',
                        'version' => 2,
                        'updated_at' => '2024-03-01T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-001',
                        'name' => 'Customer 1 Old',
                        'email' => 'alice.old@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-01-01T10:00:00Z',
                    ],
                ],
                'next_cursor' => '7',
                'has_more' => true,
            ],
            '7' => [
                'data' => [
                    [
                        'id' => 'customer-005',
                        'name' => 'Customer 5',
                        'email' => 'customer-005@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-03-01T10:00:00Z',
                    ],
                    [
                        'name' => 'Missing ID',
                        'email' => 'missing@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-03-02T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-bad-version',
                        'name' => 'Bad Version',
                        'email' => 'badversion@example.com',
                        'status' => 'active',
                        'version' => 'two',
                        'updated_at' => '2024-03-03T10:00:00Z',
                    ],
                ],
                'next_cursor' => '10',
                'has_more' => true,
            ],
            '10' => [
                'data' => [
                    [
                        'id' => 'customer-006',
                        'name' => 'Customer 6',
                        'email' => 'customer-006@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-04-01T10:00:00Z',
                    ],
                    [
                        'id' => 'customer-bad-date',
                        'name' => 'Bad Date',
                        'email' => 'baddate@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => 'not-a-date',
                    ],
                    [
                        'id' => 'customer-bad-name',
                        'name' => 12345,
                        'email' => 'badname@example.com',
                        'status' => 'active',
                        'version' => 1,
                        'updated_at' => '2024-04-02T10:00:00Z',
                    ],
                ],
                'next_cursor' => null,
                'has_more' => false,
            ],
        ];

        Http::fake(function ($request) use ($pages) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $cursor = $query['cursor'] ?? '0';

            if (! array_key_exists($cursor, $pages)) {
                return Http::response(['error' => 'unknown cursor'], 404);
            }

            return Http::response($pages[$cursor], 200);
        });
    }
}
