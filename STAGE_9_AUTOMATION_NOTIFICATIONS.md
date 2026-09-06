# Этап 9. Автоматизация и уведомления

Дата закрытия: 2026-07-22.

Статус: выполнен. Реальная отправка в Telegram требует token, bot username и хотя бы
одного подключенного администратора. `TELEGRAM_ADMIN_CHAT_ID` оставлен только как
временный bootstrap fallback до первой DB-привязки; секреты не хранятся в Git.

## 1. Результат

Laravel полностью владеет шаблонами, планированием и надежной доставкой уведомлений.
Изменение брони сохраняет notification intent в `notification_outbox` в той же
транзакции, что status history, audit и occupancy. HTTP-запрос к Telegram выполняется
отдельным Redis queue worker и не удерживает транзакцию бронирования.

Реализованы:

- уведомление администратора о новой pending-заявке;
- уведомление администратора об отмене клиентом;
- сообщения клиенту о pending, approved, admin cancel и expired;
- сообщения клиенту при изменении дат и итоговой цены;
- напоминание в день выдачи;
- напоминание за час, только если указано время выдачи;
- auto-expire pending через 24 часа с освобождением occupancy;
- retry/backoff, terminal failures, stale processing recovery и ручной retry;
- reconciliation напоминаний и housekeeping;
- локализованные шаблоны `ru`, `en`, `ua` и безопасное HTML-экранирование;
- monitoring command и структурированные warning/error logs.
- персональная Telegram-привязка каждого web-администратора одноразовым кодом;
- независимый fan-out административных событий всем активным получателям.

Поздняя отмена менее чем за 24 часа остается синхронным сценарием Bot API: бронь не
изменяется, а клиент получает runtime `MANAGER_TELEGRAM_USERNAME`. Отдельное
уведомление администратору в этом сценарии не создается согласно решению заказчика.

## 2. Матрица событий

| Событие | Получатель | Результат |
|---|---|---|
| `booking.pending` | клиент | Заявка принята и ожидает подтверждения |
| `booking.pending` | администратор | Новая заявка с клиентом, техникой, датами, ценой и опциями |
| `booking.approved` | клиент | Бронь подтверждена |
| `booking.cancelled` | клиент | Администратор отменил бронь |
| `booking.cancelled_by_client` | администратор | Клиент отменил бронь |
| `booking.expired` | клиент | Заявка истекла, техника освобождена |
| `booking.dates_changed` | клиент | Старый/новый период и актуальная цена |
| `booking.price_changed` | клиент | Новая итоговая сумма |
| `booking.reminder.pickup_day` | клиент | Напоминание в день выдачи |
| `booking.reminder.pickup_one_hour` | клиент | Напоминание за час до выдачи |

Клиентские сообщения имеют callback на карточку брони в Telegram. Expired предлагает
создать новую заявку. Административное сообщение ведет в web-карточку брони.

## 3. Delivery pipeline

Состояния записи outbox:

```text
pending -> processing -> sent
                |
                +-> pending     временная ошибка и backoff
                +-> failed      permanent error или исчерпаны attempts
                +-> discarded   событие больше не применимо
```

`notifications:dispatch-outbox` выбирает due/stale получателей и ставит одну
уникальную `DeliverNotificationRecipient` job на пару `channel + recipient`. Job
доставляет события последовательно. Если старое событие ушло в retry, более новое не
обгоняет его. Будущие плановые reminders при этом не блокируют текущие сообщения.

По умолчанию выполняется до 8 попыток с backoff `60, 300, 900, 3600, 21600` секунд.
Telegram `429 retry_after` имеет приоритет. `400/401/403` считаются постоянной ошибкой;
`429`, `5xx`, connection timeout и временная сетевая ошибка повторяются.

Уникальный `deduplication_key` предотвращает создание второго логического события.
Telegram Bot API не предоставляет idempotency key для `sendMessage`, поэтому остается
неустранимое внешнее окно: процесс может завершиться после принятия сообщения
Telegram, но до записи `sent_at`. Для расследования сохраняются attempts,
`provider_message_id`, timestamps, last error и structured logs.

Для административного события создается отдельная outbox-запись на каждого активного
администратора. Получатель имеет версионированный ключ
`admin-binding:<binding-id>:v<generation>`. При отвязке, повторной привязке, удалении,
деактивации или снятии email verification прежнее поколение становится неприменимым и
delivery переводит ожидающее сообщение в `discarded`, не обращаясь к Telegram. Ошибка
одного администратора не блокирует доставку остальным. Для административного действия
инициатор исключается из fan-out, но остальные подключенные администраторы продолжают
получать событие. Очистка identity защищена как Eloquent observer, так и PostgreSQL
lifecycle-trigger, поэтому bulk update/delete также не может оживить старую очередь или
оставить уникальный Telegram ID в orphan-записи; все еще активные коды такого администратора
отзываются той же транзакцией.

## 4. Expiry и reminders

`bookings:expire-pending` каждые 10 минут блокирует запись брони, повторно проверяет
статус/deadline, переводит due-заявку в `expired`, удаляет blocking occupancy и в той
же транзакции создает клиентское уведомление. Повторный запуск идемпотентен.

Reminders планируются в outbox при создании approved-брони, переходе в approved и
смене дат. `reminder_version` увеличивается при новом расписании:

- предыдущие pending/processing reminders переходят в `discarded`;
- повтор reconciliation одной версии не создает дубликаты;
- выход из `approved` отменяет оставшиеся reminders;
- delivery повторно сверяет статус, дату, время и version перед отправкой.

