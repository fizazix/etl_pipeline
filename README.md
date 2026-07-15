# Resumable ETL Data Ingestion Pipeline

A Laravel ETL worker that consumes cursor-paginated JSON from a simulated HTTP source API, validates each record, and loads accepted rows into MySQL. Malformed records are isolated in a separate error table. The pipeline supports at-least-once delivery with idempotent destination writes and resumable checkpoints. HTTP endpoints expose pipeline status, destination records, and ingestion errors.

## Architecture

Four components work together:

- **Simulated source API** (`source-api`) — deterministic cursor-paginated HTTP dataset with retries and rate limits
- **Laravel ETL worker** (`etl`) — fetches pages, validates records, upserts into MySQL, updates checkpoints
- **MySQL destination** (`mysql`) — stores destination records, ingestion errors, and pipeline checkpoints
- **Laravel inspection API** (`app`) — read-only HTTP endpoints on port 8080

```text
source-api  --HTTP-->  etl (Laravel worker)  -->  MySQL (etl schema)
                              |
                              v
                         app (Laravel API :8080)
```

Docker Compose services: `mysql`, `migrate` (one-shot migrations), `source-api`, `app`, `etl`.

## Requirements

- Docker
- Docker Compose

## Start the project

```bash
docker compose up --build
```

This automatically:

1. Starts MySQL and waits until it is healthy
2. Runs database migrations via the `migrate` service
3. Starts the simulated source API
4. Starts the Laravel inspection API at http://localhost:8080
5. Runs the ETL worker (`php artisan etl:run`)

The ETL worker may finish before you run the curl examples below. Query `/api/status` to confirm completion (`pipeline.status` is `completed`).

## Query status

```bash
curl http://localhost:8080/api/status
```

Useful fields: `records_loaded`, `isolated_errors`, `pipeline.status`, `pipeline.next_cursor`.

## Query records

```bash
curl http://localhost:8080/api/records
```

Filter by source ID:

```bash
curl "http://localhost:8080/api/records?source_id=customer-002"
```

## Query errors

```bash
curl http://localhost:8080/api/errors
```

## Demonstrate idempotency

After a completed run, force a full re-ingestion:

```bash
docker compose run --rm etl php artisan etl:run --force
```

The pipeline reprocesses from cursor `0`, but destination upserts and error fingerprinting are idempotent. `records_loaded` and `isolated_errors` should remain stable.

## Demonstrate resume behavior

Use `--max-pages` to stop after a fixed number of committed pages:

```bash
docker compose run --rm etl php artisan etl:run --force --max-pages=2
curl http://localhost:8080/api/status
docker compose run --rm etl php artisan etl:run
```

The first command stops after two pages. The checkpoint keeps a saved `next_cursor` (typically `100` against the bundled source with page size 50) and `pipeline.status` is `running`. The second command resumes from that cursor without reprocessing already-committed pages. After it finishes, counts match a full run.

## Run tests

```bash
docker compose run --rm test
```

Run a subset:

```bash
docker compose run --rm test php artisan test --filter=IngestionPipeline
```

Local runs with SQLite skip MySQL-dependent tests. Use the Docker command above for the full suite.

## Reset the project

```bash
docker compose down -v
docker compose up --build
```

The `-v` flag removes the `mysql_data` volume and deletes all MySQL data.

## Important implementation details

- **Version-aware upsert** — MySQL `INSERT ... ON DUPLICATE KEY UPDATE` with a shared `@accept` flag. A row wins when incoming `version` is higher, or when versions tie and `source_updated_at` is later.
- **Page-level transaction** — each source page is processed inside one `DB::transaction`.
- **Checkpoint in same transaction** — destination writes, error writes, and checkpoint advancement commit together per page.
- **Malformed record isolation** — invalid payloads are persisted to `ingestion_errors` with fingerprint-based deduplication.
- **Exponential retry** — transient HTTP failures retry with `2^(attempt-1)` second delays.
- **429 Retry-After** — rate-limit responses honor the `Retry-After` header when present.
- **Sequential request pacing** — enforces a minimum interval between requests (default 4 req/s via `SOURCE_API_REQUESTS_PER_SECOND`).

See [DECISIONS.md](DECISIONS.md) for design rationale.

## Project structure

```text
app/
  Console/Commands/       etl:run command
  Http/Controllers/       inspection API
  Models/                 destination, errors, checkpoint
  Services/Ingestion/     pipeline, client, writers, validator
database/migrations/
source-api/               simulated HTTP source + DATASET.md
tests/                    unit + feature suite
docker/                   test runner scripts
docker-compose.yml
```

## Expected result

**Automated test fixture** (verified by `test_end_to_end_pipeline_produces_expected_outcomes`):

- 6 unique destination records
- 4 unique isolated ingestion errors

**Full docker stack** (after `docker compose up` against the bundled source-api; see [source-api/DATASET.md](source-api/DATASET.md)):

- 297 unique destination records
- 10 unique isolated ingestion errors
