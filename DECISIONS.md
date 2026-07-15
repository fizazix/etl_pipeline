# Design Decisions

See [README.md](README.md) for setup and operation. This document explains why the pipeline is built the way it is.

## Stack choice

**Laravel** provides the pieces needed for a small but complete ingestion service: an HTTP client for the source API, validation for incoming records, database migrations, Artisan commands for the worker, transaction support, a PHPUnit test suite, and HTTP endpoints for inspection. Keeping the worker and API in one application avoids coordinating separate deployables for this scope.

**MySQL** is a common production datastore and fits the access patterns here well. It offers unique constraints for idempotent keys, ACID transactions for page-level commits, JSON columns for raw payloads and error details, and atomic `INSERT ... ON DUPLICATE KEY UPDATE` for version-aware upserts without a separate read-modify-write round trip.

## Processing model

The source API returns cursor-paginated pages (`cursor`, `limit`, `next_cursor`, `has_more`). A single sequential ETL worker (`php artisan etl:run`) walks those pages in order. There is no parallel partitioning; one worker owns the checkpoint.

Each page follows this sequence:

```text
fetch page -> BEGIN -> write records/errors -> advance checkpoint -> COMMIT
```

The HTTP fetch happens **before** the database transaction opens. Network I/O stays outside the transaction so a slow or retried request does not hold row locks. Once the page payload is in memory, one `DB::transaction` processes every record on that page, writes malformed rows to the error table, and advances the checkpoint. If the process crashes before commit, MySQL rolls back the page and the checkpoint remains at the last successfully committed cursor.

## Idempotency

Destination rows are keyed by unique `source_id`. Writes use a conditional MySQL upsert: an incoming record wins only when its `version` is higher than the stored value, or when versions are equal and its `source_updated_at` is later. A shared `@accept` variable in the upsert statement evaluates that decision once, avoiding incorrect partial updates as MySQL applies column assignments left to right.

Replaying a committed page is safe. Duplicate deliveries with the same or older version are ignored. Error rows deduplicate separately (see below). At-least-once delivery from the source therefore converges to the same destination state.

## Malformed records

Records that fail validation never reach the destination table. They are stored in `ingestion_errors` with the raw payload and structured error details.

Each error row has a SHA-256 fingerprint derived from the source cursor, raw payload, and error type. A unique index on `fingerprint` prevents duplicate error rows when the same malformed record is delivered again. `occurrence_count` increments on repeat sightings; `first_seen_at` is preserved. Validation failures are handled per record and do not abort the page or stop the pipeline.

## Retry and rate limiting

The HTTP client retries transient failures (5xx, connection errors) with exponential backoff: `2^(attempt-1)` seconds between attempts. HTTP 429 responses honor a numeric `Retry-After` header when present. Between successful requests, client-side pacing enforces a minimum interval based on `SOURCE_API_REQUESTS_PER_SECOND` (default 4), reducing the chance of hitting rate limits.

The simulated source API injects deterministic failures at fixed cursors (HTTP 500 at cursor `100`, HTTP 429 at cursor `200`) so retry behavior is reproducible in tests and manual runs.

## Simplifications

This project deliberately limits scope:

- **One worker** rather than partitioned parallel workers — simpler checkpoint semantics, no cross-partition coordination.
- **MySQL error table** instead of a dedicated message-broker dead-letter queue — sufficient for inspection without extra infrastructure.
- **Local deterministic source** rather than a real third-party API — controlled dataset, predictable failures, no external dependency.
- **Limited operational metrics** — the status endpoint exposes counts; there is no metrics backend.
- **No authentication** on inspection endpoints — acceptable for local demonstration.
- **No distributed lock** — a single ETL worker is expected; Docker Compose runs one `etl` container.

A production deployment would likely add distributed locking for multi-worker safety, queue partitions for throughput, a centralized rate limiter, metrics and alerting, distributed tracing, and retention policies for raw payloads and error records.
