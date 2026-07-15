<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\DB;

class DestinationWriter
{
    public const ACTION_INSERTED = 'inserted';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_IGNORED = 'ignored';

    public function upsert(array $normalized): string
    {
        $now = now()->toDateTimeString();

        DB::statement(
            'INSERT INTO destination_records (
                source_id, name, email, status, version, source_updated_at, raw_payload, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) AS new
            ON DUPLICATE KEY UPDATE
                name = IF(
                    (@accept := new.version > destination_records.version
                        OR (new.version = destination_records.version
                            AND new.source_updated_at > destination_records.source_updated_at)),
                    new.name,
                    destination_records.name
                ),
                email = IF(@accept, new.email, destination_records.email),
                status = IF(@accept, new.status, destination_records.status),
                version = IF(@accept, new.version, destination_records.version),
                source_updated_at = IF(@accept, new.source_updated_at, destination_records.source_updated_at),
                raw_payload = IF(@accept, new.raw_payload, destination_records.raw_payload),
                updated_at = IF(@accept, new.updated_at, destination_records.updated_at)',
            [
                $normalized['source_id'],
                $normalized['name'],
                $normalized['email'],
                $normalized['status'],
                $normalized['version'],
                $normalized['source_updated_at'],
                json_encode($normalized['raw_payload']),
                $now,
                $now,
            ]
        );

        $result = DB::selectOne('SELECT @accept AS accept, ROW_COUNT() AS affected_rows');

        if ($result->accept === null) {
            return self::ACTION_INSERTED;
        }

        if ((int) $result->accept === 1) {
            return self::ACTION_UPDATED;
        }

        return self::ACTION_IGNORED;
    }
}
