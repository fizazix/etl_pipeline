<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\DB;

class DestinationWriter
{
    public function upsert(array $normalized): void
    {
        $now = now()->toDateTimeString();

        DB::statement(
            'INSERT INTO destination_records (external_id, version, source_updated_at, payload, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE
                version = IF(
                    (@accept := new.version > destination_records.version
                        OR (new.version = destination_records.version AND new.source_updated_at > destination_records.source_updated_at)),
                    new.version,
                    destination_records.version
                ),
                source_updated_at = IF(@accept, new.source_updated_at, destination_records.source_updated_at),
                payload = IF(@accept, new.payload, destination_records.payload),
                updated_at = IF(@accept, new.updated_at, destination_records.updated_at)',
            [
                $normalized['external_id'],
                $normalized['version'],
                $normalized['source_updated_at'],
                json_encode($normalized['payload']),
                $now,
                $now,
            ]
        );
    }
}
