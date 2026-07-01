# Аудит route аренды под нагрузкой

Дата: 2026-07-01.

Задача: проверить полный маршрут аренды байков в Telegram-боте с учётом будущей нагрузки, быстрых повторных кликов, параллельных операций, будущей web-админки и возможной переработки цен.

## Проверенный маршрут

Основной путь:

```text
/book или menu rent
-> commands/book.js
-> handlers/book_action.js
-> handlers/book_select_date.js или handlers/book_select_category.js
-> handlers/book_select_bike.js
-> handlers/book_options.js
-> handlers/book_add_rental.js
-> handlers/booking_draft_menu.js
-> handlers/conditions.js
-> handlers/book_confirm.js
-> handlers/admin_rental_action.js
-> handlers/rent_menu.js / rent_details.js / rent_cancel.js
```

Вспомогательные части:

- `middlewares/sessionStorage.js` - хранение `ctx.session` в БД.
- `utils/overlap.js` - проверка пересечений.
- `utils/getBusyDatesForBike.js` - блокировка дат в календаре.
- `utils/timeSlots.js` - сборка datetime из даты и времени.
- `utils/pricingProfiles.js` - формулы цен.
- `utils/reminders.js` - напоминания.
- `utils/googleSheetsCalendar.js` - календарь для операторов.

## Текущее состояние данных

По локальной базе на момент проверки:

- duplicate `booking_public_id`: 0
- duplicate price keys `(bike_id, season_id, days_type)`: 0
- `process` rentals: 0
- non-cancelled rentals: 3

Это значит, что текущие данные не испорчены. Но чистота данных сейчас обеспечивается кодом и удачным состоянием, а не достаточными DB-ограничениями.

## P0: критичные риски route аренды

### 1. Date-only бронирования не конфликтуют с timed rentals

Проблема:

- `utils/overlap.js` проверяет datetime-overlap только если у новой заявки есть `startAt` и `endAt`.
- Если пользователь выбирает только даты без времени, `startAt/endAt` равны `null`.
- В этом случае проверка смотрит только legacy-записи, где у старой аренды `start_at/end_at` тоже `null`.
- Уже существующая аренда с точным временем может не попасть в conflict check.

Критичные места:

- `utils/overlap.js:8` ветка datetime включается только при `startAt && endAt`.
- `utils/overlap.js:17` legacy-ветка фильтрует старые строки через `whereNull("start_at").whereNull("end_at")`.
- `handlers/book_show_available_bikes.js:24` использует этот overlap для списка доступных байков.
- `handlers/book_add_rental.js:46` использует этот overlap перед insert.
- `handlers/book_confirm.js:90` использует этот overlap перед подтверждением.

Риск:

- Можно создать date-only заявку поверх уже занятого timed interval.
- Под нагрузкой это даст реальные двойные бронирования.

План фикса:

1. Ввести нормализованный период аренды:
   - если время выбрано: `start_at/end_at`;
   - если время не выбрано: интервал на весь день/период в `BOOKING_TZ`.
2. Переписать `hasOverlap` так, чтобы он всегда сравнивал эффективные интервалы.
3. Решить семантику границ:
   - либо `[start, end)` и back-to-back разрешён;
   - либо inclusive, если байк нельзя выдать в момент возврата.
4. Добавить тесты:
   - date-only new vs timed existing;
   - timed new vs date-only existing;
   - back-to-back intervals;
   - multi-day intervals.

### 2. Статус `active` используется вместо `pending`

Проблема:

- Пользовательское подтверждение переводит `process` сразу в `active`.
- Админка ожидает `pending` и потом ставит `approved`.

Критичные места:

- `handlers/book_confirm.js:112` ставит `status: "active"`.
- `handlers/admin_menu.js:7` список pending ищет `pending`.
- `handlers/admin_rental_action.js:134` approve ставит `approved`.

Риск:

