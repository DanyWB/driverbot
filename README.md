# Drive Phangan

Production rewrite of the Drive Phangan rental system. Laravel owns the target
business logic and admin application; the existing Node.js Telegram bot remains the
behavioral baseline until it is switched to the Laravel API.

## Repository structure

- `backend/` - Laravel 13, Vue 3, TypeScript and Inertia admin application.
- `bot/` - existing Node.js Telegram bot and its legacy Knex migrations.
- `scripts/` - shared local environment and setup commands.
- `ARCHITECTURE.md` - approved system boundaries and technical decisions.
- `IMPLEMENTATION_ROADMAP.md` - staged implementation plan and acceptance gates.
- `STAGE_4_BOOKING_CORE.md` - implemented booking and availability invariants.

## Local infrastructure

From the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-local-redis.ps1
npm run check:environment
```

The environment check reads the ignored `bot/.env` and verifies the legacy PostgreSQL
database plus Redis without printing credentials.

## Legacy bot

```powershell
cd bot
npm ci
Copy-Item .env.example .env
npm run migrate
npm run preflight
npm start
```

The existing local `bot/.env` is already ignored by Git. Detailed legacy setup notes
are in `bot/README.md`.

From the repository root, the bot can also be started and checked with:

```powershell
npm start
npm run preflight
```

## Laravel backend

Start Redis, then prepare the dedicated local PostgreSQL role and database:

```powershell
npm run setup:laravel-local
cd backend
composer install
npm install
php artisan key:generate
php artisan migrate
npm run build
```

Create or update the administrator with a hidden password prompt:

```powershell
php artisan admin:create admin@example.com --name="Administrator"
```

Run the application:

```powershell
composer run dev
```

Application: `http://127.0.0.1:8000`

Health endpoints:

- `GET /health/live` - process liveness.
- `GET /health/ready` - PostgreSQL and Redis readiness.

Quality gates:

```powershell
composer ci:check
npm run build
```

Run the complete project gate from the repository root:

```powershell
npm run precheck
```

## Current boundary

The bot still writes to the legacy database during the migration stages. New business
logic must be implemented in Laravel only. Permanent dual-write is prohibited; the
bot will become an API client during the dedicated cutover stage.
