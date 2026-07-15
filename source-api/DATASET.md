# Source API Dataset

Deterministic delivery order for the simulated HTTP source. Array indices are zero-based.

## Expected assertion counts

After a full ETL run with version-aware upserts and malformed-record rejection:

| Metric | Expected value |
|--------|----------------|
| Total delivered items | **312** |
| Unique valid logical IDs in destination | **297** (`customer-001` through `customer-297`, one winning version each) |
| Malformed deliveries / expected rejections | **10** |

## Pagination map (`limit=50`)

| Cursor | Array indices | Items | Notes |
|--------|---------------|-------|-------|
| `0` | 0–49 | 50 | Special scenarios + `customer-005`…`customer-041` |
| `50` | 50–99 | 50 | `customer-042`…`customer-091` |
| `100` | 100–149 | 50 | `customer-092`…`customer-145`; **first request returns HTTP 500** |
| `150` | 150–199 | 50 | Malformed block + `customer-146`…`customer-185` |
| `200` | 200–249 | 50 | `customer-186`…`customer-235`; **first request returns HTTP 429** |
| `250` | 250–299 | 50 | `customer-236`…`customer-285` |
| `300` | 300–311 | 12 | `customer-286`…`customer-297`; final page (`has_more=false`) |

## Special scenarios

| Indices | Scenario | Expected destination outcome |
|---------|----------|------------------------------|
| 0–1 | `customer-001` v1 delivered twice | Duplicate ignored; v1 retained until v2 |
| 2 | `customer-001` v2 (`email`, `status` changed) | Version 2 wins |
| 3–4 | `customer-002` v3 then v2 (out of order) | Version 3 retained |
| 5–6 | `customer-003` v2 earlier then v2 later timestamp | Later `updated_at` wins |
| 7–8 | `customer-004` v2 later then v2 earlier timestamp | Later `updated_at` retained; earlier does not overwrite |
| 9–149 | `customer-005`…`customer-145` | Normal single-version records |
| 160–311 | `customer-146`…`customer-297` | Normal single-version records |

## Malformed records (indices 150–159)

All malformed items are still returned inside `data` so the ETL can isolate them.

| Index | Issue |
|-------|-------|
| 150 | missing `id` |
| 151 | `email` as integer |
| 152 | invalid email string (`not-an-email`) |
| 153 | missing `name` |
| 154 | `status` is `null` |
| 155 | unsupported status (`archived`) |
| 156 | `version` is non-numeric string (`two`) |
| 157 | `version` less than 1 (`0`) |
| 158 | invalid `updated_at` (`not-a-date`) |
| 159 | non-associative payload (`"not-a-record"`) |

## Transient failures

Attempt counters are stored under `sys_get_temp_dir()/source-api-state/` and survive across requests while the container is running.

| Cursor | First request | Retry |
|--------|---------------|-------|
| `100` | HTTP **500** | HTTP **200** with normal page data |
| `200` | HTTP **429** with `Retry-After: 1` | HTTP **200** with normal page data |

## Rate limiting

`GET /records` allows a maximum of **5 requests per UTC second**. Additional requests in the same second return HTTP **429** with `Retry-After: 1`.

## Record shape (valid records)

```json
{
  "id": "customer-005",
  "name": "Customer 5",
  "email": "customer-005@example.com",
  "status": "active",
  "version": 1,
  "updated_at": "2024-01-06T10:00:00Z"
}
```

Allowed statuses: `active`, `inactive`, `pending`.
