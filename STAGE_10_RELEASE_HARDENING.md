# Этап 10. Release hardening и deployment

Дата реализации локальной части: 2026-07-22.

Статус: engineering scope выполнен и локально проверен. Production acceptance gate остается
открытым до развертывания на реальном сервере, передачи секретов/контента и приемки заказчиком.

## 1. Реализовано

- production env templates для Laravel и Node.js без секретов;
- Nginx, PHP-FPM, logrotate и systemd configs;
- отдельные auto-restart services для default worker, notification worker, scheduler и bot;
- immutable releases + shared env/storage + atomic `current` symlink;
- deploy и code rollback scripts;
- PostgreSQL + public/private files backup, checksums, verify и destructive-safe restore-test;
- строгий `operations:release-preflight --strict`;
- scheduler heartbeat, optional readiness gate и реальный queue probe;
- security headers: CSP nonce без `unsafe-inline`, HSTS, trusted host/proxies, frame/MIME/referrer policy;
- offline/online bot production preflight;
- tracked-secret scan в локальном precheck и CI;
- HTTP E2E и конкурентный Bot API load scenario;
- operational runbook и production acceptance checklist.

## 2. Локальная acceptance-репетиция

На копии текущей PostgreSQL выполнено:

1. Production backup скриптом `deploy/scripts/backup.sh`.
2. SHA-256, `pg_restore --list` и tar verification.
3. Реальное восстановление в `drive_phangan_stage10_restore_test`.
4. Проверка 29 public tables и восстановленного public/private storage.
5. Подъем отдельного Laravel HTTP staging на порту 8010.
6. Полный Bot API E2E: customer sync/profile, catalog, availability, quote, pending,
   idempotency replay, list/details, private document и cancellation.
7. Гонка 10 HTTP-клиентов за одну технику: `1x201 + 9x409`.
8. После cleanup: 3 synthetic cancelled bookings, `0` blocking occupancy, `0` failed jobs.
9. Реальный scheduler heartbeat и Redis default queue probe.
10. Проверка staging logs: error/critical записей нет.

Локальный latency result: p50 2577 ms, p95/max 5798 ms. Это не production benchmark:
Windows `artisan serve` почти последовательно обслуживает параллельные запросы. Обязательный
staging gate на Nginx/PHP-FPM: 10 клиентов, без 5xx, p95 <= 3000 ms.

Backup/restore rehearsal выявил и устранил environment mismatch: PostgreSQL server был 17.5,
поэтому `pg_dump` 16.1 корректно отказался работать; тест повторен и пройден client tools 17.

## 3. Security review

Проверено и/или закреплено тестами:

- active-admin middleware и login rate limits;
- password policy, 2FA/passkey capability;
- service token hash, abilities, revoke/rotate, auth/user rate limits;
- private document controller и `Cache-Control: no-store, private`;
- image/document MIME, size и image dimensions;
- request ID и безопасный JSON error contract;
- no direct business DB access из Laravel-mode bot;
- no tracked `.env`, Telegram/AWS/GitHub token patterns или private keys;
- secure cookies/CSP/HSTS обязательны в strict production preflight;
- SMTP обязателен, чтобы reset links не попадали в log mailer.

Финальный regression result: Laravel 182 tests / 1159 assertions (177 passed, 5 PostgreSQL-only
skipped в SQLite), отдельный PostgreSQL suite 5/5 tests / 8 assertions, Node.js 15/15 tests,
Pint/PHPStan/ESLint/Prettier/Vue types/Vite build без ошибок. Composer и оба npm production
audits: 0 известных vulnerabilities. Secret scan и `git diff --check` проходят.

## 4. Фактическое состояние данных

Текущая локальная БД:

- 29 active visible vehicles;
- pricing audit проходит для всех 29;
- pending migrations отсутствуют;
- failed jobs и failed notifications отсутствуют;
- 29/29 visible vehicles пока без primary photo;
- production admin и active Bot API client намеренно не создавались;
- финальный cutover import не выполнялся повторно.

Strict preflight поэтому должен оставаться красным до заполнения production env, создания admin
и service client, загрузки фото и подтверждения финальности каталога заказчиком.

## 5. Что нельзя закрыть локально

- installation/restart rehearsal настоящих systemd, Nginx и PHP-FPM;
- production-like latency на многопроцессном PHP-FPM;
- real Telegram `getMe`, admin/client notification delivery и bot long polling;
- SMTP password reset delivery;
- off-server backup copy;
- финальное подтверждение каталога, фото и условий;
- заказчиковая staging-приемка;
- наблюдение первые 24 часа после cutover.

Это не незавершенный код, а внешний release gate. Полный список находится в
`RELEASE_ACCEPTANCE_CHECKLIST.md`.

## 6. Следующее действие

Подготовить сервер и внешние значения, загрузить/подтвердить каталог и фото, затем выполнить
`deploy-release.sh`. Release считается production-ready только после зеленого strict preflight,
реального restore rehearsal на сервере, online Telegram smoke, rollback rehearsal и подписи
заказчика по acceptance checklist.
