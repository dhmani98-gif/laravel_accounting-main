# Tax Forms API

The tax form endpoints expose calculated return fields together with their accounting provenance, allow controlled pre-export overrides, and provide an export-readiness preview. They do not submit a tax filing to the IRS, ELSTER, or any other tax authority.

## Authentication and permissions

Requests use the existing session authentication and company context middleware:

- `web`
- `auth`
- `password.change`
- `company`

Read endpoints require `view_reports`. Creating, changing, and recalculating a return require `manage_accounts`. State-changing browser requests must include the normal Laravel CSRF token.

## Endpoints

| Method | Endpoint | Permission | Purpose |
|---|---|---|---|
| `GET` | `/api/tax/forms` | `view_reports` | List active form definitions; supports `jurisdiction` and `tax_year` filters. |
| `POST` | `/api/tax/forms/{taxFormDefinitionId}/runs` | `manage_accounts` | Create an original return run and persist calculated values. |
| `GET` | `/api/tax/runs/{taxReturnRunId}` | `view_reports` | Show the run, fields, provenance, calculation traces, validations, and edit/export flags. |
| `PATCH` | `/api/tax/runs/{taxReturnRunId}/fields/{taxFormFieldId}` | `manage_accounts` | Override a calculated field before approval/submission. |
| `POST` | `/api/tax/runs/{taxReturnRunId}/recalculate` | `manage_accounts` | Recalculate the run from accounting data. |
| `GET` | `/api/tax/runs/{taxReturnRunId}/export-preview` | `view_reports` | Confirm export readiness without submitting a filing. |

Decimal values are returned as strings using the field scale to avoid JSON floating-point precision loss. Each field includes `provenance`, which identifies the accounting period and source data used by the mapping service, and `calculation_trace` when available.

## Create a return run

```http
POST /api/tax/forms/12/runs
Accept: application/json
Content-Type: application/json
X-CSRF-TOKEN: <session csrf token>

{
  "period_start": "2026-01-01",
  "period_end": "2026-03-31",
  "tax_profile_id": 4
}
```

The response is `201 Created` and contains `data.run`, `data.fields`, `data.validations`, `data.can_edit`, and `data.can_export`. An `Idempotency-Key` header may be supplied; otherwise a deterministic key is generated for the company, form, and period.

## Manual override

```http
PATCH /api/tax/runs/27/fields/91
Accept: application/json
Content-Type: application/json
X-CSRF-TOKEN: <session csrf token>

{
  "reported_value": "125.50",
  "override_reason": "Approved adjustment from tax reviewer."
}
```

The reason is required and must contain at least 10 characters. A successful response returns the updated run with `is_overridden: true`, the reason, and the updated `reported_value`. Every override creates a `tax_audit_events` record containing the before/after snapshots, user, reason, and payload hash.

Overrides are rejected with `409 Conflict` once the run is approved or submitted. A missing field or value returns `404`; invalid request data returns `422`.

## Export preview

```http
GET /api/tax/runs/27/export-preview
Accept: application/json
```

A successful response contains:

```json
{
  "data": {
    "run_id": 27,
    "status": "ready_for_review",
    "exportable": true,
    "message": "The reviewed values are ready for an export adapter. No tax filing was submitted."
  }
}
```

Open blocking validations return `422`, and a run that is not in an exportable state returns `409`. This endpoint is intentionally a preview only; a future country-specific export adapter and separate submission workflow must be added before any actual tax filing is transmitted.
