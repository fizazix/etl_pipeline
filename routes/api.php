<?php

use App\Http\Controllers\DestinationRecordController;
use App\Http\Controllers\IngestionErrorController;
use App\Http\Controllers\PipelineStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/status', [PipelineStatusController::class, 'status']);
Route::get('/records', [DestinationRecordController::class, 'index']);
Route::get('/records/{sourceId}', [DestinationRecordController::class, 'show']);
Route::get('/errors', [IngestionErrorController::class, 'index']);
