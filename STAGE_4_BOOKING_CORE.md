# Этап 4. Booking и Availability Core

Дата закрытия: 2026-07-21.

Статус: выполнен.

## 1. Результат

В Laravel реализован единый транзакционный контур бронирования. Админка, Telegram-бот
и будущий клиентский сайт должны вызывать эти application services и не содержать
собственных копий правил статусов, доступности или перерасчета цены.

Реализованы:

- создание `pending` заявки клиентом или сервисом;
- ручное создание `pending` или `approved` брони администратором;
- подтверждение, начало и завершение аренды;
- отмена администратором и клиентом;
- автоматическое истечение `pending` через 24 часа;
- `no_show` только для подтвержденной брони после времени начала;
- изменение дат без смены техники, с повторной проверкой доступности и новой версией цены;
- служебная блокировка техники на ремонт/обслуживание;
- идемпотентное выполнение будущих mutation endpoints;
- история статусов, audit log и transactional notification outbox.

## 2. Исполняемая матрица статусов

Разрешены только переходы:

```text
process  -> pending | cancelled_by_client
pending  -> approved | cancelled | cancelled_by_client | expired
approved -> active | cancelled | cancelled_by_client | no_show
active   -> completed | cancelled
```

Терминальные статусы не имеют исходящих переходов. `active -> cancelled` остается
аварийным административным сценарием. `no_show` из `active` или `completed` запрещен.

Модель `Booking` запрещает:

- прямое изменение `status` через Eloquent;
- прямое создание блокирующей брони вне `BookingService`.

Вне доменного сервиса можно создать только неблокирующий `process`-черновик. Это
не дает контроллеру, импорту или будущему каналу случайно создать `pending` без цены,
occupancy, истории и audit.

## 3. Доступность и конкурентность

Технику блокируют:

- `pending`;
- `approved`;
- `active`;
- maintenance occupancy.

При переходе в `completed`, `cancelled`, `cancelled_by_client`, `expired` или
`no_show` occupancy удаляется в той же транзакции.

Создание брони и перенос дат выполняются в следующем порядке:

1. блокировка строки техники через `SELECT ... FOR UPDATE`;
2. проверка пересечения по включительным датам;
3. расчет цены через `PricingService`;
4. запись брони или дат;
5. создание новой неизменяемой версии price snapshot;
6. синхронизация `vehicle_occupancies`;
7. запись истории, audit и notification intent;
8. единый commit.

PostgreSQL exclusion constraint остается последней защитой от гонки. Нарушение
`23P01` переводится в стабильную ошибку `vehicle_unavailable` с HTTP status `409`.
Отдельный тест запускает два PHP-процесса одновременно: результатом являются ровно
одна бронь (`201`) и один конфликт (`409`).

## 4. Цена при изменении дат

При изменении дат:

- техника внутри брони не меняется;
- собственный occupancy исключается из проверки, остальные брони и maintenance учитываются;
- цена полностью пересчитывается по актуальному каталогу;
- создается следующая automatic snapshot version;
- старая версия и возможный старый manual override остаются в истории;
- новый manual override при необходимости выполняется администратором отдельно;
- изменение дат и notification intent фиксируются в audit/outbox.

## 5. Правила времени

Бизнес-таймзона: `Asia/Bangkok`. Системные timestamps остаются UTC.

- `pending_expires_at` задается при создании заявки как `created_at + 24 часа`;
- scheduler каждые 10 минут выполняет `bookings:expire-pending`;
- повторный запуск команды безопасен и не дублирует событие;
- клиент может отменить `approved` ровно за 24 часа или раньше;
- менее чем за 24 часа возвращается `client_cancellation_requires_manager`;
- в context ошибки передается runtime-настройка `MANAGER_TELEGRAM_USERNAME`;
- `no_show` разрешен только после даты и времени выдачи; при отсутствии времени используется начало дня.

## 6. История, audit и outbox

Каждый переход создает:

