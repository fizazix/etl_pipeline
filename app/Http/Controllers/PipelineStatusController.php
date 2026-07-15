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
        $pipelineName = config('ingestion.pipeline_name');

        $checkpoint = PipelineCheckpoint::query()
            ->where('pipeline_name', $pipelineName)
            ->first();

        $recordsLoaded = DestinationRecord::count();
        $isolatedErrors = IngestionError::count();
        $errorOccurrences = (int) IngestionError::sum('occurrence_count');

        if ($checkpoint === null) {
            return response()->json([
                'pipeline' => [
                    'name' => $pipelineName,
                    'status' => PipelineCheckpoint::STATUS_PENDING,
                    'next_cursor' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'last_successful_page_at' => null,
                    'last_error' => null,
                ],
                'records_loaded' => $recordsLoaded,
                'isolated_errors' => $isolatedErrors,
                'error_occurrences' => $errorOccurrences,
            ]);
        }

        return response()->json([
            'pipeline' => [
                'name' => $checkpoint->pipeline_name,
                'status' => $checkpoint->status,
                'next_cursor' => $checkpoint->next_cursor,
                'started_at' => $checkpoint->started_at,
                'completed_at' => $checkpoint->completed_at,
                'last_successful_page_at' => $checkpoint->last_successful_page_at,
                'last_error' => $checkpoint->last_error,
            ],
            'records_loaded' => $recordsLoaded,
            'isolated_errors' => $isolatedErrors,
            'error_occurrences' => $errorOccurrences,
        ]);
    }
}
