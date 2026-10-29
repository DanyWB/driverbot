# Remaining validation, 29 October 2026

## Final accepted results

- Full final SQLite backend run, performed by the pricing implementer after both read-snapshot fixes: **367 total, 347 passed, 20 skipped, 3716 assertions, exit 0**, 84.512 seconds. The only later PHP edit was the PostgreSQL-only expected result described below; no application code changed after that SQLite run.
- Final isolated PostgreSQL run, performed by this validation task after that edit: **28/28 passed, 428 assertions, 0 skipped/errors/failures, exit 0**, 35.736 seconds. Filter: `Postgres|PricingPresetReadSnapshotTest|VehicleQuoteReadSnapshotTest`.
- PostgreSQL breakdown: existing twelve PostgreSQL tests **12/12, 94 assertions**; new import/bulk races **3/3, 275 assertions**; preset calculator snapshot **4/4, 21 assertions**; vehicle quote snapshot **9/9, 38 assertions**. Some existing race assertions depend on which legitimate operation wins; the earlier twelve-test run had 95 assertions, the final schedule had 94. No outcome expectation was relaxed.
- The final twenty SQLite skips are the original thirteen listed below, plus three new pricing-tools PostgreSQL tests, one preset-read PostgreSQL test, and three vehicle-read PostgreSQL tests. All nineteen PostgreSQL-specific skips were exercised in the final PostgreSQL run. Its other nine cases are shared non-PG-only snapshot regressions. **Only the Linux deployment-shell scenario remains unexecuted due to platform**, alongside the separate real-device/live acceptance checks.

Do not add the SQLite and PostgreSQL counts as distinct tests: some snapshot cases run in both databases. This is a full SQLite regression plus targeted PostgreSQL coverage, not a claim that the entire backend suite ran on PostgreSQL.

## Initial baseline and scope

Checkout: `C:/Users/user/Desktop/phangan/.tmp/release-20261018`, HEAD `2279ebb9810b15231b32d5facdef9b1044257195`, with the previously implemented October 29 changes and existing `PROJECT_STATE.md` edits preserved. No push, deployment, production connection, real credentials, bot startup or global installation was performed.

The full SQLite backend run was repeated: **351 tests, 338 passed, 13 skipped, 3673 assertions, exit 0** (41.196 seconds). Its twelve PostgreSQL skips were then run against a new isolated PostgreSQL 16.1 cluster: **12 tests, 12 passed, 0 skipped, 95 assertions, exit 0** (14.935 seconds). These are complementary runs, not one full PostgreSQL backend run.

After adding new-operation race tests and the separately owned calculator-read fix, an intermediate targeted PostgreSQL run passed **7/7 tests, 296 assertions, 0 skipped/errors/failures, exit 0** (13.824 seconds): import/bulk races **3/3, 275 assertions**; calculator read-snapshot regressions **4/4, 21 assertions**. The original 351-test SQLite counts above predate these additional test cases; the final enlarged results are at the top of this report.

The sole platform skip still not executed is the Linux deployment-shell safety test. Its Windows guard was not disabled. Real iPhone/Safari multitouch and live end-to-end checks remain outside this local validation.

## Exact original thirteen skips

Names come from `.tmp/pg-validation-20261029/sqlite-junit.xml`; reasons were confirmed in the corresponding test setup/methods. JUnit records `<skipped/>` without reason text.

