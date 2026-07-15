<?php

namespace App\Services\Ingestion;

use DateTimeImmutable;

class RecordValidator
{
    private const ALLOWED_STATUSES = ['active', 'inactive', 'pending'];

    public function validate(mixed $record): array
    {
        if (! is_array($record)) {
            return $this->invalid(
                $record,
                null,
                [['field' => null, 'message' => 'The record must be an object.']]
            );
        }

        $messages = [];
        $sourceId = $this->extractSourceId($record);

        if (! array_key_exists('id', $record) || $record['id'] === null || $record['id'] === '') {
            $messages[] = ['field' => 'id', 'message' => 'The id field is required.'];
        } elseif (! is_string($record['id'])) {
            $messages[] = ['field' => 'id', 'message' => 'The id field must be a string.'];
        } elseif (strlen($record['id']) > 100) {
            $messages[] = ['field' => 'id', 'message' => 'The id field must not exceed 100 characters.'];
        }

        if (! array_key_exists('name', $record) || $record['name'] === null || $record['name'] === '') {
            $messages[] = ['field' => 'name', 'message' => 'The name field is required.'];
        } elseif (! is_string($record['name'])) {
            $messages[] = ['field' => 'name', 'message' => 'The name field must be a string.'];
        } elseif (strlen($record['name']) > 255) {
            $messages[] = ['field' => 'name', 'message' => 'The name field must not exceed 255 characters.'];
        }

        if (! array_key_exists('email', $record) || $record['email'] === null || $record['email'] === '') {
            $messages[] = ['field' => 'email', 'message' => 'The email field is required.'];
        } elseif (! is_string($record['email'])) {
            $messages[] = ['field' => 'email', 'message' => 'The email field must be a string.'];
        } elseif (strlen($record['email']) > 255) {
            $messages[] = ['field' => 'email', 'message' => 'The email field must not exceed 255 characters.'];
        } else {
            $trimmedEmail = trim($record['email']);

            if (filter_var($trimmedEmail, FILTER_VALIDATE_EMAIL) === false) {
                $messages[] = ['field' => 'email', 'message' => 'The email field must be a valid email address.'];
            }
        }

        if (! array_key_exists('status', $record) || $record['status'] === null || $record['status'] === '') {
            $messages[] = ['field' => 'status', 'message' => 'The status field is required.'];
        } elseif (! is_string($record['status'])) {
            $messages[] = ['field' => 'status', 'message' => 'The status field must be a string.'];
        } elseif (! in_array($record['status'], self::ALLOWED_STATUSES, true)) {
            $messages[] = ['field' => 'status', 'message' => 'The status field must be one of: active, inactive, pending.'];
        }

        if (! array_key_exists('version', $record)) {
            $messages[] = ['field' => 'version', 'message' => 'The version field is required.'];
        } elseif (! is_int($record['version']) || $record['version'] < 1) {
            $messages[] = ['field' => 'version', 'message' => 'The version field must be an integer of at least 1.'];
        }

        if (! array_key_exists('updated_at', $record) || $record['updated_at'] === null || $record['updated_at'] === '') {
            $messages[] = ['field' => 'updated_at', 'message' => 'The updated_at field is required.'];
        } elseif (! is_string($record['updated_at'])) {
            $messages[] = ['field' => 'updated_at', 'message' => 'The updated_at field must be a string.'];
        } elseif ($this->parseUpdatedAt($record['updated_at']) === null) {
            $messages[] = ['field' => 'updated_at', 'message' => 'The updated_at field must be a valid date.'];
        }

        if ($messages !== []) {
            return $this->invalid($record, $sourceId, $messages);
        }

        return [
            'valid' => true,
            'normalized' => [
                'source_id' => $record['id'],
                'name' => $record['name'],
                'email' => strtolower(trim($record['email'])),
                'status' => $record['status'],
                'version' => $record['version'],
                'source_updated_at' => $this->parseUpdatedAt($record['updated_at']),
                'raw_payload' => $record,
            ],
        ];
    }

    private function invalid(mixed $record, ?string $sourceId, array $messages): array
    {
        return [
            'valid' => false,
            'source_id' => $sourceId,
            'raw_payload' => $record,
            'error_type' => 'validation_error',
            'error_details' => [
                'messages' => $messages,
            ],
        ];
    }

    private function extractSourceId(mixed $record): ?string
    {
        if (! is_array($record)) {
            return null;
        }

        if (! array_key_exists('id', $record) || ! is_string($record['id']) || $record['id'] === '') {
            return null;
        }

        return $record['id'];
    }

    private function parseUpdatedAt(string $value): ?string
    {
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $date->format('Y-m-d H:i:s');
    }
}
