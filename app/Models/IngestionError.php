<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngestionError extends Model
{
    protected $fillable = [
        'external_id',
        'source_cursor',
        'raw_payload',
        'error_message',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }
}
