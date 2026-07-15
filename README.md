# Resumable ETL Ingestion Pipeline

A Laravel-based ETL pipeline that ingests records from a simulated HTTP source API into MySQL with resumable checkpointing, version-aware upserts, and rejection reporting.

## Quick start

```bash
docker compose up --build
```

Docker Compose starts MySQL, the simulated source API, and the Laravel app. On first boot the app automatically:

1. Installs Composer dependencies
2. Generates an application key
3. Runs database migrations
4. Executes the ingestion pipeline (`php artisan ingestion:run`)
5. Starts the HTTP server on port 8000

No manual migration, seeding, key generation, or ETL command is required.

## Architecture

```text
source-api (PHP)  --HTTP-->  Laravel ingestion:run  -->  MySQL
                                    |
                                    +--> HTTP API (status, counts, records)
```

The pipeline processes cursor-paginated source pages inside a single MySQL transaction per page. Destination writes, rejection writes, and checkpoint updates commit together. A crash before commit rolls back the entire page so the pipeline can safely resume.

## HTTP endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/status` | Pipeline checkpoint status and record/error counts |
| GET | `/api/records` | Paginated destination records |
| GET | `/api/records/{sourceId}` | Single destination record by source ID |
| GET | `/api/errors` | Paginated ingestion errors |

### Examples

```bash
curl http://localhost:8080/api/status
curl "http://localhost:8080/api/records?per_page=10&status=active"
curl "http://localhost:8080/api/errors?per_page=10"
curl http://localhost:8080/api/records/customer-001
```

## Expected results after a full run

After `docker compose up --build` completes, the pipeline loads **297** valid destination records and isolates **10** malformed source records (see [`source-api/DATASET.md`](source-api/DATASET.md)).

The deterministic test fixture used in automated tests loads **6** valid records and **4** malformed records.

## Running tests

The test suite uses MySQL for upsert logic, JSON fields, checkpoint transactions, and API feature tests. SQLite-only runs will skip those tests.

Run the full suite in Docker:

```bash
docker compose run --rm test
```

Run a subset:

```bash
docker compose run --rm test php artisan test --filter=IngestionPipeline
```

Unit tests for HTTP retry behavior and record validation run without MySQL when executed locally with SQLite defaults.

## Resuming after interruption

The pipeline is safe to re-run:

```bash
docker compose run --rm etl php artisan etl:run
```

If the process stops before a page transaction commits, the checkpoint remains at the last successful page and the interrupted page is reprocessed on the next run.

## Configuration

Key environment variables (see `.env.example`):

| Variable | Default | Purpose |
|----------|---------|---------|
| `SOURCE_API_URL` | `http://source-api:8080/records` | Simulated source endpoint |
| `INGESTION_PIPELINE_NAME` | `default` | Checkpoint identifier |
| `INGESTION_MAX_RETRIES` | `5` | HTTP retry limit |
| `INGESTION_BACKOFF_BASE_MS` | `100` | Exponential backoff base delay |
| `INGESTION_BACKOFF_MAX_MS` | `2000` | Maximum backoff delay |

## Project layout

```text
app/Services/Ingestion/     Pipeline services
app/Console/Commands/       ingestion:run command
app/Http/Controllers/       Status and listing endpoints
source-api/                 Deterministic simulated HTTP source
docker/entrypoint.sh        Automated startup script
```

See [DECISIONS.md](DECISIONS.md) for design rationale.

MySQL is exposed on host port **3307** (not 3306) to avoid conflicts with local MySQL installations.
