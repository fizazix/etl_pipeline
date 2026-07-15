<?php

namespace Tests\Feature;

use App\Models\DestinationRecord;
use App\Models\IngestionError;
use App\Models\PipelineCheckpoint;
use App\Services\Ingestion\IngestionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RequiresMySql;
use Tests\Support\DeterministicSourcePages;
use Tests\TestCase;

class EtlRunCommandTest extends TestCase
{
    use DeterministicSourcePages;
    use RefreshDatabase;
    use RequiresMySql;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRequiresMySql();
    }

    public function test_etl_run_completes_successfully(): void
    {
        $this->fakeDeterministicSourcePages();

        $exitCode = Artisan::call('etl:run');

        $this->assertSame(0, $exitCode);
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $this->checkpoint()->status);
        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
    }

    public function test_etl_run_exits_success_when_already_completed(): void
    {
        $this->fakeDeterministicSourcePages();

        Artisan::call('etl:run');
        $destinationCount = DestinationRecord::count();
        $errorCount = IngestionError::count();

        $exitCode = Artisan::call('etl:run');

        $this->assertSame(0, $exitCode);
        $this->assertSame($destinationCount, DestinationRecord::count());
        $this->assertSame($errorCount, IngestionError::count());
        $this->assertStringContainsString('Pipeline already completed.', Artisan::output());
    }

    public function test_etl_run_force_remains_idempotent(): void
    {
        $this->fakeDeterministicSourcePages();

        Artisan::call('etl:run');
        Artisan::call('etl:run', ['--force' => true]);
        Artisan::call('etl:run', ['--force' => true]);

        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $this->checkpoint()->status);
    }

    public function test_etl_run_max_pages_stops_and_resumes(): void
    {
        $this->fakeDeterministicSourcePages();

        $exitCode = Artisan::call('etl:run', ['--max-pages' => 2]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(PipelineCheckpoint::STATUS_RUNNING, $this->checkpoint()->status);
        $this->assertSame('5', $this->checkpoint()->next_cursor);
        $this->assertStringContainsString('Stopped after 2 page(s)', Artisan::output());

        $exitCode = Artisan::call('etl:run');

        $this->assertSame(0, $exitCode);
        $this->assertSame(self::EXPECTED_VALID_RECORD_COUNT, DestinationRecord::count());
        $this->assertSame(self::EXPECTED_MALFORMED_RECORD_COUNT, IngestionError::count());
        $this->assertSame(PipelineCheckpoint::STATUS_COMPLETED, $this->checkpoint()->status);
    }

    public function test_etl_run_rejects_invalid_max_pages(): void
    {
        $this->fakeDeterministicSourcePages();

        $this->assertSame(1, Artisan::call('etl:run', ['--max-pages' => '0']));
        $this->assertStringContainsString('positive integer', Artisan::output());
        $this->assertDatabaseMissing('pipeline_checkpoints', [
            'pipeline_name' => IngestionPipeline::PIPELINE_NAME,
        ]);

        $this->assertSame(1, Artisan::call('etl:run', ['--max-pages' => 'abc']));
        $this->assertStringContainsString('positive integer', Artisan::output());
    }

    public function test_etl_run_returns_failure_on_fetch_error(): void
    {
        config(['ingestion.source_api_url' => 'http://source.test/records', 'ingestion.max_attempts' => 1]);

        Http::fake([
            'source.test/*' => Http::response(['error' => 'server error'], 500),
        ]);

        $exitCode = Artisan::call('etl:run');

        $this->assertSame(1, $exitCode);
        $this->assertSame(PipelineCheckpoint::STATUS_FAILED, $this->checkpoint()->status);
        $this->assertNotNull($this->checkpoint()->last_error);
    }

    public function test_ingestion_run_shares_same_behavior(): void
    {
        $this->fakeDeterministicSourcePages();

        $exitCode = Artisan::call('ingestion:run', ['--max-pages' => 1]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(PipelineCheckpoint::STATUS_RUNNING, $this->checkpoint()->status);
        $this->assertSame('3', $this->checkpoint()->next_cursor);
        $this->assertStringContainsString('Starting pipeline from cursor 0', Artisan::output());
    }

    private function checkpoint(): PipelineCheckpoint
    {
        return PipelineCheckpoint::where('pipeline_name', IngestionPipeline::PIPELINE_NAME)->firstOrFail();
    }
}
