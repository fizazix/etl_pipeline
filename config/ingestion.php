<?php

return [
    'pipeline_name' => env('INGESTION_PIPELINE_NAME', 'default'),
    'source_api_url' => env('SOURCE_API_URL', 'http://source-api:8080/records'),
    'max_retries' => (int) env('INGESTION_MAX_RETRIES', 5),
    'backoff_base_ms' => (int) env('INGESTION_BACKOFF_BASE_MS', 200),
    'backoff_max_ms' => (int) env('INGESTION_BACKOFF_MAX_MS', 5000),
    'request_timeout_seconds' => (int) env('INGESTION_REQUEST_TIMEOUT', 10),
];
