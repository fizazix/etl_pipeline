# Design Decisions

## Transactional checkpointing

Each source page is processed inside one MySQL transaction. Valid destination writes, rejection writes, and the checkpoint update commit together. If the process crashes before commit, MySQL rolls back the page and the checkpoint stays at the previous cursor. Reprocessing the same page is safe because destination upserts and error inserts are idempotent.

## MySQL upsert for version control

Destination writes use a single `INSERT ... ON DUPLICATE KEY UPDATE` statement with an `AS new` row alias. A user variable captures the accept/reject decision once from the existing row values, then all columns update consistently. Without that, later assignments in the same statement would see already-updated column values and could skip payload updates when only the version column changed first.

## No queues or extra infrastructure

The pipeline runs as one Artisan command with a simple page loop. MySQL stores checkpoints and results. Redis, Horizon, Kafka, and RabbitMQ are not needed for this scope.

## Deterministic simulated source failures

The source API returns HTTP 500 and 429 responses at fixed cursors (`fail-500`, `fail-429`). Retry behavior is reproducible across runs and evaluations. Transient failures use attempt counters stored in the source container temp directory.

## Idempotent rejection storage

Malformed records are stored in `ingestion_errors` with a unique key on `(external_id, source_cursor, error_code)`. Reprocessing the same page does not create duplicate rejection rows. Records without an `external_id` use an empty string in that column so the unique constraint remains effective.

## Automated Docker startup

The app entrypoint runs migrations and the ingestion command before starting the HTTP server. The pipeline exits when complete, so first boot finishes ingestion without manual steps. Re-running `ingestion:run` is safe because completed pipelines short-circuit and partial runs resume from the checkpoint.

## Laravel version

The project uses the current Laravel application skeleton (Laravel 13 at scaffold time) on PHP 8.3. The ingestion code follows standard Laravel conventions and does not depend on version-specific features beyond the framework baseline.
