# Operations runbook

Дата актуализации: 2026-09-02.

## 1. Production topology

Один production-сервер содержит:

- Nginx -> PHP-FPM -> Laravel web/admin и `/api/v1/bot`;
- PostgreSQL как единственный источник бизнес-данных;
- Redis для cache, session, queue и bot sessions;
- два Laravel worker: `default` и `notifications`;
- Laravel `schedule:work`;
- Node.js Telegram-бот в long polling режиме;
- локальные persistent files: публичные фото и приватные документы.

Для `default` worker с `--timeout=90` значение `REDIS_QUEUE_RETRY_AFTER` должно быть не меньше
`120`, чтобы Redis не выдал длительную задачу повторно до завершения первого процесса.

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
backup. Каждый release содержит `release-manifest.env` с UTC ID, Git revision и ref; source
обязан быть чистым Git checkout. В Git секретов и пользовательских файлов нет.

## 4. Первичная настройка

1. Создать системного пользователя `drive-phangan` и каталоги `/srv/drive-phangan`.
2. Скопировать `deploy/env/backend.production.example` в `shared/backend/.env` и
   `deploy/env/bot.production.example` в `shared/bot/.env`.
3. Заменить все `CHANGE_ME`, выставить права `0600` и владельца `drive-phangan`.
   `BOT_TOKEN` в bot env и `TELEGRAM_BOT_TOKEN` в backend env должны совпадать.
   В backend env также указать публичный `TELEGRAM_BOT_USERNAME`. Numeric
   `TELEGRAM_ADMIN_CHAT_ID` допустим только как bootstrap до первой web-привязки.
4. Создать `APP_KEY` командой `php artisan key:generate --show` в защищенном окружении.
5. Настроить PostgreSQL, Redis, SMTP, домен и TLS.
6. Установить конфиги из `deploy/nginx`, `deploy/php-fpm`, `deploy/systemd` и `deploy/logrotate`.
7. Заменить `admin.example.com`, путь сертификата и имя PHP-FPM service.
8. Выполнить `chmod 0750 deploy/scripts/*.sh` в deployment checkout.
9. Выполнить `systemctl daemon-reload` и включить `drive-phangan.target` и
   `drive-phangan-backup.timer`.

Шаблон FPM ограничивает pool шестью children. Четыре long-running service имеют 30-секундный
restart backoff, `Restart=on-failure` и start limit `5/600s`; после установки unit-файлов это проверяется через
`systemctl cat` и `systemctl show`.

До public launch нужно разделить runtime identities: создать отдельного пользователя
`drive-phangan-bot`, передать ему только `shared/bot/.env`, переключить `User`/`Group` bot unit и
добавить ему `InaccessiblePaths=/srv/drive-phangan/shared/backend`. После этого повторно проверить
`release:smoke`, чтение bot env и невозможность чтения backend env/storage. Текущий общий runtime
user оставлен в шаблонах только до выполнения этого provisioning шага.

До первого cutover создать администратора и service client:

```bash
php artisan admin:create admin@real-domain.example
php artisan bot-api:client issue --name="Production Telegram Bot"
```

Service token показывается один раз и помещается только в `shared/bot/.env`. Для администратора
до запуска подтверждается email и включается TOTP 2FA или passkey.

После запуска bot/API каждый получатель самостоятельно открывает в админке
`Настройки -> Уведомления Telegram`, подтверждает пароль, создает одноразовый код и отправляет
команду `/bind XXXX-XXXX` боту в личном чате. На странице нужно выполнить тестовую отправку.
Повторить для каждого администратора. Первый bind отключает bootstrap fallback навсегда;
пустой список после последующей отвязки является намеренным состоянием, а не поводом возвращать
старый env ID.

Для смены Telegram-аккаунта используется кнопка замены на той же странице: сначала создается
и принимается новый код, старый аккаунт до этого момента остается рабочим. Не отключайте старую
привязку заранее без необходимости. Код, опубликованный в группе, считается раскрытым: бот
сразу попытается удалить команду и отозвать код. Независимо от ответа бота нужно создать
новый код и проверить текущий подключенный аккаунт на странице настроек.

## 5. Release

Из проверенного immutable checkout:

