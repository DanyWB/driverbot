# Drive Phangan backend

Laravel backend and Vue/Inertia admin application for the Drive Phangan rental
system. The target architecture is documented in `../ARCHITECTURE.md`.

## Requirements

- PHP 8.3+
- Composer 2
- Node.js 22+
- PostgreSQL 17+
- Redis 7+

## Local setup

From the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-local-redis.ps1
npm run setup:laravel-local
```

The setup command creates a dedicated local database/role and writes credentials only
to ignored `backend/.env`.

Then run from `backend/`:

```powershell
composer install
npm install
php artisan key:generate
php artisan migrate
npm run build
```

## Create an administrator

Public registration is disabled. Create or update an administrator interactively:

```powershell
php artisan admin:create admin@example.com --name="Administrator"
```

The password is requested through a hidden prompt and is never accepted as a command
line argument.

## Development

```powershell
composer run dev
```

Application: `http://127.0.0.1:8000`

Health endpoints:

- `/health/live` - process liveness;
- `/health/ready` - PostgreSQL and Redis readiness.

## Quality checks

```powershell
composer ci:check
npm run build
```

Verify the Redis queue with a real worker:

```powershell
$probeId = php artisan operations:queue-probe
php artisan queue:work redis --once --queue=default --tries=1 --timeout=30
php artisan operations:queue-probe --verify=$probeId
php artisan schedule:list
```

## Pricing workbook

The workbook is an external source file and is not committed to Git. Validate it
without database writes:

```powershell
php -d memory_limit=1024M artisan pricing:import-workbook "C:\path\pricing.xlsx" --json
```

Apply an already reviewed file and audit the normalized catalog:

```powershell
php -d memory_limit=1024M artisan pricing:import-workbook "C:\path\pricing.xlsx" --apply
php artisan pricing:audit
```

Run a diagnostic quote through the same service used by future booking channels:

```powershell
php artisan pricing:quote click-blue-125-2019-A 2027-03-17 2027-04-15
```

Every applied import stores its source filename, SHA-256 and summary in
`data_import_runs`.

## Booking operations

Booking state changes, availability, repricing and maintenance are implemented in
the Laravel domain services documented in `../STAGE_4_BOOKING_CORE.md`.

Expire overdue pending bookings manually or inspect the scheduled task:

```powershell
php artisan bookings:expire-pending
php artisan schedule:list
```

Set `MANAGER_TELEGRAM_USERNAME` before exposing late client cancellation through a
customer channel.
