<?php

namespace App\Services\Ingestion;

use App\Models\PipelineCheckpoint;
use Illuminate\Support\Facades\DB;
use Throwable;

class IngestionPipeline
{
    public function __construct(
        private SourceApiClient $sourceApiClient,
        private RecordValidator $recordValidator,
        private DestinationWriter $destinationWriter,
        private IngestionErrorWriter $errorWriter,
    ) {}

    public function run(): void
    {
        $pipelineName = config('ingestion.pipeline_name');
        $checkpoint = $this->resolveCheckpoint($pipelineName);

        if ($checkpoint->status === PipelineCheckpoint::STATUS_COMPLETED) {
            return;
        }

        $checkpoint->update([
            'status' => PipelineCheckpoint::STATUS_RUNNING,
            'started_at' => $checkpoint->started_at ?? now(),
            'last_error' => null,
        ]);

        $cursor = $checkpoint->cursor;

        try {
            while (true) {
                $page = $this->sourceApiClient->fetchPage($cursor, (int) config('ingestion.page_size'));
                $pageCursor = $cursor ?? 'start';

                DB::transaction(function () use ($page, $pageCursor, &$cursor, $checkpoint, $pipelineName) {
                    foreach ($page['data'] as $record) {
                        $this->processRecord($record, $pageCursor);
                    }

                    $nextCursor = $page['next_cursor'];
                    $cursor = $nextCursor;

                    if ($nextCursor === null) {
                        $checkpoint->update([
                            'cursor' => null,
                            'status' => PipelineCheckpoint::STATUS_COMPLETED,
                            'completed_at' => now(),
                            'last_error' => null,
                        ]);
                    } else {
                        $checkpoint->update([
                            'cursor' => $nextCursor,
                            'status' => PipelineCheckpoint::STATUS_RUNNING,
                            'last_error' => null,
                        ]);
                    }
                });

                if ($page['next_cursor'] === null) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            $checkpoint->update([
                'status' => PipelineCheckpoint::STATUS_FAILED,
                'last_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function resolveCheckpoint(string $pipelineName): PipelineCheckpoint
    {
        return PipelineCheckpoint::firstOrCreate(
            ['pipeline_name' => $pipelineName],
            [
                'cursor' => null,
                'status' => PipelineCheckpoint::STATUS_IDLE,
            ]
        );
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
