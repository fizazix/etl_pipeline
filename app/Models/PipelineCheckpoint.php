<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineCheckpoint extends Model
{
    public const STATUS_IDLE = 'idle';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'pipeline_name',
        'cursor',
        'status',
        'last_error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
