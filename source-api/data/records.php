<?php

declare(strict_types=1);

function buildCustomerRecord(
    int $number,
    int $version = 1,
    ?string $updatedAt = null,
    ?string $status = null,
    ?string $email = null,
): array {
    $id = sprintf('customer-%03d', $number);
    $statuses = ['active', 'inactive', 'pending'];
    $resolvedStatus = $status ?? $statuses[$number % 3];

    return [
        'id' => $id,
        'name' => 'Customer '.$number,
        'email' => $email ?? strtolower($id).'@example.com',
        'status' => $resolvedStatus,
        'version' => $version,
        'updated_at' => $updatedAt ?? sprintf('2024-01-%02dT10:00:00Z', ($number % 28) + 1),
    ];
}

function buildDataset(): array
{
    $dataset = [];

    $customer001V1 = buildCustomerRecord(1, 1, '2024-01-01T10:00:00Z', 'active', 'alice1@example.com');
    $dataset[] = $customer001V1;
    $dataset[] = $customer001V1;
    $dataset[] = [
        ...$customer001V1,
        'email' => 'alice1.updated@example.com',
        'status' => 'pending',
        'version' => 2,
        'updated_at' => '2024-02-01T10:00:00Z',
    ];

    $dataset[] = buildCustomerRecord(2, 3, '2024-03-15T10:00:00Z');
    $dataset[] = buildCustomerRecord(2, 2, '2024-03-10T10:00:00Z');

    $dataset[] = buildCustomerRecord(3, 2, '2024-04-01T10:00:00Z');
    $dataset[] = buildCustomerRecord(3, 2, '2024-04-01T11:00:00Z');

    $dataset[] = buildCustomerRecord(4, 2, '2024-05-01T11:00:00Z');
    $dataset[] = buildCustomerRecord(4, 2, '2024-05-01T10:00:00Z');

    for ($number = 5; $number <= 145; $number++) {
        $dataset[] = buildCustomerRecord($number);
    }

    $dataset[] = [
        'name' => 'Missing ID',
        'email' => 'missing-id@example.com',
        'status' => 'active',
        'version' => 1,
        'updated_at' => '2024-06-01T10:00:00Z',
    ];

    $dataset[] = [
        'id' => 'customer-bad-email-int',
        'name' => 'Bad Email Integer',
        'email' => 12345,
        'status' => 'active',
        'version' => 1,
        'updated_at' => '2024-06-01T10:01:00Z',
    ];

    $dataset[] = [
        'id' => 'customer-bad-email',
        'name' => 'Bad Email String',
        'email' => 'not-an-email',
        'status' => 'active',
        'version' => 1,
        'updated_at' => '2024-06-01T10:02:00Z',
    ];

    $dataset[] = [
        'id' => 'customer-missing-name',
        'email' => 'noname@example.com',
        'status' => 'active',
        'version' => 1,
        'updated_at' => '2024-06-01T10:03:00Z',
    ];

    $dataset[] = [
        'id' => 'customer-null-status',
        'name' => 'Null Status',
        'email' => 'nullstatus@example.com',
        'status' => null,
        'version' => 1,
        'updated_at' => '2024-06-01T10:04:00Z',
    ];

    $dataset[] = [
        'id' => 'customer-bad-status',
        'name' => 'Bad Status',
        'email' => 'badstatus@example.com',
        'status' => 'archived',
        'version' => 1,
        'updated_at' => '2024-06-01T10:05:00Z',
    ];

    $dataset[] = [
        'id' => 'customer-bad-version-string',
        'name' => 'Bad Version String',
        'email' => 'badversion@example.com',
        'status' => 'active',
        'version' => 'two',
        'updated_at' => '2024-06-01T10:06:00Z',
    ];

    $dataset[] = [
        'id' => 'customer-bad-version-zero',
        'name' => 'Bad Version Zero',
        'email' => 'badversionzero@example.com',
        'status' => 'active',
        'version' => 0,
        'updated_at' => '2024-06-01T10:07:00Z',
    ];

    $dataset[] = [
        'id' => 'customer-bad-date',
        'name' => 'Bad Date',
        'email' => 'baddate@example.com',
        'status' => 'active',
        'version' => 1,
        'updated_at' => 'not-a-date',
    ];

    $dataset[] = 'not-a-record';

    for ($number = 146; $number <= 297; $number++) {
        $dataset[] = buildCustomerRecord($number);
    }

    return $dataset;
}

return buildDataset();
