<?php

namespace App\Http\Controllers;

use App\Models\IngestionError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngestionErrorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'source_id' => ['sometimes', 'string'],
            'error_type' => ['sometimes', 'string'],
        ]);

        $perPage = $validated['per_page'] ?? 50;

        $query = IngestionError::query()->orderBy('id');

        if (isset($validated['source_id'])) {
            $query->where('source_id', $validated['source_id']);
        }

        if (isset($validated['error_type'])) {
            $query->where('error_type', $validated['error_type']);
        }

        $errors = $query->paginate($perPage);

        return response()->json([
            'data' => collect($errors->items())->map(fn (IngestionError $error) => [
                'source_id' => $error->source_id,
                'source_cursor' => $error->source_cursor,
                'error_type' => $error->error_type,
                'error_details' => $error->error_details,
                'raw_payload' => $error->raw_payload,
                'occurrence_count' => $error->occurrence_count,
                'first_seen_at' => $error->first_seen_at,
                'last_seen_at' => $error->last_seen_at,
            ])->values(),
            'meta' => [
                'current_page' => $errors->currentPage(),
                'per_page' => $errors->perPage(),
                'total' => $errors->total(),
                'last_page' => $errors->lastPage(),
            ],
        ]);
    }
}