```bash
APP_ROOT=/srv/drive-phangan \
SMOKE_BASE_URL=https://admin.real-domain.example \
PHP_FPM_SERVICE=php8.3-fpm \
DEPLOY_MIN_FREE_MB=2048 \
DEPLOY_CUTOVER_MIN_FREE_MB=768 \
RELEASE_RETENTION_COUNT=2 \
bash deploy/scripts/deploy-release.sh /path/to/checkout
```

Checkout должен быть корнем Git repository без tracked или untracked изменений. Deploy и
rollback используют общий non-blocking lock: параллельный запуск завершается до любых изменений.
Перед build требуется минимум `DEPLOY_MIN_FREE_MB`, перед maintenance/cutover — минимум
`DEPLOY_CUTOVER_MIN_FREE_MB` свободного места. Значения можно только осознанно повысить или
адаптировать под размер диска; нулевые и отрицательные значения запрещены.

Скрипт выполняет:

1. Удаление expired releases и проверка свободного места.
2. Копирование чистого Git revision в новый release без legacy SQL/data dumps.
3. Production install Composer/npm, Vite build и обязательные locked production dependency audits;
   backend `node_modules` после build удаляется.
4. Создание revision manifest и связывание shared env/storage.
5. Maintenance mode и остановку bot/worker/scheduler.
6. Backup БД и persistent files.
7. `migrate --force`, `optimize` и строгий release preflight.
8. Перевод code tree в root-owned read-only режим без обхода shared symlinks.
9. Атомарное переключение `current`.
10. Запуск процессов, reload PHP-FPM и полный smoke.
11. Retention: текущий и один соседний release для code rollback.

Неполный release до/после неуспешного cutover удаляется только после проверки безопасного пути.
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
Release smoke дополнительно требует active state обоих workers, scheduler и bot, затем запускает
online bot preflight для Laravel Bot API, Redis и Telegram `getMe`.

Только до cutover, когда production processes намеренно еще не подняты, разрешен ограниченный
smoke без readiness, process checks и online bot calls:

```bash
ALLOW_PRE_CUTOVER_SMOKE=true \
SMOKE_BASE_URL=https://admin.real-domain.example \
deploy/scripts/smoke.sh
```

После cutover и для deploy/rollback этот флаг запрещен: отсутствие флага всегда означает полный
smoke. Старый opt-in `RUN_BOT_API_SMOKE` больше не используется.

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

Сначала устраняется причина ошибки. Массовый retry не запускается, пока Telegram token,
bot username, активные DB-привязки, сеть и шаблоны не проверены.

Если outbox содержит давно просроченные `pending`-записи без `last_error`, а
`failed_jobs` пуст, сначала проверьте, что одновременно работают scheduler и worker
очереди `notifications`; такой backlog обычно означает, что dispatcher не запускался,
а не ошибку Telegram API. До ручного `notifications:dispatch-outbox` убедитесь, что
`TELEGRAM_BOT_TOKEN`/`TELEGRAM_BOT_USERNAME` относятся к нужному окружению,
нужные администраторы отображаются подключенными и получили тестовое сообщение, Laravel
config cache обновлён, а исходящие запросы
к `api.telegram.org` разрешены. После исправления используйте monitor и точечный retry;
массовая отправка старого локального backlog в production-чат запрещена.

## 8. Backup

Ежедневный timer запускает:

```bash
APP_ROOT=/srv/drive-phangan deploy/scripts/backup.sh
deploy/scripts/verify-backup.sh /srv/drive-phangan/backups/<timestamp>
```

Backup содержит PostgreSQL custom dump, `storage/app/public`, `storage/app/private`, manifest и
SHA-256 checksums. `.env` и другие секреты в него не входят. Копия должна регулярно уходить на
второй сервер или object storage; локальная копия не защищает от потери всего сервера.
При `ENABLE_BACKUP_PRUNE=true` expired timestamp-каталоги удаляются под backup lock до создания
нового dump (это освобождает место при заполненном диске) и повторно после успешного backup.
Root-запуск нормализует backup tree к `BACKUP_OWNER=drive-phangan`, `0700/0600`, чтобы следующий
systemd-запуск и retention от service user не блокировались root-owned файлами.

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

Rollback использует тот же deploy lock. Если target release не проходит полный smoke, скрипт
автоматически возвращает исходный `current`, поднимает приложение и services. БД при этом не
откатывается.

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