| Test class | Skipped method | Reason in SQLite run | PostgreSQL result |
| --- | --- | --- | --- |
| DeploymentHardeningTest | test_release_shell_scripts_parse_and_safety_scenarios_pass | `PHP_OS_FAMILY === 'Windows'`; deployment shell scenarios run on Linux CI/deployment hosts | Not run; remains platform limitation |
| PostgresAdminAccountConcurrencyTest | test_two_simultaneous_self_deletions_cannot_remove_every_active_administrator | Requires pgsql driver | PASS |
| PostgresAdminTelegramBindingConcurrencyTest | test_one_binding_code_can_be_consumed_by_only_one_simultaneous_request | Requires pgsql driver | PASS |
| PostgresAdminTelegramBindingConcurrencyTest | test_one_telegram_identity_can_be_claimed_by_only_one_administrator_simultaneously | Requires pgsql driver | PASS |
| PostgresAdminTelegramBindingLifecycleTest | test_bulk_lifecycle_changes_scrub_identity_and_advance_generation | Requires pgsql lifecycle triggers | PASS |
| PostgresAvailabilityConstraintTest | test_blocking_intervals_for_the_same_vehicle_cannot_overlap | Requires pgsql exclusion constraints | PASS |
| PostgresAvailabilityConstraintTest | test_adjacent_and_non_blocking_intervals_are_allowed | Requires pgsql exclusion constraints | PASS |
| PostgresAvailabilityConstraintTest | test_booking_time_constraint_rejects_a_rental_shorter_than_one_hour | Requires pgsql constraints | PASS |
| PostgresAvailabilityConstraintTest | test_notification_outbox_constraint_accepts_discarded_and_rejects_unknown_statuses | Requires pgsql constraints | PASS |
| PostgresBookingConcurrencyTest | test_parallel_pending_requests_are_allowed_but_parallel_approval_has_one_winner | Requires pgsql driver | PASS |
| PostgresPricingPresetConcurrencyTest | test_simultaneous_update_and_attach_leave_one_coherent_source_of_truth | Requires pgsql driver | PASS |
| PostgresPricingPresetConcurrencyTest | test_simultaneous_update_and_detach_leave_one_coherent_source_of_truth | Requires pgsql driver | PASS |
| PostgresTelegramRecoveryConcurrencyTest | test_same_reset_link_cannot_change_password_twice_concurrently | Requires pgsql driver | PASS |

There was no EXIF-related skip: the test-only PHP configuration loads `php_exif.dll`. The isolated-administrator guard in PostgresAdminAccountConcurrencyTest did not skip: the new database had no pre-existing administrators.

The final enlarged SQLite suite adds the following seven skips; each explicitly requires pgsql and each passed in the final PostgreSQL run:

| New test class | Method |
| --- | --- |
| PostgresPricingToolsConcurrencyTest | test_calculator_import_and_attach_have_one_coherent_winner |
| PostgresPricingToolsConcurrencyTest | test_two_imports_cannot_mix_calculator_rules_prices_or_assignment_revisions |
| PostgresPricingToolsConcurrencyTest | test_bulk_and_import_roll_back_their_uncontested_first_row_when_shared_second_row_is_stale |
| PricingPresetReadSnapshotTest | test_postgres_calculate_holds_one_snapshot_while_writer_waits |
| VehicleQuoteReadSnapshotTest | test_postgres_vehicle_read_blocks_preset_materialization_until_snapshot_finishes |
| VehicleQuoteReadSnapshotTest | test_postgres_detached_vehicle_read_blocks_manual_matrix_writer |
| VehicleQuoteReadSnapshotTest | test_postgres_nested_quote_does_not_release_or_downgrade_booking_update_lock |

## Isolation and execution evidence

