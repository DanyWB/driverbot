# Google Calendar convergence and operator policy

## Database support and concurrency evidence

PostgreSQL is the production-supported engine: `backend/.env.example` selects `pgsql`, and `ReleasePreflightService` rejects another driver in strict release checks. Laravel config also defines SQLite, MySQL, MariaDB, and SQL Server connections; these are configuration options, not evidence that the application release path supports them. SQLite is used by the ordinary in-memory PHPUnit suite and does not prove PostgreSQL row-lock behavior. The Google Calendar process-race suite is PostgreSQL-only.

The process tests require a disposable local PostgreSQL database and use a fail-closed bootstrap before Laravel creates the test application or `RefreshDatabase` can migrate. The regular `phpunit.xml` suite excludes this class; run it only through the dedicated configuration below. Do not use `drive_phangan`, a shared test database, or a production host.

Set all of these variables explicitly in the same shell. The confirmation must exactly match `DB_DATABASE`, including case, and the database must end with the exact lowercase `_test` suffix. Supported loopback hosts are `127.0.0.1`, `localhost`, and `::1`. `DB_URL` must be explicitly empty; a URL can override individual database settings. The guard rejects a cached Laravel config because it can redirect connections independently of these variables. It also resolves the checked-in Laravel database configuration without booting providers, then checks the booted effective config before `RefreshDatabase` starts. `.env` and `.env.testing` are not loaded by the dedicated bootstrap or worker processes.

PowerShell example after provisioning a disposable local test database:

```powershell
$env:APP_ENV='testing'
$env:GOOGLE_CALENDAR_PG_RACE_TESTING='1'
$env:GOOGLE_CALENDAR_PG_RACE_DATABASE='drive_phangan_gcal_test'
$env:DB_CONNECTION='pgsql'
$env:DB_HOST='127.0.0.1'
$env:DB_PORT='5432'
$env:DB_DATABASE='drive_phangan_gcal_test'
$env:DB_USERNAME='local_test_user'
$env:DB_PASSWORD='local_test_password'
$env:DB_URL=''
vendor/bin/phpunit --configuration=phpunit-google-calendar-postgres.xml
```

Supply credentials only for that disposable local test database. Each child PHP process receives the explicit connection values, independently runs the same preflight before booting Laravel, rejects cached configuration, and opens its own PostgreSQL connection. The guard test itself is pure and does not use Laravel's database test traits. The PostgreSQL process suite has not been run in this environment.

CI uses its ephemeral PostgreSQL service database `phangan_test` (created by the workflow service container), confirms the same name in `GOOGLE_CALENDAR_PG_RACE_DATABASE`, explicitly sets `DB_URL` empty, and runs `php tests/Support/google_calendar_postgres_bootstrap.php` as a pure preflight in the same environment before its migration command. It then invokes the dedicated PHPUnit configuration against that service database. The service is isolated to the CI job and is discarded afterward.

## Operator policy

No operator action silently clears `uncertain_since`. A successful readback is point-in-time evidence and cannot prove that an older accepted request is no longer in flight. Current desired state is recomputed from the booking and mapping on each check; cancellation/expiry always selects delete, while completed/no-show retain their event history.

