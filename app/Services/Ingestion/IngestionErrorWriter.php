<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\DB;

class IngestionErrorWriter
{
    public function upsertValidationError(array $invalidResult, string $sourceCursor): void
    {
        $errorType = $invalidResult['error_type'];
        $fingerprint = $this->fingerprint($sourceCursor, $invalidResult['raw_payload'], $errorType);
        $now = now()->toDateTimeString();
        $errorDetailsJson = $this->stableJsonEncode($invalidResult['error_details']);
        $rawPayloadJson = $this->stableJsonEncode($invalidResult['raw_payload']);

        DB::statement(
            'INSERT INTO ingestion_errors (
                source_id, source_cursor, error_type, error_details, raw_payload,
                fingerprint, occurrence_count, first_seen_at, last_seen_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                occurrence_count = ingestion_errors.occurrence_count + 1,
                last_seen_at = VALUES(last_seen_at),
                updated_at = VALUES(updated_at)',
            [
                $invalidResult['source_id'],
                $sourceCursor,
                $errorType,
                $errorDetailsJson,
                $rawPayloadJson,
                $fingerprint,
                $now,
                $now,
                $now,
                $now,
            ]
        );
    }

    private function fingerprint(string $sourceCursor, mixed $rawPayload, string $errorType): string
    {
        return hash('sha256', $this->stableJsonEncode([
            'source_cursor' => $sourceCursor,
            'raw_payload' => $rawPayload,
            'error_type' => $errorType,
        ]));
    }

    private function stableJsonEncode(mixed $value): string
    {
        return json_encode(
            $this->sortKeysRecursive($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private function sortKeysRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortKeysRecursive($item), $value);
        }

        $sorted = [];

        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $sorted[$key] = $this->sortKeysRecursive($value[$key]);
        }

        return $sorted;
    }
}