- Админская очередь и реальное состояние аренды расходятся.
- Сайт-админка унаследует неверную модель.
- Напоминания планируются до админского подтверждения.

План фикса:

1. Ввести constants/service для статусов:
   - `process` или `draft`;
   - `pending`;
   - `approved`;
   - `active`;
   - `completed` или `returned`;
   - `cancelled`;
   - `cancelled_by_client`.
2. `book_confirm`: переводить `process -> pending`.
3. `admin approve`: переводить только `pending -> approved`.
4. `active` использовать только после фактического начала аренды или пока не использовать.
5. Обновить тексты, списки, Google Sheets colors, reminders.

### 3. Повторный confirm может дать дубли уведомлений и побочные эффекты

Проблема:

- `book_confirm.js` сначала читает `process` rentals, потом внутри transaction обновляет все `process`.
- Если пользователь быстро нажмёт confirm несколько раз или будет несколько процессов, второй обработчик может работать со старым набором rentals.
- Уведомления админу и reminders идут после transaction, но не привязаны к фактически обновлённым строкам.

Критичные места:

- `handlers/book_confirm.js:18` читает process rentals.
- `handlers/book_confirm.js:82` повторно читает process rentals.
- `handlers/book_confirm.js:110` обновляет все process rentals.
- `handlers/book_confirm.js:172` отправляет админу сообщение.
- `handlers/book_confirm.js:191` планирует reminders.

Риск:

- Дубли уведомлений админу.
- Дубли или перезапись reminders.
- Пользователь может получить ошибку редактирования уже изменённого сообщения.
- При масштабировании на несколько процессов риск выше.

План фикса:

1. Сделать confirm идемпотентным.
2. В transaction обновлять только строки текущего draft и получать `returning`.
3. Отправлять уведомления только для строк, реально переведённых `process -> pending`.
4. Старый повторный confirm должен возвращать "заявка уже отправлена", а не повторять side effects.
5. Добавить lock на confirm:
   - минимум: advisory lock по `user_id`;
   - лучше: отдельная сущность booking/draft group.

### 4. Add rental не защищён от гонки двойного клика

Проблема:

- Перед insert проверяется `existingRental`.
- Проверка находится вне transaction.
- Нет unique constraint, который гарантирует отсутствие дубля.

Критичные места:

- `handlers/book_add_rental.js:36` ищет existing process rental.
- `handlers/book_add_rental.js:45` начинает transaction.
- `handlers/book_add_rental.js:58` делает insert.

Риск:

- Быстрый двойной клик может создать две draft-записи.
- При нескольких процессах/инстансах риск ещё выше.

План фикса:

1. Перенести проверку existing rental внутрь transaction.
2. Добавить per-user lock или advisory lock на `user_id`.
3. Сделать action идемпотентным:
   - если такая draft item уже есть, вернуть текущий draft menu;
   - не создавать новую строку;
   - не молча игнорировать изменение параметров.
4. Рассмотреть partial unique index:
   - `(user_id, bike_id, status)` where `status = 'process'`;
   - если бизнес не разрешает один и тот же байк дважды в одном draft.

### 5. Админский approve/cancel не проверяет права внутри обработчика

Проблема:

- `admin_menu` и `admin_bikes` проверяют `is_admin`.
- `admin_rental_action.js` выполняет approve/cancel без собственной проверки.

Критичные места:

- `handlers/admin_rental_action.js:96` основной handler.
- `handlers/admin_rental_action.js:105` cancel branch.
- `handlers/admin_rental_action.js:133` approve branch.

Риск:

- Действие защищено UI-контекстом, а не самим business action.
- Для будущего web/API это недопустимо.

План фикса:

1. Добавить `ensureAdmin` прямо в `admin_rental_action.js`.
2. Проверять статус перед переходом:
   - approve только из `pending`;
   - cancel только из допустимых активных статусов.
3. Старый callback по уже обработанной заявке должен быть no-op с понятным сообщением.
4. Позже вынести в `adminRentalService`.

