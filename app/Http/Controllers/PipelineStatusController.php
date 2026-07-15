<?php

namespace App\Http\Controllers;

use App\Models\DestinationRecord;
use App\Models\IngestionError;
use App\Models\PipelineCheckpoint;
use Illuminate\Http\JsonResponse;

class PipelineStatusController extends Controller
{
    public function status(): JsonResponse
    {
        $checkpoint = PipelineCheckpoint::query()
            ->where('pipeline_name', config('ingestion.pipeline_name'))
            ->first();

        if ($checkpoint === null) {
            return response()->json([
                'pipeline_name' => config('ingestion.pipeline_name'),
                'status' => PipelineCheckpoint::STATUS_IDLE,
                'cursor' => null,
                'started_at' => null,
                'completed_at' => null,
                'last_error' => null,
            ]);
        }

        return response()->json([
            'pipeline_name' => $checkpoint->pipeline_name,
            'status' => $checkpoint->status,
            'cursor' => $checkpoint->cursor,
            'started_at' => $checkpoint->started_at,
            'completed_at' => $checkpoint->completed_at,
            'last_error' => $checkpoint->last_error,
        ]);
    }

    public function loadedCount(): JsonResponse
    {
        return response()->json([
            'count' => DestinationRecord::count(),
        ]);
    }
}
