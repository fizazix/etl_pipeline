<?php

namespace App\Services\Ingestion;

use App\Models\PipelineCheckpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class IngestionPipeline
{
    public const PIPELINE_NAME = 'customer-import';

    public function __construct(
        private SourceApiClient $sourceApiClient,
        private RecordValidator $recordValidator,
        private DestinationWriter $destinationWriter,
        private IngestionErrorWriter $errorWriter,
    ) {}

    public function run(?int $maxPages = null, bool $force = false, ?callable $onPageProcessed = null): void
    {
        $checkpoint = $this->resolveCheckpoint();

        if ($checkpoint->status === PipelineCheckpoint::STATUS_COMPLETED && ! $force) {
            return;
        }

        if ($force) {
            $checkpoint->update([
                'status' => PipelineCheckpoint::STATUS_RUNNING,
                'next_cursor' => null,
                'started_at' => now(),
                'completed_at' => null,
                'last_error' => null,
                'last_successful_page_at' => null,
            ]);
        } else {
            $checkpoint->update([
                'status' => PipelineCheckpoint::STATUS_RUNNING,
                'started_at' => $checkpoint->started_at ?? now(),
                'last_error' => null,
            ]);
        }

        $cursor = $force ? '0' : ($checkpoint->next_cursor ?? '0');
        $pagesProcessed = 0;

        while (true) {
            try {
                $page = $this->sourceApiClient->fetchPage($cursor, (int) config('ingestion.page_size'));
            } catch (Throwable $exception) {
                Log::error('Ingestion pipeline failed to fetch source page.', [
                    'pipeline' => self::PIPELINE_NAME,
                    'cursor' => $cursor,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                $this->markCheckpointFailed($checkpoint, $exception);

                throw $exception;
            }

            $pageCursor = $cursor;

            try {
                DB::transaction(function () use ($page, $pageCursor, $checkpoint) {
                    foreach ($page['data'] as $record) {
                        $this->processRecord($record, $pageCursor);
                    }

                    $this->advanceCheckpoint($checkpoint, $page);
                });
            } catch (Throwable $exception) {
                // Failure updates run outside the rolled-back page transaction so the
                // checkpoint still reflects the last committed page cursor.
                $this->markCheckpointFailed($checkpoint, $exception);

                throw $exception;
            }

            $pagesProcessed++;

            $onPageProcessed?->__invoke($pageCursor, $page, $pagesProcessed);

            if ($maxPages !== null && $pagesProcessed >= $maxPages) {
                break;
            }

            if (! $page['has_more']) {
                break;
            }

            $cursor = $page['next_cursor'];
        }
    }

    private function resolveCheckpoint(): PipelineCheckpoint
    {
        return PipelineCheckpoint::firstOrCreate(
            ['pipeline_name' => self::PIPELINE_NAME],
            [
                'next_cursor' => null,
                'status' => PipelineCheckpoint::STATUS_PENDING,
            ]
        );
    }

    private function advanceCheckpoint(PipelineCheckpoint $checkpoint, array $page): void
    {
        $now = now();

        if (! $page['has_more']) {
            $checkpoint->update([
                'next_cursor' => null,
                'status' => PipelineCheckpoint::STATUS_COMPLETED,
                'completed_at' => $now,
                'last_successful_page_at' => $now,
                'last_error' => null,
            ]);

            return;
        }

        $checkpoint->update([
            'next_cursor' => $page['next_cursor'],
            'status' => PipelineCheckpoint::STATUS_RUNNING,
            'last_successful_page_at' => $now,
            'last_error' => null,
        ]);
    }

    private function markCheckpointFailed(PipelineCheckpoint $checkpoint, Throwable $exception): void
    {
        $checkpoint->update([
            'status' => PipelineCheckpoint::STATUS_FAILED,
            'last_error' => $exception->getMessage(),
        ]);
    }

    private function processRecord(mixed $record, string $pageCursor): void
    {
        $result = $this->recordValidator->validate($record);

        if (! $result['valid']) {
            $this->errorWriter->upsertValidationError($result, $pageCursor);

            return;
        }

        $this->destinationWriter->upsert($result['normalized']);
    }
}
