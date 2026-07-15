<?php

return [
    'page-1' => [
        'data' => [
            [
                'external_id' => 'rec-001',
                'version' => 1,
                'updated_at' => '2024-01-01T10:00:00Z',
                'name' => 'Alice Johnson',
                'email' => 'alice@example.com',
            ],
            [
                'external_id' => 'rec-002',
                'version' => 1,
                'updated_at' => '2024-01-02T10:00:00Z',
                'name' => 'Bob Smith',
                'email' => 'bob@example.com',
            ],
            [
                'external_id' => 'rec-001',
                'version' => 1,
                'updated_at' => '2024-01-01T10:00:00Z',
                'name' => 'Alice Johnson',
                'email' => 'alice@example.com',
            ],
        ],
        'next_cursor' => 'page-2',
    ],
    'page-2' => [
        'data' => [
            [
                'external_id' => 'rec-003',
                'version' => 1,
                'updated_at' => '2024-01-03T10:00:00Z',
                'name' => 'Carol White',
                'email' => 'carol@example.com',
            ],
            [
                'external_id' => 'rec-001',
                'version' => 2,
                'updated_at' => '2024-02-01T10:00:00Z',
                'name' => 'Alice Johnson',
                'email' => 'alice.updated@example.com',
            ],
        ],
        'next_cursor' => 'fail-500',
    ],
    'page-3' => [
        'data' => [
            [
                'external_id' => 'rec-004',
                'version' => 2,
                'updated_at' => '2024-02-10T10:00:00Z',
                'name' => 'David Lee',
                'email' => 'david@example.com',
            ],
            [
                'external_id' => 'rec-001',
                'version' => 1,
                'updated_at' => '2024-01-01T10:00:00Z',
                'name' => 'Alice Johnson Old',
                'email' => 'alice.old@example.com',
            ],
        ],
        'next_cursor' => 'fail-429',
    ],
    'page-4' => [
        'data' => [
            [
                'external_id' => 'rec-005',
                'version' => 1,
                'updated_at' => '2024-03-01T10:00:00Z',
                'name' => 'Eve Adams',
                'email' => 'eve@example.com',
            ],
            [
                'version' => 1,
                'updated_at' => '2024-03-02T10:00:00Z',
                'name' => 'Missing ID Record',
            ],
            [
                'external_id' => 'rec-bad-version',
                'version' => 'two',
                'updated_at' => '2024-03-03T10:00:00Z',
                'name' => 'Bad Version Record',
            ],
        ],
        'next_cursor' => 'page-5',
    ],
    'page-5' => [
        'data' => [
            [
                'external_id' => 'rec-006',
                'version' => 1,
                'updated_at' => '2024-04-01T10:00:00Z',
                'name' => 'Frank Miller',
                'email' => 'frank@example.com',
            ],
            [
                'external_id' => 'rec-bad-date',
                'version' => 1,
                'updated_at' => 'not-a-date',
                'name' => 'Bad Date Record',
            ],
            [
                'external_id' => 'rec-bad-name',
                'version' => 1,
                'updated_at' => '2024-04-02T10:00:00Z',
                'name' => 12345,
            ],
        ],
        'next_cursor' => null,
    ],
];
