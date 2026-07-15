<?php

namespace App\Console\Commands\Concerns;

use App\Models\DestinationRecord;
use App\Models\IngestionError;
use App\Models\PipelineCheckpoint;
use App\Services\Ingestion\IngestionPipeline;
use Throwable;

trait InteractsWithIngestionPipeline
{
    protected function runIngestionPipeline(IngestionPipeline $pipeline): int
    {
        $force = (bool) $this->option('force');
        $maxPages = $this->resolveMaxPagesOption();

        if ($maxPages === false) {
            return self::FAILURE;
        }

        $pipelineName = config('ingestion.pipeline_name');

        $checkpoint = PipelineCheckpoint::query()
            ->where('pipeline_name', $pipelineName)
            ->first();

        if ($checkpoint?->status === PipelineCheckpoint::STATUS_COMPLETED && ! $force) {
            $this->info('Pipeline already completed.');

            return $this->renderPipelineSummary();
        }

        $startingCursor = $force ? '0' : ($checkpoint?->next_cursor ?? '0');
        $this->info('Starting pipeline from cursor '.$startingCursor);

        $stoppedEarly = false;

        try {
            $pipeline->run(
                maxPages: $maxPages,
                force: $force,
                onPageProcessed: function (string $pageCursor, array $page, int $pagesProcessed) use (&$stoppedEarly, $maxPages): void {
                    if (! $page['has_more']) {
                        $this->line('Page completed at cursor '.$pageCursor.' (final page)');

                        return;
                    }

                    $this->line('Page completed at cursor '.$pageCursor.' -> next cursor '.$page['next_cursor']);

                    if ($maxPages !== null && $pagesProcessed >= $maxPages) {
                        $stoppedEarly = true;
                    }
                },
            );
        } catch (Throwable $exception) {
            $this->error('Pipeline failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($stoppedEarly) {
            $this->info('Stopped after '.$maxPages.' page(s); checkpoint preserved for resume.');
        }

        return $this->renderPipelineSummary();
    }

    private function resolveMaxPagesOption(): int|false|null
    {
        $maxPages = $this->option('max-pages');

        if ($maxPages === null || $maxPages === false || $maxPages === '') {
            return null;
        }

        if ($maxPages === true || ! is_scalar($maxPages)) {
            $this->error('The --max-pages option must be a positive integer.');

            return false;
        }

        $maxPagesString = is_int($maxPages) ? (string) $maxPages : trim((string) $maxPages);

        if ($maxPagesString === '' || ! ctype_digit($maxPagesString)) {
            $this->error('The --max-pages option must be a positive integer.');

            return false;
        }

        $parsed = (int) $maxPagesString;

        if ($parsed < 1) {
            $this->error('The --max-pages option must be a positive integer.');

            return false;
        }

        return $parsed;
    }

    private function renderPipelineSummary(): int
    {
        $checkpoint = PipelineCheckpoint::query()
            ->where('pipeline_name', config('ingestion.pipeline_name'))
            ->first();

        $status = $checkpoint?->status ?? PipelineCheckpoint::STATUS_PENDING;

        $this->newLine();
        $this->line('Pipeline status: '.$status);
        $this->line('Destination records: '.DestinationRecord::count());
        $this->line('Ingestion errors: '.IngestionError::count());

        return self::SUCCESS;
    }
}
