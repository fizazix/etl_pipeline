<?php

namespace Tests\Feature;

use App\Models\DestinationRecord;
use App\Models\IngestionError;
use App\Models\PipelineCheckpoint;
use App\Services\Ingestion\IngestionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RequiresMySql;
use Tests\Concerns\RequiresSourceApi;
use Tests\TestCase;

class FullSourceApiIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use RequiresMySql;
    use RequiresSourceApi;

    private const EXPECTED_VALID_RECORD_COUNT = 297;

    private const EXPECTED_MALFORMED_RECORD_COUNT = 10;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRequiresMySql();
        $this->setUpRequiresSourceApi();
    }

    public function test_full_source_api_pipeline_produces_expected_outcomes(): void
    {
        app(IngestionPipeline::class)->run(force: true);

        $checkpoint = PipelineCheckpoint::where('pipeline_name', IngestionPipeline::PIPELINE_NAME)->firstOrFail();

        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $checkpoint->status);
        $this->assertNull($checkpoint->next_cursor);
        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());

        $alice = DestinationRecord::where('source_id', 'customer-001')->firstOrFail();
        $this->assertSame(2, $alice->version);
        $this->assertSame('alice1.updated@example.com', $alice->email);

        $customerTwo = DestinationRecord::where('source_id', 'customer-002')->firstOrFail();
        $this->assertSame(3, $customerTwo->version);
    }
}