- Installed runtime used without installation: `C:/OSPanel/modules/PostgreSQL-16/bin`, PostgreSQL 16.1. Existing Windows service `postgresql-x64-17` was running before and after, and was never targeted.
- Newly initialized cluster: `C:/Users/user/Desktop/phangan/.tmp/release-20261018/.tmp/pg-validation-20261029/data`.
- Listener: `127.0.0.1:55429`, checked by binding a temporary loopback TCP listener before initialization. PostgreSQL was launched with `-h 127.0.0.1 -p 55429`, without registering a Windows service.
- Database and synthetic cluster superuser: `phangan_validation_20261029`. A random test-only password was generated in process; `initdb` used a transient task-local pwfile, removed immediately afterward. Authentication is SCRAM-SHA-256; HBA allows only localhost entries. No trust authentication or non-loopback listener was enabled.
- Before `migrate:fresh`, `psql -X` checked `current_database()`, server `port`, `data_directory` and `inet_server_addr()`. Observed identity: `phangan_validation_20261029|55429|C:/Users/user/Desktop/phangan/.tmp/release-20261018/.tmp/pg-validation-20261029/data|127.0.0.1`. Any mismatch makes the harness abort before destructive tests.
- Every application DB connection field was set explicitly to this synthetic target. `DB_URL` was empty; app key was synthetic and process-scoped, queues synchronous, cache/session in-memory, mail array-backed. No `.env` file was changed.
- All migrations, including `2026_10_29_000001_add_pricing_calculation_settings`, completed successfully against that target.
- Tests spawned their actual existing PHP worker processes, so row-lock races were exercised across separate connections, not simulated sequential requests.
- Harness `finally` stopped only its exact `pg_ctl -D <task data path>` target. Output: `server stopped`; a subsequent status command returned `pg_ctl: no server running`. The init pwfile no longer exists. Cluster data and logs were retained; nothing material was deleted.

The sandboxed `initdb` attempt could not create/re-execute its Windows restricted token (errors 87/3). It did not create the data directory. The same guarded local harness then ran with the approved command-level sandbox escalation; it initialized and stopped the isolated cluster successfully.

## Commands and local artifacts

Working directory for PHPUnit commands: `C:/Users/user/Desktop/phangan/.tmp/release-20261018/backend`.

```powershell
$env:PHPRC = 'C:/Users/user/Desktop/phangan/.tmp/release-20261018/.tmp/pg-validation-20261029/php.ini'
$env:APP_ENV = 'testing'
$env:APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$env:DB_URL = ''
& 'C:/OSPanel/modules/PHP-8.3/PHP/php.exe' vendor/phpunit/phpunit/phpunit --display-skipped --colors=never --log-junit ../.tmp/pg-validation-20261029/sqlite-junit.xml
```

The test-only ini loads mbstring, fileinfo, gd, openssl, pdo_sqlite, sqlite3, pdo_pgsql, exif and zip from the installed PHP 8.3 runtime; `memory_limit=512M` and `sys_temp_dir` points to this checkout's `backend/storage/framework/testing`. `PHPRC` is inherited by race workers, so they use the same tested extension set.

The PostgreSQL orchestration is retained at `.tmp/pg-validation-20261029/run-postgres.ps1`. It refuses any existing data directory, creates synthetic credentials itself, performs the identity guard, executes the following commands and shuts down in `finally`:

```powershell
# The script sets the explicit synthetic PostgreSQL environment before these:
& 'C:/OSPanel/modules/PHP-8.3/PHP/php.exe' artisan migrate:fresh --force --no-interaction
& 'C:/OSPanel/modules/PHP-8.3/PHP/php.exe' vendor/phpunit/phpunit/phpunit --filter Postgres --display-skipped --colors=never --log-junit ../.tmp/pg-validation-20261029/postgres-junit.xml
```

Retained task-local evidence: `sqlite-junit.xml` (initial 351-test run), `postgres-junit.xml` (initial twelve-test PG run), `pricing-tools-junit.xml` (final 28-test PG run), `postgres.log`, `php.ini`, `run-postgres.ps1`, stopped `data/` and `tools-data/` through `tools-data-5/`. Each run initialized its own new cluster with a new random password; passwords were never retained. The task directory is ignored disposable local verification data, not release assets. Do not point this harness at a real database or change its refusal to reuse a cluster casually.

## Preliminary diagnostics, not accepted results

Before the final sequential SQLite run, an explicit `-n` attempt inherited PHP's 128 MiB default and exhausted memory during a large-image fixture. A minimal 512 MiB ini initially omitted ZipArchive, producing two XLSX errors. Two overlapping full suites also shared Laravel fake-storage paths, invalidating one image-hash assertion. These were environment/harness issues: ZipArchive was loaded, the suites were no longer overlapped, and the complete final run returned the original 338/13/3673 counts. No application code was changed to suppress failures.

