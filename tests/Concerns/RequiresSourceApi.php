<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

trait RequiresSourceApi
{
    protected function setUpRequiresSourceApi(): void
    {
        if (! filter_var(env('SOURCE_API_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Requires SOURCE_API_INTEGRATION=true.');
        }

        $healthUrl = $this->sourceApiHealthUrl();

        try {
            $response = Http::timeout(2)->get($healthUrl);
        } catch (\Throwable) {
            $this->markTestSkipped('Source API is not reachable at '.$healthUrl.'.');
        }

        if (! $response->successful()) {
            $this->markTestSkipped('Source API health check failed at '.$healthUrl.'.');
        }
    }

    private function sourceApiHealthUrl(): string
    {
        $recordsUrl = (string) config('ingestion.source_api_url');
        $parsed = parse_url($recordsUrl);

        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? 'source-api';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        return $scheme.'://'.$host.$port.'/health';
    }
}
