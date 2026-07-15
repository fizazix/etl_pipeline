<?php

return [
    'pipeline_name' => env('INGESTION_PIPELINE_NAME', 'customer-import'),
    'source_api_url' => env('SOURCE_API_URL', 'http://source-api:8081/records'),
    'request_timeout_seconds' => (int) env('SOURCE_API_TIMEOUT', 5),
    'page_size' => (int) env('SOURCE_API_PAGE_SIZE', 50),
    'requests_per_second' => (int) env('SOURCE_API_REQUESTS_PER_SECOND', 4),
    'max_attempts' => (int) env('SOURCE_API_MAX_ATTEMPTS', 5),
];
