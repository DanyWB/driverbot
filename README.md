# Drive Phangan

Production rewrite of the Drive Phangan rental system. Laravel owns business logic,
data and the admin application. The Node.js Telegram bot uses the versioned Laravel
Bot API in the target mode; its direct Knex mode is retained only for rollback.

## Repository structure

- `backend/` - Laravel 13, Vue 3, TypeScript and Inertia admin application.
- `bot/` - Node.js Telegram UI, Laravel API client, Redis sessions and legacy rollback code.
- `scripts/` - shared local environment and setup commands.
- `ARCHITECTURE.md` - approved system boundaries and technical decisions.
- `IMPLEMENTATION_ROADMAP.md` - staged implementation plan and acceptance gates.
- `STAGE_4_BOOKING_CORE.md` - implemented booking and availability invariants.
- `STAGE_5_BOOKINGS_ADMIN.md` - implemented booking administration workflows and UI.
- `STAGE_6_TIMELINE.md` - implemented fleet availability timeline and calendar workflows.
- `STAGE_7_CATALOG_CUSTOMERS_EXPORT.md` - implemented fleet catalog, pricing, customers,
  private documents and CSV export.
- `STAGE_8_BOT_API.md` - implemented Bot API, Node.js cutover mode and operational runbook.

## Local infrastructure

From the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-local-redis.ps1
npm run check:environment
```

The environment check reads the ignored `bot/.env`. In Laravel mode it verifies
Laravel readiness plus Redis; in legacy mode it verifies the old PostgreSQL plus Redis.

## Telegram bot: Laravel mode

Start the Laravel application and Redis first. Issue a service token once:

```powershell
cd backend
php artisan bot-api:client issue --name="Local Telegram Bot"
```

Configure `bot/.env` from `bot/.env.example`. Required target-mode values are
`BOT_DATA_MODE=laravel`, `BOT_API_URL`, `BOT_API_TOKEN`, `REDIS_URL` and
`BOOKING_TZ=Asia/Bangkok`. PostgreSQL credentials are not required in this mode.

```powershell
cd bot
npm ci
npm run preflight
npm start
```

Do not run two instances with the same Telegram token. Token rotation and the full
cutover/rollback procedure are documented in `STAGE_8_BOT_API.md`.

## Legacy rollback bot

```powershell
cd bot
npm ci
Copy-Item .env.example .env
# Set BOT_DATA_MODE=legacy and fill the legacy PG_* variables in .env.
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
php artisan storage:link
npm run build
```

Vehicle thumbnail generation requires PHP GD with WebP support. The web server must
allow Laravel to write to `storage/app/public`, `storage/app/private` and
`storage/framework`; only the public disk may be exposed through `public/storage`.

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

Run the complete project gate from the repository root. The bot profile is selected
by `BOT_DATA_MODE` in `bot/.env`:

```powershell
npm run precheck
```

## Current boundary

Stages 0-8 are complete. In target mode Laravel is the only writer of business data;
permanent dual-write is prohibited. Stage 9 must deliver queued Telegram notifications,
pending expiry and reminders before production cutover. Stage 10 covers deployment,
backup/restore and final release hardening.
