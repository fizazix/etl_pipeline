<?php

namespace App\Console\Commands\Concerns;

use App\Models\DestinationRecord;
use App\Models\IngestionError;
use App\Models\PipelineCheckpoint;
use App\Services\Ingestion\IngestionPipeline;
use Illuminate\Support\Facades\Log;
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

        $checkpoint = PipelineCheckpoint::query()
            ->where('pipeline_name', IngestionPipeline::PIPELINE_NAME)
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
            Log::error('Ingestion pipeline command failed.', [
                'pipeline' => IngestionPipeline::PIPELINE_NAME,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

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

        if ($maxPages === true) {
            $this->error('The --max-pages option must be a positive integer.');

            return false;
        }

        if (is_int($maxPages)) {
            if ($maxPages < 1) {
                $this->error('The --max-pages option must be a positive integer.');

                return false;
            }

            return $maxPages;
        }

        if (! is_scalar($maxPages)) {
            $this->error('The --max-pages option must be a positive integer.');

            return false;
        }

        $maxPagesString = trim((string) $maxPages);

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
            ->where('pipeline_name', IngestionPipeline::PIPELINE_NAME)
            ->first();

        $status = $checkpoint?->status ?? PipelineCheckpoint::STATUS_PENDING;

        $this->newLine();
        $this->line('Pipeline status: '.$status);
        $this->line('Destination records: '.DestinationRecord::count());
        $this->line('Ingestion errors: '.IngestionError::count());

        return self::SUCCESS;
    }
}
