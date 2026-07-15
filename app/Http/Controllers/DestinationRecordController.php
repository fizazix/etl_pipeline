<?php

namespace App\Http\Controllers;

use App\Models\DestinationRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DestinationRecordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 100);

        $records = DestinationRecord::query()
            ->orderBy('external_id')
            ->paginate($perPage);

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
}