### 6. Пользовательские данные вставляются в HTML без escaping

Проблема:

- Сообщения отправляются с `parse_mode: "HTML"`.
- В HTML попадают имя, username, телефон, комментарий, адрес, название байка, описание.
- Нет общего `escapeHtml`.

Критичные места:

- `handlers/book_confirm.js` строит admin notification.
- `handlers/booking_draft_menu.js` выводит comment/address.
- `handlers/rent_menu.js` выводит детали аренды.
- `handlers/rent_details.js` выводит детали.
- `handlers/support.js`, `conditions.js`, `account_menu.js` тоже используют HTML.

Риск:

- Пользовательский ввод с `<`, `>`, `&` может ломать отправку Telegram HTML.
- Под нагрузкой это превращается в нестабильность route.
- Возможна HTML-инъекция в админских сообщениях.

План фикса:

1. Добавить `utils/html.js`:
   - `escapeHtml(value)`;
   - `formatHtmlMessage(template, vars)`.
2. Все пользовательские/DB значения перед parse_mode HTML экранировать.
3. Ограничить длину комментария, адреса, имени, паспорта.
4. Добавить тесты на спецсимволы.

### 7. Timezone для выбранного времени игнорирует `BOOKING_TZ`

Проблема:

- `utils/timeSlots.js` делает `dayjs(`${date}T${time}:00`)`.
- Это использует timezone окружения сервера.
- Reminders используют `BOOKING_TZ`, но start/end создаются без него.

Критичные места:

- `utils/timeSlots.js:22`
- `utils/reminders.js:8`

Риск:

- Если сервер работает не в `Asia/Bangkok`, время выдачи/возврата и reminders смещаются.
- В текущем окружении timezone `Europe/Moscow`, а бизнес-таймзона в документации `Asia/Bangkok`.

План фикса:

1. Переписать `makeDateTime(dateStr, timeStr)` через `dayjs.tz(..., BOOKING_TZ)`.
2. Все date/time label форматировать через тот же timezone.
3. Проверить сохранение в PostgreSQL `timestamptz`.
4. Добавить тесты на Bangkok vs server timezone.

## P1: важные риски, которые лучше закрыть до web-админки

### 8. `process`-черновики могут блокировать доступность

Проблема:

- Все статусы, кроме cancelled/cancelled_by_client, считаются блокирующими.
- `process` живёт в `rentals`.
- Если пользователь бросил черновик, он может блокировать байк.

План фикса:

1. Ввести единый `BLOCKING_RENTAL_STATUSES`.
2. Решить, блокирует ли `process`.
3. Если блокирует, добавить `expires_at` и очистку.
4. Если не блокирует, исключить `process` из availability.

Рекомендация:

- Для простого бота лучше не блокировать `process` или блокировать с коротким TTL.
- Блокировать точно должны `pending`, `approved`, `active`, возможно `ready`.

### 9. Options для draft меняют время без полной переоценки заявки

Проблема:

- `book_options.persistProcessOptions` обновляет `start_at/end_at` у всех process rentals.
- Цена не пересчитывается.
- Overlap не проверяется в момент изменения options.
- Обновления идут по одной строке, не transaction.

Критичные места:

- `handlers/book_options.js:60`
- `handlers/book_options.js:77`
- `handlers/book_options.js:90`

План фикса:

1. Сделать `updateDraftOptions(userId, options)` в service.
2. Внутри transaction обновлять все draft items.
3. После изменения времени пересчитывать availability и quote.
4. Если появился conflict, не сохранять изменение или показать список конфликтов.

### 10. Старые callback-кнопки могут менять уже изменённую заявку

Проблема:

- Callback из старого сообщения может прийти после изменения статуса.
- `rent_cancel.js` на `cancel_confirm` не повторяет статусную проверку.
- `admin_rental_action.js` approve/cancel не проверяет текущий статус.

