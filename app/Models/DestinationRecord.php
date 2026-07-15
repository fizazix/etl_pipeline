<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DestinationRecord extends Model
{
    protected $fillable = [
        'source_id',
        'name',
        'email',
        'status',
        'version',
        'source_updated_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'source_updated_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }
}
