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
