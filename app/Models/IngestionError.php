<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngestionError extends Model
{
    protected $fillable = [
        'source_id',
        'source_cursor',
        'error_type',
        'error_details',
        'raw_payload',
        'fingerprint',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'error_details' => 'array',
            'raw_payload' => 'array',
            'occurrence_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