- неизменяемую запись `booking_status_history` с actor, причиной, context и request ID;
- `audit_logs` со старым и новым статусом;
- notification intent, если событие требует уведомления.

На текущем этапе outbox только надежно сохраняет намерения. Фактическая доставка,
retry/backoff и тексты сообщений относятся к этапу уведомлений.

Создаются события:

- клиенту: `booking.pending`, `booking.approved`, `booking.cancelled`,
  `booking.expired`, `booking.dates_changed`;
- администратору: новая `booking.pending` и `booking.cancelled_by_client`.

Outbox использует уникальный deduplication key, привязанный к версии события.

## 7. Идемпотентность

`IdempotencyService` предназначен для mutation endpoints бота и будущего сайта.

- request payload канонизируется и хешируется SHA-256;
- одинаковые key + payload возвращают сохраненный response без повторного действия;
- тот же key с другим payload возвращает `idempotency_key_reused` (`409`);
- незавершенная операция возвращает `idempotency_in_progress` (`409`);
- business mutation и запись completed response находятся в одной DB transaction;
- при исключении операция и idempotency key откатываются, после чего запрос можно повторить.

HTTP middleware будет подключать этот сервис к конкретным маршрутам на этапе API.

## 8. Основные business error codes

| Code | HTTP | Значение |
|---|---:|---|
| `vehicle_unavailable` | 409 | даты пересекаются с бронью или maintenance |
| `invalid_booking_transition` | 409 | переход не разрешен state machine |
| `client_cancellation_requires_manager` | 409 | до выдачи менее 24 часов |
| `booking_not_due_for_expiry` | 409 | заявка еще не должна истечь |
| `no_show_too_early` | 409 | время начала еще не наступило |
| `booking_dates_locked` | 409 | статус не допускает перенос дат |
| `booking_actor_forbidden` | 403 | actor не имеет права на use case |
| `booking_customer_mismatch` | 403 | клиент пытается изменить чужую бронь |
| `customer_not_found` / `vehicle_not_found` | 404 | сущность отсутствует |
| `invalid_rental_period` / `invalid_booking_time` | 422 | некорректные даты или время |
| `booking_reason_required` | 422 | обязательная причина не указана |
| `idempotency_key_reused` | 409 | ключ повторен с другим payload |

Контроллеры должны переводить `BookingException` в единый JSON/Inertia error contract,
сохраняя `errorCode`, `httpStatus` и безопасный `context`.

## 9. Конфигурация и команды

```dotenv
BOOKING_PENDING_TTL_HOURS=24
MANAGER_TELEGRAM_USERNAME=
```

Контакт менеджера намеренно остается runtime-конфигурацией и должен быть заполнен
до подключения клиентской отмены в Telegram.

```powershell
# ручной безопасный запуск auto-expire
php artisan bookings:expire-pending

# проверка расписания
php artisan schedule:list
```

Request ID в истории и audit расширен до `varchar(100)`, поскольку middleware
разрешает UUID и внешние correlation IDs бота, например `bot:update:123`.

## 10. Проверки

- Laravel Pint проходит;
- PHPStan/Larastan level 7 проходит без ошибок;
- unit tests покрывают всю разрешенную и ключевую запрещенную матрицу переходов;
- feature tests покрывают создание, lifecycle, конфликты, отмены, перенос дат,
  snapshots, expiry, no-show, maintenance, историю, audit, outbox и idempotency;
- полный project precheck: 104 Laravel tests, 101 passed и 3 PostgreSQL-only skipped
  в SQLite-профиле;
- PostgreSQL constraint tests проходят;
- отдельный PostgreSQL-набор: 3 tests passed, включая multi-process race `201 + 409`;
- полный project precheck должен выполняться перед каждым следующим этапом.

## 11. Следующий этап

Этап 5: основная web-админка бронирований. Inertia controllers должны вызывать
готовый `BookingService`, `MaintenanceService` и `BookingPriceSnapshotService`.
Переносить state machine, availability queries или формулы цены во Vue запрещено.
