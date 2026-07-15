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
| GET | `/api/pipeline/status` | Pipeline checkpoint status |
| GET | `/api/pipeline/loaded-count` | Count of successfully loaded records |
| GET | `/api/pipeline/rejected-count` | Count of rejected records |
| GET | `/api/pipeline/rejected` | Paginated rejected record details |
| GET | `/api/pipeline/loaded` | Paginated successfully loaded records |

### Examples

```bash
curl http://localhost:8000/api/pipeline/status
curl http://localhost:8000/api/pipeline/loaded-count
curl http://localhost:8000/api/pipeline/rejected-count
curl "http://localhost:8000/api/pipeline/rejected?per_page=10"
curl "http://localhost:8000/api/pipeline/loaded?per_page=10"
```

## Expected results after a full run

After `docker compose up --build` completes, the pipeline should load **6** valid destination records and reject **4** malformed source records.

| External ID | Final version | Notes |
|-------------|---------------|-------|
| rec-001 | 2 | Duplicate in page 1 ignored; v2 accepted; older v1 rejected |
| rec-002 | 1 | |
| rec-003 | 1 | |
| rec-004 | 2 | |
| rec-005 | 1 | |
| rec-006 | 1 | |

Rejected records:

| Error | Source page |
|-------|-------------|
| missing_external_id | page-4 |
| invalid_version | page-4 |
| invalid_updated_at | page-5 |
| invalid_name | page-5 |

## Running tests

Tests that exercise MySQL-specific upsert logic require MySQL:

```bash
docker compose exec app sh -c "DB_CONNECTION=mysql DB_HOST=mysql DB_PORT=3306 DB_DATABASE=ingestion DB_USERNAME=ingestion DB_PASSWORD=secret php artisan test"
```

Unit tests for validation and HTTP retry behavior run without MySQL. Destination writer, pipeline, and API feature tests use MySQL.

## Resuming after interruption

The pipeline is safe to re-run:

```bash
docker compose exec app php artisan ingestion:run
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
