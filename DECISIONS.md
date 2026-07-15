# Design Decisions

## Transactional checkpointing

Each source page is processed inside one MySQL transaction. Valid destination writes, rejection writes, and the checkpoint update commit together. If the process crashes before commit, MySQL rolls back the page and the checkpoint stays at the previous cursor. Reprocessing the same page is safe because destination upserts and error inserts are idempotent.

## MySQL upsert for version control

Destination writes use a single `INSERT ... ON DUPLICATE KEY UPDATE` statement against `destination_records`, keyed by the unique `source_id` index. Columns written are `name`, `email`, `status`, `version`, `source_updated_at`, `raw_payload`, and `updated_at`.

The statement uses a `VALUES (...) AS new` row alias (MySQL 8.0.19+). A user variable `@accept` captures the accept/reject decision once from the existing row values:

```sql
incoming version > stored version
OR (incoming version = stored version AND incoming source_updated_at > stored source_updated_at)
```

That decision is assigned in the **first** `ON DUPLICATE KEY UPDATE` expression (`name = IF((@accept := <condition>), ...)`). Subsequent column assignments reuse `@accept` rather than re-evaluating the condition against partially-updated columns.

MySQL evaluates duplicate-key updates left to right. Without the shared `@accept` variable, an early assignment to `version` or `source_updated_at` would change the values seen by later expressions and could accept or reject the wrong payload. A `SELECT ... FOR UPDATE` transaction would also work, but the single-statement `@accept` pattern is simpler and stays atomic.

When the incoming row wins, all business fields and `updated_at` are replaced. When it loses, every column keeps its stored value, including `updated_at`.

`DestinationWriter::upsert()` returns a per-row action without querying the table again:

- `inserted` — new `source_id` (`@accept` was never assigned)
- `updated` — duplicate key conflict and incoming row won (`@accept = 1`)
- `ignored` — duplicate key conflict and incoming row lost (`@accept = 0`)

The writer reads `SELECT @accept AS accept, ROW_COUNT() AS affected_rows` on the same connection immediately after the upsert.

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