The actual `vendor/phpunit/phpunit/phpunit` runner was used throughout. A quiet period before the final JSON summary is normal for this environment; an empty-output vendor/bin shim exit is not accepted as a passed test run.

The first new-operation PostgreSQL run passed two cases but failed the bulk case because the new test worker invoked an instance controller method as a static callable. The worker was corrected to resolve a controller instance from the container. A following run passed 3/3, 275 assertions. After adding worker no-env/cache protection and a two-connection readiness barrier, the final seven-test run above passed. No product behavior or expected conflict outcome was weakened to obtain green tests.

The first enlarged 28-test PostgreSQL run (`tools-data-4`) passed 27 and failed one new detached-vehicle expectation (428 assertions, 45.400 seconds). The test incorrectly compared the after-update quote to the original matrix: it expected `monthly_rate=500`, while the correct new matrix gave `monthly_rate=3500`; the retained eight-day override kept total 3700. The pricing implementer corrected the full expected quote to use **updated prices plus retained original settings**, without removing or weakening the strict full-array assertion. This was a test expectation error, not a product-code change. The final `tools-data-5` repeat passed all 28 tests.

## New-operation PostgreSQL races

New files owned by this validation task:

- `backend/tests/Feature/PostgresPricingToolsConcurrencyTest.php`.
- `backend/tests/Support/pricing_tools_race_worker.php`.
- This validation document. No functional application file was edited by this task.

The tests execute the real `PricingSettingsImport::preview()` and `commit()` path, the real `PricingPresetService::attach()`, and the real container-resolved `PricingToolsController::bulkUpdate()`. Import server revision snapshots have the same shape as the preview controller; no alternate import implementation is simulated in the worker.

1. Calculator-settings import versus attach uses one preview/version snapshot. Exactly one operation returns 200 and the other 409. The final preset version, vehicle revision, assignment, all fifteen materialized prices, enabled flags, full settings and derived three-season 1–31 calculator matrix must agree with the winner.
2. Two imports carry different matrices/rules/overrides but the same original revisions. Exactly one returns 200, the other 409; no settings/price/vehicle revision can come from the loser.
3. Bulk `[A, B]` races import `[C, B]`, with B deliberately second in each operation. The first row is uncontested; B is the shared optimistic-version conflict. Import does not explicitly remap B's vehicle, avoiding an early vehicle guard that would hide the late second-preset conflict. The losing transaction must leave its first preset/vehicle completely unchanged; the winner's two presets and linked vehicle matrices/settings/revisions must be coherent. Bulk audit rows must be either both present or both absent.

Both subprocesses acquire separate session advisory locks with one random pair key, then wait until `pg_locks` confirms two live PostgreSQL connections reached the readiness barrier (5-second deadline). They next use the same fixed release time. This prevents a quiet sequential start from being accepted as a two-worker race. Worker lock timeout is 5 seconds, statement timeout 10 seconds, process timeout 20 seconds; running children are stopped in `finally`.

The new worker receives payloads over stdin and explicit synthetic DB/application configuration via process env. It changes the Laravel environment-file directory to a nonexistent test-only directory and refuses cached application configuration before bootstrap: developer/CI `.env` and production config-cache values are not read. No credentials are emitted as process arguments or diagnostics.

Intermediate seven-test command from the clean checkout root:

```powershell
& '.tmp/pg-validation-20261029/run-postgres.ps1' -Stage tools -Attempt 3 -TestFilter 'PostgresPricingToolsConcurrencyTest|PricingPresetReadSnapshotTest'
```

That intermediate cluster was `.../.tmp/pg-validation-20261029/tools-data-3`, still on `127.0.0.1:55429`; the same four-field identity guard passed before migration. After the seven tests, `pg_ctl status` returned `no server running`, the transient pwfile did not exist, and the pre-existing PG17 service remained running. The earlier two tools clusters were also confirmed stopped.

