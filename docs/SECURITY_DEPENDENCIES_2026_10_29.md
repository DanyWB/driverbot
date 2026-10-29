# Security dependency remediation, 29 October 2026

Baseline: clean checkout commit `142382bee90f276f43c6d926d2f8a3f3cd3549b8`. Preserve the pre-existing `PROJECT_STATE.md` edit. No commit, push or deployment is performed by this worker.

## Confirmed CI blocker

GitHub run [40924133252](https://github.com/DanyWB/driverbot/actions/runs/40924133252), job 123624999288, belongs to this exact SHA. PHP installation passed; `Audit PHP dependencies` failed; subsequent application configuration, frontend build, CI checks and PostgreSQL tests were skipped. Local `composer audit --locked --format=json` reproduces five advisories in two locked packages.

| Package before | Advisory | Issue | Fixed target |
| --- | --- | --- | --- |
| phpoffice/phpspreadsheet 5.9.0 | [CVE-2026-108357](https://github.com/advisories/GHSA-rxj4-h86g-25gc) | Double-encoded XML scanner bypass, XXE/DoS | 5.10.0 |
| phpoffice/phpspreadsheet 5.9.0 | [CVE-2026-108358](https://github.com/advisories/GHSA-3x94-m842-937c) | ISO-2022 XML scanner bypass, XXE/DoS | 5.10.0 |
| phpoffice/phpspreadsheet 5.9.0 | [CVE-2026-108359](https://github.com/advisories/GHSA-j2w7-387v-m2rq) | Unbounded ODS repeated rows, memory/CPU exhaustion | 5.10.0 |
| phpoffice/phpspreadsheet 5.9.0 | [CVE-2026-108360](https://github.com/advisories/GHSA-2phh-27fw-q4gc) | External-image redirect whitelist bypass, SSRF | 5.10.0 |
| symfony/mime 7.4.13 | [CVE-2026-45070](https://raw.githubusercontent.com/FriendsOfPHP/security-advisories/master/symfony/mime/CVE-2026-45070.yaml) | Invalid MIME parameter names allow email header injection | 7.4.22 |

PhpSpreadsheet has no 5.9.1 release. Root explicitly approved the minimal security-bearing minor update to 5.10.0, within existing `^5.9.0`. The current FriendsOfPHP advisory YAML and Composer registry require Symfony Mime >=7.4.22; the GitHub advisory HTML displays an older range, so that HTML was not used to lower the remediation target.

## Narrow change and compatibility

Dry-run and actual resolution both select exactly two updates, zero installs/removals and no other transitive changes. `composer.json` remains unchanged. Library metadata changes in lock include optional/development requirements, not new application runtime requirements.

The [PhpSpreadsheet 5.10.0 changelog](https://github.com/PHPOffice/PhpSpreadsheet/releases/tag/5.10.0) includes security fixes, optional encrypted XLSX support, sparklines, pivot-table preservation and reader/writer fixes. The app uses the existing IOFactory XLSX reader/writer API; no application call-site change is required. Symfony Mime remains on its existing 7.4 patch line and now rejects non-token parameter names. Ordinary filename parameters remain valid.

Commands use the installed Composer PHAR with a checkout-local PHP temp directory. Plugins and Composer lifecycle scripts are disabled for this targeted update to avoid unrelated application mutations; audit is not disabled or ignored:

```powershell
php -d sys_temp_dir=storage/framework/testing C:/ProgramData/ComposerSetup/bin/composer.phar update phpoffice/phpspreadsheet:5.10.0 symfony/mime:7.4.22 --no-interaction --no-scripts --no-plugins --no-cache
php -d sys_temp_dir=storage/framework/testing C:/ProgramData/ComposerSetup/bin/composer.phar audit --locked --no-interaction --no-cache --format=json
```

## Verification

- Locked audit: PASS, empty advisories/abandoned/filter arrays, exit 0.
- Composer manifest validation: PASS.
- Task-local, no-network dependency smoke: 3/3 PASS. Ordinary MIME filename preserved; CRLF parameter name rejected; two XML scanner callbacks do not materialize a double-encoded DOCTYPE.
- Composer update completed successfully, exit 0; the final built-in audit also reports no advisories.
- Focused `PricingWorkbookImportTest|PricingCalculatorToolsTest`: **18/18 PASS, 178 assertions**. Includes real XLSX generation/read/import/idempotence and native JSON export/reimport with legacy preservation.
- Full configured PHPStan: **PASS, zero errors**.
- `composer check-platform-reqs --lock --no-dev`: **PASS**, all installed runtime requirements met on local PHP 8.3.6.
- `git diff --check`: PASS; composer.json unchanged; lock diff is 16 additions/14 deletions describing exactly the two selected package records.
- Full SQLite PHP suite after the package update: **367 total, 347 passed, 20 skipped, 3716 assertions, exit 0**, 85.850 seconds. Counts match the accepted application baseline; the 19 PostgreSQL-only cases and one Windows/Linux deployment-shell guard remain skipped in this SQLite run. A new exact-SHA CI pass, including its PostgreSQL stage, is still required before release; prior CI results are not reused for the changed lock.

No audit ignore, security gate bypass, workflow relaxation, bulk dependency upgrade or production action was used.