Day reminder планируется на `BOOKING_DAY_REMINDER_TIME`, по умолчанию `08:00` в
`Asia/Bangkok`. Если выдача раньше, сообщение сдвигается на допустимое время того же
дня. One-hour reminder создается только при `pickup_time`. Если бронь подтверждена
после планового времени, но до выдачи, отправляется ближайшее релевантное сообщение,
без двух одинаковых немедленных reminders.

`bookings:reconcile-reminders` каждые 10 минут восстанавливает отсутствующие intents
для approved-брони в заданном горизонте. Это покрывает downtime scheduler и записи,
существовавшие до миграции этапа 9.

## 5. Scheduler и worker

Расписание:

```text
* * * * *     notifications:dispatch-outbox
*/5 * * * *   notifications:monitor        production only
*/10 * * * *  bookings:expire-pending
*/10 * * * *  bookings:reconcile-reminders
20 3 * * *    operations:housekeeping
0 3 * * *     queue:prune-failed --hours=168
```

Локальный запуск процессов:

```powershell
php artisan schedule:work
php artisan queue:work redis --queue=notifications,default --tries=1 --timeout=60
```

Production process supervision, restart policy и log routing входят в этап 10.

## 6. Операционные команды

```powershell
php artisan notifications:dispatch-outbox
php artisan notifications:monitor
php artisan notifications:retry-failed --id=123
php artisan notifications:retry-failed --all
php artisan bookings:expire-pending
php artisan bookings:reconcile-reminders
php artisan operations:housekeeping --dry-run
php artisan operations:housekeeping
```

`notifications:monitor` возвращает non-zero при terminal failures, просроченном
pending, stale processing или отсутствующей обязательной Telegram-конфигурации.
`failed` записи housekeeping не удаляет. Ручной retry требует явного `--id` либо
`--all`, сбрасывает attempts и возвращает запись в pending.

## 7. Конфигурация

Обязательные production secrets/runtime values:

```dotenv
TELEGRAM_BOT_TOKEN=<same bot token stored as a backend secret>
TELEGRAM_BOT_USERNAME=<bot username without a secret>
# Optional only on a fresh installation, before the first DB binding:
TELEGRAM_ADMIN_CHAT_ID=<bootstrap numeric chat id>
TELEGRAM_BINDING_CODE_TTL_MINUTES=10
TELEGRAM_BINDING_CODE_RETENTION_DAYS=7
MANAGER_TELEGRAM_USERNAME=@username
APP_URL=https://admin.example.com
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

Каждый администратор открывает `Настройки -> Уведомления Telegram`, подтверждает пароль,
создает одноразовый код и отправляет боту команду `/bind XXXX-XXXX` в личном чате. Код
действует 10 минут, хранится только как HMAC и погашается один раз. Затем следует отправить
тестовое сообщение с той же страницы. Процедура повторяется из web-аккаунта каждого
администратора; один Telegram ID нельзя подключить к двум администраторам.
Уже подключенный администратор может выпустить код замены без предварительной отвязки:
старый чат продолжает получать уведомления до успешного `/bind`, после чего generation
атомарно меняется. Если `/bind` с валидным кодом случайно отправлен в группе, бот best
effort удаляет сообщение и через доверенный API сразу отзывает раскрытый код.

До первой записи в `admin_telegram_bindings` допускается numeric
`TELEGRAM_ADMIN_CHAT_ID`. Как только появилась первая привязка или tombstone, список в
БД навсегда становится авторитетным, а fallback не включается повторно. Поэтому отвязка
последнего администратора означает ноль административных получателей до новой привязки.
Полный список tuning-параметров находится в `backend/.env.example`.

## 8. Проверки

- временная ошибка Telegram возвращает intent в pending с backoff;
- permanent `403` переводит intent в failed;
- более новое событие не обгоняет ожидающий retry;
- stale processing повторно подбирается;
- один recipient получает одну queue job независимо от числа due rows;
- неизвестный/устаревший reminder не вызывает HTTP и становится discarded;
- reminder versions, смена дат, cancellation и reconciliation идемпотентны;
- динамические поля HTML-экранируются;
- provider message ID и sent timestamp сохраняются;
- manual retry, monitor и dry-run housekeeping покрыты feature tests;
- одноразовость/TTL кодов, private-chat identity, multi-admin fan-out, смена generation,
  удаление админа и запрет возврата legacy fallback покрыты feature tests;
- PostgreSQL migration применена и расширяет status constraint значением discarded.

Финальный срез:

- единый `npm run precheck` прошел;
- Laravel: 177 tests, 172 passed, 5 PostgreSQL-only skipped в SQLite,
  1131 assertions;
- отдельный PostgreSQL-набор: 5 tests, 8 assertions, включая multi-process
  race `201 + 409` и новый outbox status constraint;
- Node.js bot: 13/13 unit tests, Laravel-mode boot и Redis concurrency check;
- новая `DeliverNotificationRecipient` job сериализована в локальный Redis и успешно
  обработана реальным Laravel worker;
- Pint, PHPStan, ESLint, Prettier, Vue TypeScript и Vite production build прошли;
- Composer и оба npm dependency audits: 0 известных vulnerabilities;
- `/health/ready`: PostgreSQL и Redis `ok`.

## 9. Следующий этап

Этап 10: release hardening и deployment. Нужны production process configs для
PHP-FPM, scheduler, notification/default workers и Node.js bot, staging smoke с
реальным Telegram token, нагрузочные проверки, backup/restore и cutover rehearsal.