| Situation / action | Preconditions | Recorded action | Follow-up and late request behavior |
| --- | --- | --- | --- |
| Remote state verified correct | Adapter GET/readback matches the current mapping revision, stable ID, calendar, and desired payload (or confirms absence for a tombstone) | Set `last_remote_verified_at`; preserve uncertainty and diagnostics | Keep scheduled convergence while uncertainty remains. A late older request is repaired by a later check. |
| Remote state repaired and verified | Current desired operation completed and a subsequent readback matches current database state | Set `last_remote_verified_at`; preserve uncertainty | Keep scheduled convergence. A write response without readback is not verified. |
| Remote state cannot be verified | API unavailable, access denied, or response ambiguous | Preserve last error and uncertainty; raise/retain manual review | Retry with backoff and continue convergence. Escalate; do not close. |
| Unexpected Google event ID | Processor returns an ID different from the permanent stable ID | Preserve unexpected ID, failure detail, and manual-review state; never mutate/delete that arbitrary ID | Continue work only against the stable mapping ID; investigate the unexpected resource separately. |
| Repeated API failures | Failure threshold is reached | Flag manual review and retain failure counters/error | Continue scheduled attempts; manual review does not silently stop cancellation deletes. |
| Operation may still be in flight | Expired processing lease or uncertain transport outcome | Persist `uncertain_since` on the booking/event-type reconciliation row | Continue delayed checks indefinitely. No finite wait proves the remote request is quiescent. |
| Request immediate reconciliation | Authenticated operator action in a future admin surface; mapping exists | Increment/coalesce the existing reconciliation generation and set `available_at=now`; audit actor, time, reason, booking public ID, and event type | Preserve uncertainty. Tombstones still select delete; any late older request is repaired by later checks. |
| Mark reviewed | Operator has inspected diagnostics and records an audit reason | Record reviewer/time/reason without changing generations, uncertainty, or tombstone | Existing checks continue. |
| Escalate | Verification is unavailable, unexpected ID persists, or failures continue | Record an escalation/audit reference; do not change desired state | Checks continue. No credentials or personal customer data belong in the record. |
| Close uncertainty | Only defensible if old requests are known quiescent; Google provides no API proof for this. A risk-acceptance exception would require a separately approved, audited action and explicit residual-risk acknowledgement. | **No clear/close operation is implemented.** | Do not stop recurring repair based on elapsed time or one successful readback. If a future approved exception stops it, a later remote side effect may remain undetected. |

The current diagnostics command is read-only; there is no admin mutation endpoint or CLI to request/close/review records. Any future operator action requires authorization, audit history, and explicit booking/event scoping. Closing a cancelled tombstone must never make it eligible for upsert.

## Retry and API request volume

Successful uncertain repairs use exponential intervals beginning at `google_calendar.convergence.delay_seconds` (300 seconds by default), capped by `max_delay_seconds` (86,400 seconds). The approximate schedule is 5, 10, 20, 40, 80, 160, 320, and 640 minutes, then at most once per day. Counting the initial repair, this is roughly nine convergence operations per unresolved mapping during the first 24 hours, then one operation per mapping per day while uncertainty persists. One booking with pickup and return uncertainty has two independent mapping schedules.

This is a count of logical convergence operations, not HTTP calls. A future adapter may use a GET plus update/delete or a GET after a write, so actual API calls per operation will be higher and must be measured against quota. Failed attempts use the configured retry backoff (30 seconds base, capped at one hour by default, plus jitter); repeated failures can therefore generate more requests than the successful-check schedule.

Manual review is raised after the configured failure threshold (10 by default), or immediately for an unexpected event ID. It does not pause or terminate convergence. Warning thresholds (`warning_after_checks=100`, `warning_after_days=7`) mark long-running/high-volume rows in diagnostics so operators can review API cost. They do not stop work. Cancelled/expired records continue deleting; there is no quota-driven silent stop.

The command reports age of uncertainty, last successful **remote readback verification** (never inferred from a write response or the local simulator), generations, next due time, waiting/processing/overdue state, successful check and failure counts, last error, unexpected ID, manual-review flag, and warning status. It shows only booking public IDs and event types, with no phone, passport, Telegram, or other customer identity data.

## Remote API limits

Google permits caller-supplied event IDs in a documented character set and length range and supports get/update/delete by event ID. It warns that collision detection is not guaranteed at creation time. Google documents ETag conditional update/delete behavior, but not a fence that prevents an older already-accepted request from taking effect after a later delete. Stable IDs prevent neither late remote ordering nor exactly-once ambiguity. The persistent marker and recurring latest-state checks reduce the chance of a lasting divergence; they do not provide an absolute ordering guarantee. The real API adapter is `GoogleApiCalendarOperationProcessor` (service-account auth), enabled with `GOOGLE_CALENDAR_DRIVER=google`; the default `local` driver only simulates success.
