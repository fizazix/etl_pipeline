<?php

namespace App\Http\Controllers;

use App\Models\IngestionError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngestionErrorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 100);

        $errors = IngestionError::query()
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $errors->items(),
            'meta' => [
                'current_page' => $errors->currentPage(),
                'per_page' => $errors->perPage(),
                'total' => $errors->total(),
                'last_page' => $errors->lastPage(),
            ],
        ]);
    }

    public function count(): JsonResponse
    {
        return response()->json([
            'count' => IngestionError::count(),
        ]);
    }
}
