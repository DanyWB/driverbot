# Operations runbook

Дата актуализации: 2026-07-22.

## 1. Production topology

Один production-сервер содержит:

- Nginx -> PHP-FPM -> Laravel web/admin и `/api/v1/bot`;
- PostgreSQL как единственный источник бизнес-данных;
- Redis для cache, session, queue и bot sessions;
- два Laravel worker: `default` и `notifications`;
- Laravel `schedule:work`;
- Node.js Telegram-бот в long polling режиме;
- локальные persistent files: публичные фото и приватные документы.

Бот не принимает входящий HTTP-трафик и не имеет прямого доступа к business DB. Он делает
исходящие запросы в Telegram и Laravel Bot API.

## 2. Версии и пакеты

Минимум:

- Linux с systemd, Nginx и PHP-FPM;
- PHP 8.3 с `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_pgsql`, `redis`;
- Composer 2;
- Node.js 20+ и npm;
- PostgreSQL 17 и client tools той же или более новой major-версии;
- Redis;
- `bash`, `curl`, `rsync`, `tar`, `sha256sum`, `flock`.

`pg_dump` более старой major-версии, чем сервер PostgreSQL, использовать нельзя. Скрипт
backup завершится ошибкой, а неполный архив не станет финальным backup.

## 3. Структура каталогов

```text
/srv/drive-phangan/
  current -> releases/<UTC timestamp>
  releases/
  shared/backend/.env
  shared/backend/storage/
  shared/bot/.env
  backups/
```

Код release неизменяемый. Между release сохраняются только `.env`, Laravel `storage` и
backup. В Git секретов и пользовательских файлов нет.

## 4. Первичная настройка

1. Создать системного пользователя `drive-phangan` и каталоги `/srv/drive-phangan`.
2. Скопировать `deploy/env/backend.production.example` в `shared/backend/.env` и
   `deploy/env/bot.production.example` в `shared/bot/.env`.
3. Заменить все `CHANGE_ME`, выставить права `0600` и владельца `drive-phangan`.
   `BOT_TOKEN` в bot env и `TELEGRAM_BOT_TOKEN` в backend env должны совпадать.
4. Создать `APP_KEY` командой `php artisan key:generate --show` в защищенном окружении.
5. Настроить PostgreSQL, Redis, SMTP, домен и TLS.
6. Установить конфиги из `deploy/nginx`, `deploy/php-fpm`, `deploy/systemd` и `deploy/logrotate`.
7. Заменить `admin.example.com`, путь сертификата и имя PHP-FPM service.
8. Выполнить `chmod 0750 deploy/scripts/*.sh` в deployment checkout.
9. Выполнить `systemctl daemon-reload` и включить `drive-phangan.target` и
   `drive-phangan-backup.timer`.

До первого cutover создать администратора и service client:

```bash
php artisan admin:create admin@real-domain.example
php artisan bot-api:client issue --name="Production Telegram Bot"
```

Service token показывается один раз и помещается только в `shared/bot/.env`. Для администратора
до запуска включается TOTP 2FA или passkey.

## 5. Release

Из проверенного immutable checkout:

```bash
APP_ROOT=/srv/drive-phangan \
SMOKE_BASE_URL=https://admin.real-domain.example \
PHP_FPM_SERVICE=php8.3-fpm \
bash deploy/scripts/deploy-release.sh /path/to/checkout
```

Скрипт выполняет:

1. Копирование кода в новый release.
2. Production install Composer/npm и Vite build.
3. Связывание shared env/storage.
4. Maintenance mode и остановку bot/worker/scheduler.
5. Backup БД и persistent files.
6. `migrate --force`, `optimize` и строгий release preflight.
7. Атомарное переключение `current`.
8. Запуск процессов, reload PHP-FPM и smoke.

При ошибке после начала cutover скрипт возвращает `current` на предыдущий код. Миграции
автоматически назад не откатываются. Все production migrations должны быть backward-compatible
с предыдущим release; восстановление БД выполняется только по incident decision.

