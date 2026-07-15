<?php

namespace App\Http\Controllers;

use App\Models\DestinationRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DestinationRecordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'source_id' => ['sometimes', 'string'],
            'status' => ['sometimes', 'string', 'in:active,inactive,pending'],
        ]);

        $perPage = $validated['per_page'] ?? 50;

        $query = DestinationRecord::query()->orderBy('source_id');

        if (isset($validated['source_id'])) {
            $query->where('source_id', $validated['source_id']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $records = $query->paginate($perPage);

        return response()->json([
            'data' => $records->items(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'last_page' => $records->lastPage(),
            ],
        ]);
    }

    public function show(string $sourceId): JsonResponse
    {
        $record = DestinationRecord::query()
            ->where('source_id', $sourceId)
            ->first();

        if ($record === null) {
            return response()->json([
                'message' => 'Record not found.',
            ], 404);
        }

        return response()->json($record);
    }
}
