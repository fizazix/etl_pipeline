<?php

namespace App\Services\Ingestion;

class RecordValidator
{
    public function validate(array $record): ?array
    {
        if (! array_key_exists('external_id', $record) || $record['external_id'] === '' || $record['external_id'] === null) {
            return $this->error('missing_external_id', 'The external_id field is required.');
        }

        if (! is_string($record['external_id'])) {
            return $this->error('invalid_external_id', 'The external_id field must be a string.');
        }

        if (! array_key_exists('version', $record)) {
            return $this->error('missing_version', 'The version field is required.');
        }

        if (! is_int($record['version']) || $record['version'] < 1) {
            return $this->error('invalid_version', 'The version field must be a positive integer.');
        }

        if (! array_key_exists('updated_at', $record) || $record['updated_at'] === '' || $record['updated_at'] === null) {
            return $this->error('missing_updated_at', 'The updated_at field is required.');
        }

        if (! is_string($record['updated_at']) || strtotime($record['updated_at']) === false) {
            return $this->error('invalid_updated_at', 'The updated_at field must be a valid datetime string.');
        }

        if (! array_key_exists('name', $record) || $record['name'] === '' || $record['name'] === null) {
            return $this->error('missing_name', 'The name field is required.');
        }

        if (! is_string($record['name'])) {
            return $this->error('invalid_name', 'The name field must be a string.');
        }

        return null;
    }

    public function normalize(array $record): array
    {
        return [
            'external_id' => $record['external_id'],
            'version' => $record['version'],
            'source_updated_at' => date('Y-m-d H:i:s', strtotime($record['updated_at'])),
            'payload' => [
                'name' => $record['name'],
                'email' => $record['email'] ?? null,
            ],
        ];
    }

    private function error(string $code, string $message): array
    {
        return [
            'error_code' => $code,
            'error_message' => $message,
        ];
    }
}