## 6. Runtime checks

```bash
php artisan operations:release-preflight --strict
php artisan operations:runtime-status --wait=20
php artisan notifications:monitor
curl --fail https://admin.real-domain.example/health/live
curl --fail https://admin.real-domain.example/health/ready
```

`runtime-status` проверяет PostgreSQL, Redis, свежий scheduler heartbeat и отправляет harmless
job в default queue. `/health/ready` в production также требует свежий scheduler heartbeat.

Процессы и logs:

```bash
systemctl status drive-phangan.target
systemctl status drive-phangan-worker-default.service
systemctl status drive-phangan-worker-notifications.service
systemctl status drive-phangan-scheduler.service
systemctl status drive-phangan-bot.service
journalctl -u drive-phangan-bot.service -n 200 --no-pager
journalctl -u drive-phangan-worker-notifications.service -n 200 --no-pager
tail -n 200 /srv/drive-phangan/shared/backend/storage/logs/laravel.log
```

## 7. Queue и notifications

```bash
php artisan queue:failed
php artisan queue:retry <job-uuid>
php artisan notifications:monitor
php artisan notifications:retry-failed --id=<outbox-id>
php artisan notifications:retry-failed --all
php artisan notifications:dispatch-outbox
```

Сначала устраняется причина ошибки. Массовый retry не запускается, пока Telegram token, chat ID,
сеть и шаблоны не проверены.

## 8. Backup

Ежедневный timer запускает:

```bash
APP_ROOT=/srv/drive-phangan deploy/scripts/backup.sh
deploy/scripts/verify-backup.sh /srv/drive-phangan/backups/<timestamp>
```

Backup содержит PostgreSQL custom dump, `storage/app/public`, `storage/app/private`, manifest и
SHA-256 checksums. `.env` и другие секреты в него не входят. Копия должна регулярно уходить на
второй сервер или object storage; локальная копия не защищает от потери всего сервера.

Тест восстановления:

```bash
ALLOW_RESTORE_TEST=true \
RESTORE_ADMIN_USER=postgres \
RESTORE_ADMIN_PASSWORD='<secret>' \
RESTORE_TEST_DATABASE=drive_phangan_restore_test \
deploy/scripts/restore-test.sh /srv/drive-phangan/backups/<timestamp>
```

Имя disposable DB обязательно заканчивается на `_restore_test`. Скрипт проверяет checksums,
реально восстанавливает БД и files во временный каталог, затем удаляет тестовую БД. Запускать
restore-test по расписанию не реже одного раза в месяц и перед крупным schema update.

## 9. Rollback и disaster restore

Rollback только кода:

```bash
SMOKE_BASE_URL=https://admin.real-domain.example \
deploy/scripts/rollback-release.sh /srv/drive-phangan/releases/<timestamp>
```

Полное восстановление БД требует отдельного решения:

1. Включить maintenance mode и остановить target.
2. Снять дополнительный incident backup текущего состояния.
3. Проверить выбранный backup через `verify-backup.sh`.
4. Восстановить dump в новую БД, не поверх единственной production DB.
5. Распаковать persistent files в новый shared-каталог.
6. Переключить env/links на восстановленные ресурсы.
7. Запустить код release, соответствующий backup, и выполнить smoke.
8. Старую БД и files оставить read-only до завершения расследования.

## 10. Post-cutover observation

Первые 24 часа проверять минимум каждые 15 минут, затем ежедневно:

- `/health/ready` и systemd restart counters;
- `failed_jobs`, failed/overdue notification outbox;
- Laravel error/critical logs и Nginx 5xx;
- PostgreSQL disk/connection usage и Redis memory;
- новые pending, approve, cancel и reminders;
- свободное место backup и persistent storage.

Секреты ротируются при подозрении на утечку. Bot API token ротируется через
`bot-api:client rotate`, после чего bot service перезапускается и старый token больше не работает.
