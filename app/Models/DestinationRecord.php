<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DestinationRecord extends Model
{
    protected $fillable = [
        'external_id',
        'version',
        'source_updated_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'source_updated_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
