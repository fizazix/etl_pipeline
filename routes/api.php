<?php

use App\Http\Controllers\DestinationRecordController;
use App\Http\Controllers\IngestionErrorController;
use App\Http\Controllers\PipelineStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/pipeline/status', [PipelineStatusController::class, 'status']);
Route::get('/pipeline/loaded-count', [PipelineStatusController::class, 'loadedCount']);
Route::get('/pipeline/rejected-count', [IngestionErrorController::class, 'count']);
Route::get('/pipeline/rejected', [IngestionErrorController::class, 'index']);
Route::get('/pipeline/loaded', [DestinationRecordController::class, 'index']);