Scoped checks on the two new PHP files: syntax checks PASS; Pint `--test` PASS; PHPStan level 7 using project configuration PASS with zero errors; `git diff --check` PASS. Full frontend/bot/build suites were not repeated for these backend-only test additions.

## Calculator read consistency found during review

The lead identified a separate calculate-preview race; code inspection confirmed it: the route-bound preset held old settings/version, while `PricingPresetService::matrix()` used a later `loadMissing('prices.season')` query. A committed update between those reads could pair new prices with old settings and old reported version. The frontend version guard alone could not detect that mixture.

The pricing implementer, not this validation task, fixed `calculate()` to reload the parent under a shared lock and read its prices/settings/version inside one transaction. Their separate `PricingPresetReadSnapshotTest.php` and `pricing_preset_read_worker.php` were included in the final PostgreSQL run. Three cases cover stale/preloaded models and calculator activation/deactivation; the fourth pauses a real reader immediately before its child-price query, confirms that the writer is waiting on a PostgreSQL row lock, then verifies coherent old and new snapshots. PostgreSQL result: **4/4, 21 assertions**. The original SQLite-only run of these cases has a different assertion count because it explicitly asserts successful SQLite fixture migrations; do not conflate those counts.

## Public vehicle quote snapshot and final PostgreSQL command

The pricing implementer also fixed the analogous public `PricingService::quote()` read boundary: a fresh vehicle is loaded under a shared lock inside the quote transaction before availability flags, calculator mode and materialized prices are read. It never acquires a preset lock after the vehicle, preserving writer lock ordering. This functional change justified repeating the existing PostgreSQL booking/concurrency tests, not only the new classes.

Their `VehicleQuoteReadSnapshotTest.php` and `vehicle_quote_read_worker.php` contributed **9/9 tests, 38 assertions** in the final PostgreSQL run. Six common regressions cover fresh flags/mode/rules/prices, retained rules after detach/manual saving, and the caller's outer transaction. Three PostgreSQL schedules confirm an actual writer `wait_event_type=Lock` during preset materialization or detached manual matrix updates, verify coherent old/new quote snapshots, and prove a nested quote does not release or downgrade a booking-style outer `FOR UPDATE` lock: a competing shared reader still waits after the nested quote has returned.

Final command, after coordinating completion of the pricing implementer's full SQLite run:

```powershell
& '.tmp/pg-validation-20261029/run-postgres.ps1' -Stage tools -Attempt 5 -TestFilter 'Postgres|PricingPresetReadSnapshotTest|VehicleQuoteReadSnapshotTest'
```

Observed identity before `migrate:fresh`: `phangan_validation_20261029|55429|C:/Users/user/Desktop/phangan/.tmp/release-20261018/.tmp/pg-validation-20261029/tools-data-5|127.0.0.1`.

Final PHPUnit JSON summary: `tests=28`, `passed=28`, `assertions=428`, `duration_ms=35736`; JUnit confirms `errors=0`, `failures=0`, `skipped=0`. `finally` reported `server stopped`; a subsequent exact-target status check reported `pg_ctl: no server running`. The transient password file did not exist, PG17 was still running, and C: had 8,636,821,504 bytes free. The failed fourth-attempt cluster was likewise confirmed stopped. No cluster data was deleted.

The pricing implementer updated the PostgreSQL CI filter to include both snapshot classes (`Postgres|PricingPresetReadSnapshotTest|VehicleQuoteReadSnapshotTest`). This local run verifies those tests, not the remote CI job or deployment.

## Coverage limits after these checks

The existing twelve-test suite covered legacy preset update versus attach/detach, administrator/Telegram/account races, booking approval and PostgreSQL constraints. The new three tests close simultaneous calculator-settings import, attach and bulk rollback paths; the separate read classes cover preset calculation and public vehicle quote consistency, including outer booking locks. These controlled schedules are evidence for the named cases, not exhaustive proof against every possible database interleaving. This task did not run the entire enlarged backend suite on PostgreSQL, live booking end-to-end, real Safari multitouch, or the Linux-only deployment-shell scenario.