План фикса:

1. Все state-changing callback handlers должны делать conditional update:
   - `where id = ? and status in (...)`.
2. Если `updatedRows = 0`, отвечать "заявка уже изменена".
3. Удалять/обновлять inline keyboard после успешного действия.
4. Всегда `answerCallbackQuery`.

### 11. No availability lead может спамить админа

Проблема:

- Если свободных байков нет, создаётся `no_availability_requests`.
- Нет dedupe/rate limit.
- Повторные клики/перезаходы создают новые leads и сообщения админу.

Критичные места:

- `handlers/book_show_available_bikes.js:38`
- `handlers/book_show_available_bikes.js:49`
- `handlers/book_show_available_bikes.js:65`

План фикса:

1. Добавить dedupe key: `user_id + start_date + end_date + category_id`.
2. Добавить cooldown, например 15 минут.
3. Добавить unique/partial index или application-level check.

### 12. Уведомления и БД-изменения не имеют outbox

Проблема:

- Статус меняется в БД.
- Потом отправляется Telegram notification.
- Если Telegram отправка падает, БД уже изменилась.
- Если обработчик повторится, уведомления могут дублироваться.

План фикса:

1. Для P0 минимум: отправлять уведомления только по строкам, реально изменённым transition update.
2. Для web-админки: добавить `notifications_outbox`.
3. Worker отправляет уведомления идемпотентно.
4. Хранить `sent_at`, `attempts`, `last_error`.

### 13. Reminders не защищены от multi-instance duplicates

Проблема:

- Scheduler запускается в каждом процессе.
- `sendDueReminders` выбирает все unsent reminders, отправляет, потом ставит `sent=true`.
- Два процесса могут выбрать одну и ту же строку.

Критичные места:

- `bot.js` запускает `setInterval`.
- `utils/reminders.js:62` выбирает due reminders.
- `utils/reminders.js:95` ставит `sent=true` после отправки.

План фикса:

1. Если будет один процесс - документировать это ограничение.
2. Для масштабирования:
   - atomic claim через `UPDATE ... WHERE sent=false ... RETURNING`;
   - или `SELECT ... FOR UPDATE SKIP LOCKED`;
   - добавить `processing_at`.
3. Не запускать scheduler в web-процессе админки.

### 14. Delete/back flow draft menu использует не тот callback

Проблема:

- В `book_remove_bike.js` кнопка "назад" ведёт в `book:add_rental`.
- `book:add_rental` не просто показывает draft, а пытается добавить текущий selected bike из session.
- Если session уже не содержит корректный booking, пользователь получает ошибку "not enough data".

Критичные места:

- `handlers/book_remove_bike.js:34`
- `handlers/book_add_rental.js:13`

План фикса:

1. Добавить отдельный callback `book:draft`.
2. Он только вызывает `buildDraftMenuPayload`.
3. Все "назад к текущей заявке" вести туда, а не в `book:add_rental`.

### 15. Нет DB-level гарантий для route аренды

Проблема:

- `booking_public_id` не unique.
- `bike_prices(bike_id, season_id, days_type)` не unique.
- `rentals.status` без check constraint.
- Нет индексов под availability query.

План фикса:

1. Миграция:
   - unique partial index на `rentals(booking_public_id)` where not null;
   - unique index на `bike_prices(bike_id, season_id, days_type)`;
   - index `rentals(bike_id, status, start_date, end_date)`;
   - index `rentals(user_id, status)`;
   - check constraint на status.
2. После нормализации datetime рассмотреть exclusion constraint для PostgreSQL.

## P2: эксплуатационные риски

### 16. Нет автоматических тестов route аренды

Нужно добавить минимум:

- pricing bands and rounding;
- overlap matrix;
- date-only vs timed overlap;
- process -> pending -> approved transitions;
- duplicate add/confirm idempotency;
- HTML escaping;
- active bike без price rows.

