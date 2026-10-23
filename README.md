# PolicyFlow

**Fault-Tolerant Insurance Issuance & Reconciliation Engine**

A robust Laravel-based system that processes travel insurance policy requests safely, handling duplicate submissions, provider timeouts, and idempotent callbacks.

> **Assessment Submission:**
> - **Candidate:** Ritik Kumar ([@RitikKumar1225](https://github.com/RitikKumar1225))
> - **Submitted For Review To:** [@MasterAdmin2](https://github.com/MasterAdmin2)
> - **Repository:** [https://github.com/RitikKumar1225/policyflow](https://github.com/RitikKumar1225/policyflow)
> - **Status:** Complete (46 automated tests passing, all 4 reliability scenarios implemented)

---

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [Local Setup](#local-setup)
- [Database Setup](#database-setup)
- [Running Tests](#running-tests)
- [API Documentation](#api-documentation)
- [Mock Insurer Scenarios](#mock-insurer-scenarios)
- [Reliability & Duplicate Prevention](#reliability--duplicate-prevention)
- [Premium Calculation](#premium-calculation)
- [Known Limitations](#known-limitations)
- [Assumptions](#assumptions)

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                         API Layer                                │
│  PolicyRequestController          MockInsurerController          │
│  POST /api/policy-requests        POST /api/mock-insurer/callback│
│  GET  /api/policy-requests/{id}   GET  /api/mock-insurer/status  │
│  POST /api/policy-requests/{id}/submit                           │
│  GET  /api/policy-requests                                       │
└──────────────┬───────────────────────────┬──────────────────────┘
               │                           │
               ▼                           ▼
┌──────────────────────────┐  ┌──────────────────────────┐
│     IssuanceService      │  │   PremiumCalculation     │
│  - Submit to insurer     │  │   Service                │
│  - Handle responses      │  │  - bcmath arithmetic     │
│  - Process callbacks     │  │  - Deterministic pricing │
│  - Reconcile timeouts    │  └──────────────────────────┘
└──────────┬───────────────┘
           │
           ▼
┌──────────────────────────┐  ┌──────────────────────────┐
│   PolicyStateMachine     │  │   MockInsurerService     │
│  - Validates transitions │  │  - Simulates provider    │
│  - Records audit trail   │  │  - Configurable scenarios│
│  - Prevents regressions  │  │  - Reconciliation lookup │
└──────────────────────────┘  └──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────────────────────────┐
│                        Database Layer                            │
│  policy_requests  │  issuance_attempts  │  provider_events      │
│  (unique: agent_id + client_request_id) │  (unique: event_id)   │
│                   │  policy_state_history                        │
└─────────────────────────────────────────────────────────────────┘
```

### Key Design Decisions

1. **State Machine Pattern**: All status transitions go through `PolicyStateMachine` which validates allowed transitions and records an audit trail. ISSUED is a terminal state — no regressions allowed.

2. **Database-Level Uniqueness**: Duplicate prevention uses both application-level checks AND database unique constraints (`agent_id + client_request_id`). The unique constraint catches race conditions that application-level checks miss.

3. **Decimal-Safe Arithmetic**: Premium calculation uses PHP's `bcmath` extension to avoid floating-point errors, as required by the specification.

4. **Idempotent Callbacks**: Provider events are deduplicated by `event_id` with a database unique constraint. Processing uses `firstOrCreate` inside a transaction with row-level locking.

---

## Local Setup

### Prerequisites

- PHP 8.2+ with extensions: `bcmath`, `sqlite3` (or `mysql`/`pgsql`)
- Composer 2.x
- SQLite 3 (default) or MySQL 8+ / PostgreSQL

### Installation

```bash
# 1. Clone the repository
git clone https://github.com/your-username/policyflow-<candidate-id>.git
cd policyflow-<candidate-id>

# 2. Install dependencies
composer install

# 3. Configure environment
cp .env.example .env
php artisan key:generate

# 4. Create SQLite database (default configuration)
touch database/database.sqlite

# 5. Run migrations
php artisan migrate

# 6. Start the development server
php artisan serve
```

The API will be available at `http://localhost:8000/api`.

### Using MySQL Instead

Update `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=policyflow
DB_USERNAME=root
DB_PASSWORD=
```

Then run:
```bash
mysql -u root -e "CREATE DATABASE policyflow;"
php artisan migrate
```

---

## Database Setup

### Migrations

All tables are created via Laravel migrations:

| Table | Purpose |
|-------|---------|
| `policy_requests` | Core policy request data, premium, status, policy number |
| `issuance_attempts` | Each submission attempt to the insurer with response |
| `provider_events` | Callback events received from the insurer (deduplicated) |
| `policy_state_history` | Full audit trail of every state transition |

### Key Constraints

- `policy_requests`: UNIQUE on `(agent_id, client_request_id)` — prevents duplicate logical requests
- `policy_requests`: UNIQUE on `uuid` — ensures unique identifier per request
- `policy_requests`: UNIQUE on `policy_number` — ensures no duplicate policy numbers
- `provider_events`: UNIQUE on `event_id` — prevents processing duplicate callbacks

---

## Running Tests

```bash
# Run all tests
php artisan test

# Run with verbose output
php artisan test --verbose

# Run specific test class
php artisan test --filter=PolicyRequestTest
php artisan test --filter=PremiumCalculationServiceTest

# Run a specific test method
php artisan test --filter=test_spec_example_plus_international_300k
```

### Test Coverage (46 Tests, 145 Assertions)

| Area | Tests | What's Verified |
|------|-------|-----------------|
| **Intake Validation** | 6 | Required fields, date logic (trip_end >= trip_start), plan/destination/coverage validation |
| **Premium Calculation** | 14 | All multiplier combinations, age boundaries (0-17, 18-60, 61-70, 71+), decimal-safe bcmath, spec example (₹225.00) |
| **Issuance Workflow** | 5 | SUCCESS, TEMPORARY_FAILURE, TIMEOUT, state transitions, audit trail |
| **Case 1: Duplicate Intake** | 3 | Application check + DB composite unique constraint `(agent_id, client_request_id)` |
| **Case 2: Timeout After Success** | 2 | Insurer lookup before reissue, `policy:reconcile` artisan command resolves timeouts |
| **Case 3: Duplicate Callback** | 3 | Database unique `event_id`, idempotent callback handling, ISSUED terminal protection |
| **Case 4: Retry After Failure** | 2 | Safe retry transitions `FAILED -> SUBMITTED`, preserves original immutable premium |
| **Listing/Filtering** | 2 | Pagination, status and agent filter |

---

## API Documentation

### POST `/api/policy-requests`

Create or safely return an existing policy request.

**Request:**
```json
{
  "client_request_id": "AGT-2026-0001",
  "agent_id": "AGENT-17",
  "customer_name": "Sample Customer",
  "date_of_birth": "1998-04-10",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-10",
  "trip_end": "2026-11-14",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
```

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Policy request created successfully",
  "data": {
    "id": "uuid-here",
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "premium": "225.00",
    "status": "RECEIVED",
    ...
  }
}
```

**Response (200 OK) — duplicate submission:**
```json
{
  "success": true,
  "message": "Policy request already exists",
  "data": { ... }
}
```

**Response (422) — validation error:**
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "trip_end": ["The trip end date must be on or after the trip start date."]
  }
}
```

### GET `/api/policy-requests/{id}`

Retrieve status, premium, references, and full audit trail.

**Response includes:** `audit_trail`, `issuance_attempts`, `provider_events`

### POST `/api/policy-requests/{id}/submit`

Start or safely resume issuance. Pass a `scenario` field for testing:

```json
{
  "scenario": "SUCCESS"
}
```

Scenarios: `SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `DELAYED_CALLBACK`, `DUPLICATE_CALLBACK`

### POST `/api/mock-insurer/callback`

Receive a simulated provider event:

```json
{
  "event_id": "EVT-001",
  "policy_request_uuid": "uuid-here",
  "event_type": "POLICY_ISSUED",
  "provider_reference": "PROV-ABC123",
  "policy_number": "POL-XYZ789"
}
```

### GET `/api/policy-requests`

List requests with optional filters: `?status=ISSUED`, `?agent_id=AGENT-17`, `?per_page=25`

### GET `/api/mock-insurer/status/{uuid}`

Reconciliation endpoint — check if the insurer internally issued a policy.

---

## Mock Insurer Scenarios

The mock insurer is controlled via the `scenario` field on the submit endpoint:

| Scenario | Behavior | Engine Response |
|----------|----------|-----------------|
| `SUCCESS` | Returns policy_number immediately | Marks ISSUED |
| `TEMPORARY_FAILURE` | Returns retriable error | Marks FAILED (retryable) |
| `TIMEOUT_AFTER_ISSUE` | Issues internally but returns timeout | Marks NEEDS_RECONCILIATION |
| `DELAYED_CALLBACK` | Returns PENDING, expects callback later | Marks PENDING_CONFIRMATION |
| `DUPLICATE_CALLBACK` | Same as SUCCESS; test by sending callback twice | Processes only once |

The mock insurer stores issued policies in a JSON file (`storage/app/mock_insurer_store.json`) for reconciliation lookups via `GET /api/mock-insurer/status/{uuid}`.

---

## Reliability & Duplicate Prevention

### Implemented Reliability Cases

#### 1. Duplicate Intake (Case #1)

**Problem:** The same `client_request_id + agent_id` is submitted repeatedly, including near-simultaneous requests.

**Solution — Two layers:**

1. **Application-level check:** Before creating a new record, query for existing `(agent_id, client_request_id)` combination. If found, return HTTP 200 with the existing record.

2. **Database-level unique constraint:** `UNIQUE(agent_id, client_request_id)` on the `policy_requests` table. If two concurrent requests pass the application check simultaneously, one will fail with `UniqueConstraintViolationException`, which is caught and returns the existing record.

**Tests:**
- `test_duplicate_intake_returns_existing_request` — Verifies 201 then 200, same UUID
- `test_duplicate_intake_different_agent_creates_new` — Different agent = new request
- `test_duplicate_intake_enforced_by_database_constraint` — Direct DB insert fails

#### 2. Duplicate Callback (Case #3)

**Problem:** The same provider event is delivered multiple times; processing must be idempotent.

**Solution:**

1. **Unique `event_id` constraint:** `provider_events.event_id` has a UNIQUE database constraint.
2. **`firstOrCreate` pattern:** Inside a DB transaction, use `firstOrCreate` on `event_id`. If the event already exists and is processed, skip immediately.
3. **Row-level locking:** `PolicyRequest::lockForUpdate()->find(id)` before modifying the policy request to prevent concurrent callbacks from both marking ISSUED.
4. **Terminal state guard:** If the policy is already ISSUED, any further callbacks are acknowledged but not applied.

**Tests:**
- `test_duplicate_callback_processed_only_once` — Same event_id processed once
- `test_duplicate_callback_cannot_overwrite_issued_state` — ISSUED state is immutable
- `test_invalid_state_transition_rejected` — State machine prevents regressions

#### 3. Timeout After Success (Case #2)

**Problem:** The insurer may have issued a policy even when the client receives no response (timeout); the engine must not blindly issue again.

**Solution:**
1. **Reconciliation before reissue:** When `submit()` is invoked on a request in `SUBMITTED`, `PENDING_CONFIRMATION`, or `NEEDS_RECONCILIATION` state, the engine queries the mock provider's status endpoint (`checkStatus`) before submitting another request.
2. **Reconciliation Command (Stretch Feature):** An artisan command `php artisan policy:reconcile` automatically scans for pending or timeout requests and resolves them with the provider.
3. **Atomic state update:** `reconcileIfNeeded()` atomically locks the row, sets the provider's policy number, and transitions to `ISSUED`.

**Tests:**
- `test_timeout_scenario_marks_needs_reconciliation` — TIMEOUT response transitions safely to `NEEDS_RECONCILIATION`
- `test_timeout_after_success_reconciles_without_duplicate_issuance` — Reissue check queries provider, marks `ISSUED`, prevents duplicate policy
- `test_reconciliation_command_detects_and_resolves_timeout` — `policy:reconcile` artisan command detects and resolves timeout

#### 4. Retry After Temporary Failure (Case #4)

**Problem:** Retries must not produce duplicate policies or lose the original accepted premium.

**Solution:**
1. **Immutable premium snapshot:** Premium is calculated once at intake, stored in decimal-safe format, and never recalculated on retries.
2. **Controlled retry transition:** The state machine permits `FAILED -> SUBMITTED` for retries, while disallowing invalid regressions (e.g., `ISSUED -> SUBMITTED`).
3. **Attempt tracking:** Each attempt is uniquely tracked with its attempt number, request payload, provider response, and timestamp in `issuance_attempts`.

**Tests:**
- `test_temporary_failure_allows_retry` — Request fails, then retries and issues cleanly
- `test_retry_after_temporary_failure_preserves_original_premium` — Original premium remains identical, attempts recorded properly

---

## Premium Calculation

**Formula:** `premium = base_daily_rate × travel_days × age_multiplier × destination_multiplier × coverage_multiplier`

| Factor | Values |
|--------|--------|
| **Base rate** | BASIC: ₹12, PLUS: ₹20, PREMIUM: ₹30 |
| **Travel days** | `trip_end - trip_start + 1` (inclusive, minimum 1) |
| **Age multiplier** (at trip start) | 0–17: 1.10, 18–60: 1.00, 61–70: 1.50, 71+: 2.00 |
| **Destination** | DOMESTIC: 1.00, INTERNATIONAL: 1.80 |
| **Coverage** | ₹100,000: 1.00, ₹300,000: 1.25, ₹500,000: 1.50 |

**Example:** PLUS, 5 days, age 28, INTERNATIONAL, ₹300,000 → `20 × 5 × 1.00 × 1.80 × 1.25 = ₹225.00`

All arithmetic uses `bcmath` for decimal safety. Final premium rounded to 2 decimal places.

---

## State Machine

```
RECEIVED ──→ VALIDATED ──→ SUBMITTED ──→ ISSUED (terminal)
   │              │            │   │
   └→ FAILED      └→ FAILED   │   └→ PENDING_CONFIRMATION ──→ ISSUED
                               │            │                    │
                               │            └→ FAILED            │
                               │            └→ NEEDS_RECONCILIATION
                               └→ NEEDS_RECONCILIATION ──→ ISSUED
                                        │                    │
                                        └→ FAILED            │
                                        └→ SUBMITTED (retry) │

   FAILED ──→ SUBMITTED (retry allowed)
```

Invalid transitions (e.g., ISSUED → SUBMITTED) throw `InvalidArgumentException`.

---

## Known Limitations

1. **SQLite concurrency**: The default SQLite configuration handles row-level locking differently than MySQL/PostgreSQL. For true concurrent request testing, use MySQL.

2. **Mock insurer store**: Uses a JSON file for simplicity. In production, this would be a separate database or service.

3. **No authentication**: API endpoints are unprotected. In production, add API key or OAuth authentication.

4. **Synchronous processing**: All insurer calls are synchronous. Production would use Laravel queues for async processing with retry/backoff.

5. **No rate limiting**: API endpoints don't have rate limiting configured.

6. **Reconciliation is manual**: The `reconcileIfNeeded()` method is called during submit, not as a scheduled task.

7. **Trip start validation**: The `after_or_equal:today` validation means requests for future dates are enforced — tests use 2026 dates accordingly.

---

## Assumptions

1. **Premium is immutable**: Once calculated at request creation, the premium is a snapshot and cannot change. Re-submission of the same request returns the original premium.

2. **Mock insurer scenario is per-submission**: The `scenario` field on the submit endpoint controls mock behavior. This is a development-only feature not present in production.

3. **Single-process deployment**: The current design handles concurrency through database constraints and transactions. It's designed for single-process testing and small-scale deployment.

4. **Policy number uniqueness**: The mock insurer generates random policy numbers. The database UNIQUE constraint on `policy_number` prevents accidental duplicates.

5. **Callback authentication**: In production, callbacks would be authenticated (e.g., HMAC signature). The mock skips authentication for simplicity.

---

## Project Structure

```
app/
├── Http/Controllers/
│   ├── MockInsurerController.php    # Callback and status endpoints
│   └── PolicyRequestController.php  # Core CRUD + submit
├── Models/
│   ├── IssuanceAttempt.php          # Tracks each insurer submission
│   ├── PolicyRequest.php            # Core policy request entity
│   ├── PolicyStateHistory.php       # Audit trail entries
│   └── ProviderEvent.php            # Deduplicated callback events
└── Services/
    ├── IssuanceService.php          # Orchestrates submission + callbacks
    ├── MockInsurerService.php       # Simulated insurer with scenarios
    ├── PolicyStateMachine.php       # State transition validation
    └── PremiumCalculationService.php # Decimal-safe pricing

database/migrations/
├── 2024_01_01_000001_create_policy_requests_table.php
├── 2024_01_01_000002_create_issuance_attempts_table.php
├── 2024_01_01_000003_create_provider_events_table.php
└── 2024_01_01_000004_create_policy_state_history_table.php

tests/
├── Feature/PolicyRequestTest.php    # Integration tests (22 tests)
└── Unit/PremiumCalculationServiceTest.php # Unit tests (14 tests)

examples/
└── curl_examples.sh                 # Sample API calls
```
