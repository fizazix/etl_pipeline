<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithIngestionPipeline;
use App\Services\Ingestion\IngestionPipeline;
use Illuminate\Console\Command;

class RunIngestionPipeline extends Command
{
    use InteractsWithIngestionPipeline;

    protected $signature = 'ingestion:run {--force : Reprocess from cursor 0} {--max-pages= : Stop after N pages}';

    protected $description = 'Run the resumable ETL ingestion pipeline';

    public function handle(IngestionPipeline $pipeline): int
    {
        return $this->runIngestionPipeline($pipeline);
    }
}
