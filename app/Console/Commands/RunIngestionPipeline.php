<?php

namespace App\Console\Commands;

use App\Services\Ingestion\IngestionPipeline;
use Illuminate\Console\Command;

class RunIngestionPipeline extends Command
{
    protected $signature = 'ingestion:run';

    protected $description = 'Run the resumable ETL ingestion pipeline';

    public function handle(IngestionPipeline $pipeline): int
    {
        $this->info('Starting ingestion pipeline...');

        try {
            $pipeline->run();
        } catch (\Throwable $exception) {
            $this->error('Ingestion pipeline failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Ingestion pipeline completed.');

        return self::SUCCESS;
    }
}