### 17. Нет отдельной проверки данных перед запуском

Нужна команда `npm run check:data`, которая проверяет:

- активные байки без 15 price rows;
- неизвестные statuses;
- duplicate booking ids;
- duplicate bike price keys;
- reminders на несуществующие rentals;
- pending/approved rentals без `booking_public_id`.

### 18. Telegram handlers слишком толстые

Для будущей web-админки нужно вынести:

- `availabilityService`;
- `pricingService`;
- `draftRentalService`;
- `rentalTransitionService`;
- `notificationService`;
- `adminRentalService`.

Telegram должен только:

- читать callback/message;
- вызывать service;
- форматировать ответ.

Web-админка должна вызывать те же service functions.

## Полный план фикса

### Этап 0. Зафиксировать правила

Перед кодом принять решения:

1. Блокирует ли `process` доступность.
2. Какой точный lifecycle:
   - `process -> pending -> approved -> active -> completed`.
3. Когда отправлять reminders:
   - на `pending` или только на `approved`.
4. Как считать границы времени:
   - inclusive или `[start, end)`.
5. Что делать с байками без цены:
   - запретить или разрешить manual quote.

### Этап 1. Срочный стабилизационный патч

Состав:

1. Добавить `utils/rentalStatus.js`:
   - statuses;
   - `BLOCKING_RENTAL_STATUSES`;
   - allowed transitions.
2. Исправить `book_confirm`: `process -> pending`.
3. Добавить auth/status checks в `admin_rental_action.js`.
4. Перенести reminders на нужный status transition.
5. Исправить overlap для date-only vs timed.
6. Исправить timezone в `timeSlots.js`.
7. Добавить `book:draft` callback.
8. Добавить `escapeHtml`.
9. Исправить `priceUnknown` или закрыть missing prices.

### Этап 2. Идемпотентность и нагрузка

Состав:

1. Per-user advisory lock для операций:
   - add rental;
   - update draft options;
   - confirm draft;
   - reset draft.
2. Conditional updates для admin/user actions.
3. Side effects только после successful transition.
4. No availability dedupe/cooldown.
5. Atomic reminders claim.

### Этап 3. Миграции целостности

Состав:

1. Unique partial `booking_public_id`.
2. Unique `bike_prices(bike_id, season_id, days_type)`.
3. Индексы availability/user status.
4. Check constraint statuses.
5. Поля transition audit:
   - `approved_at`;
   - `cancelled_at`;
   - `cancel_reason`;
   - `updated_at default now`;
   - возможно `created_by/updated_by`.

### Этап 4. Тесты и check scripts

Состав:

1. `node --test` или `vitest`.
2. `npm run check`.
3. `npm run check:data`.
4. Тесты:
   - overlap;
   - pricing;
   - status transitions;
   - idempotent confirm;
   - HTML escaping.

### Этап 5. Подготовка к web-админке

Состав:

1. Вынести services:
   - `services/availabilityService.js`;
   - `services/pricingService.js`;
   - `services/rentalDraftService.js`;
   - `services/rentalTransitionService.js`;
   - `services/adminRentalService.js`.
2. Telegram handlers перевести на эти services.
3. Web API строить поверх тех же services.
4. Scheduler оставить отдельным worker-процессом, не внутри web-админки.

## Рекомендуемый первый набор исправлений

Если идти максимально прагматично, первая пачка должна быть такой:

1. `rentalStatus.js` + status-flow `process -> pending -> approved`.
2. `overlap.js` rewrite с эффективными интервалами и timezone.
3. `admin_rental_action.js` auth + conditional transitions.
4. `book_confirm.js` idempotent transition + notifications only for changed rows.
5. `book_add_rental.js` transaction/idempotency.
6. `escapeHtml` для всех HTML-сообщений route аренды.
7. `check:data` для цен и статусов.

После этого уже безопаснее начинать вынос админки и переработку pricing.

