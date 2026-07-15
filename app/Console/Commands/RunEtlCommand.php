<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RunEtlCommand extends Command
{
    protected $signature = 'etl:run';

    protected $description = 'Run the ETL pipeline';

    public function handle(): int
    {
        $this->info('ETL pipeline is not yet implemented.');
        $this->line('Source API URL: '.config('etl.source_api_url'));

        return self::SUCCESS;
    }
}
