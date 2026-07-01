# Аудит перед крупным апдейтом

Дата: 2026-07-01.

Контекст будущих задач:

- админку, вероятно, нужно будет переработать и вынести из Telegram в web-интерфейс;
- формулы цен могут быть пересмотрены;
- логика пользовательского бронирования в боте в целом остаётся, но может дорабатываться;
- значит, главный риск сейчас - не UI админки сам по себе, а общая доменная логика, которую потом будут использовать и бот, и web-админка.

## Главный вывод

Перед крупным апдейтом нужно стабилизировать ядро:

1. Жизненный цикл бронирования.
2. Правила доступности байков.
3. Расчёт и хранение цены.
4. Админские действия и права.
5. Схему БД и минимальные ограничения целостности.
6. Границу между Telegram-хендлерами и бизнес-логикой.

Сейчас большая часть бизнес-правил живёт прямо в `handlers/`. Для будущей web-админки это неудобно: сайт не сможет переиспользовать Telegram-хендлеры напрямую, и появится риск второй, несовместимой реализации тех же правил.

## P0: исправить до любых крупных изменений

### 1. Зафиксировать модель статусов rental

Проблема:

- Черновик пользователя хранится в `rentals` со статусом `process`.
- Клиентское подтверждение в `handlers/book_confirm.js` переводит `process` сразу в `active`.
- Админка при этом имеет список `pending` и approve/cancel flow.
- Админский approve переводит заявку в `approved`.

Критичные места:

- `handlers/book_confirm.js:18` ищет пользовательские `process`.
- `handlers/book_confirm.js:112` ставит `status: "active"`.
- `handlers/admin_menu.js:5` задаёт списки `active/pending/confirmed`.
- `handlers/admin_menu.js:7` список pending ищет только `pending`.
- `handlers/admin_rental_action.js:134` approve ставит `approved`.

Почему это критично:

- `active` сейчас означает и "заявка отправлена", и потенциально "аренда началась".
- Админская очередь `pending` фактически может быть пустой, хотя клиент уже отправил заявку.
- Напоминания планируются сразу после клиентского подтверждения, до админского approve.
- Для web-админки невозможно построить корректные фильтры и действия без единого status-flow.

Рекомендуемая модель:

```text
draft/process -> pending -> approved -> active -> completed/returned
                         \-> cancelled
              \-> cancelled_by_client
```

Минимальное решение:

- Оставить `process` только для черновика.
- При клиентском подтверждении переводить в `pending`, а не `active`.
- Админский approve переводит `pending -> approved`.
- `active` использовать только для реально начавшейся аренды или пока вообще не использовать.
- Явно описать, какие статусы блокируют байк.
- Перенести создание reminders на момент `approved`, если напоминания должны идти только по подтверждённым арендам.

### 2. Разделить черновики и реальные блокировки доступности

Проблема:

- `process`-заявки лежат в `rentals`.
- Проверка доступности считает занятыми все статусы, кроме `cancelled` и `cancelled_by_client`.
- Если пользователь добавил байк в черновик и бросил сценарий, такой `process` может блокировать байк бесконечно.

Критичные места:

- `utils/overlap.js:37` исключает только `cancelled/cancelled_by_client`.
- `handlers/book_show_available_bikes.js:26` так же исключает только отменённые.
- `handlers/book_select_category.js:28` так же исключает только отменённые.
- `utils/getBusyDatesForBike.js:7` так же исключает только отменённые.
- `handlers/book_reset.js:15` удаляет `process`, но только если пользователь явно сбросил бронь.

Почему это критично:

- Перед web-админкой появятся новые источники создания/редактирования заявок.
- Без явного правила hold/TTL можно получить "невидимые" блокировки склада.

Рекомендуемое решение:

- Ввести понятие `blocking statuses`, например `pending`, `approved`, `active`, `ready`.
- Решить, блокирует ли `process`.
- Если `process` должен временно держать байк, добавить `expires_at` и регулярную очистку.
- Если `process` не должен держать байк, исключить его из availability-проверок.
- Лучше ввести helper вроде `getBlockingRentalStatuses()` и использовать его везде.

### 3. Исправить price TBD и неполные цены

Проблема:

- У активного байка `ADV 160cc, ABS, Black, 2022` нет строк `bike_prices`.
- UI умеет показать `booking_price_tbd`.
- Но добавление аренды требует truthy `booking.totalPrice`, поэтому такую заявку нельзя корректно добавить.

Критичные места:

- `handlers/book_select_bike.js:134` ищет price row.
- `handlers/book_select_bike.js:142` пишет `booking.totalPrice`.
- `handlers/book_select_bike.js:144` пишет `booking.priceUnknown`.
- `handlers/book_add_rental.js:17` отклоняет сценарий без `booking.totalPrice`.
- `handlers/book_add_rental.js:65` сохраняет `total_price`.

Почему это критично:

- При переработке формул цен часть цен может временно быть "по запросу".
- Нельзя допускать, чтобы клиент дошёл до summary, увидел TBD, но не смог добавить заявку.

Варианты решения:

- Консервативный: запретить выбор активных байков без полного набора цен.
- Гибкий: разрешить `priceUnknown`, сохранять `total_price = null`, а админу давать поле ручного расчёта.
- Для будущей web-админки лучше второй вариант, но нужно явно показать оператору, что цена не рассчитана.

Обязательная проверка перед релизом:

```text
каждый active bike должен иметь 15 price rows
```

или:

```text
active bike без цен разрешён только если включён режим manual quote
```

### 4. Закрыть права на админские действия

Проблема:

- `commands/admin.js`, `admin_menu.js`, `admin_bikes.js` проверяют `users.is_admin`.
- `handlers/admin_rental_action.js` напрямую выполняет approve/cancel и не проверяет `is_admin`.

Критичные места:

- `handlers/admin_rental_action.js:96` экспортирует обработчик callback.
- `handlers/admin_rental_action.js:105` начинает cancel flow.
- `handlers/admin_rental_action.js:133` начинает approve flow.
- В файле нет проверки `users.is_admin`.

Почему это критично:

- Даже если Telegram callback обычно приходит из кнопки, бизнес-действие approve/cancel не должно зависеть от UI.
- Для web-админки это особенно важно: права должны жить на уровне сервиса/API, а не на уровне кнопки.

Минимальное решение:

- Добавить `ensureAdmin` в `admin_rental_action.js`.
- Проверять допустимый статус перед approve/cancel.
- Логировать, кто выполнил действие.

Лучшее решение:

- Вынести `approveRental(adminUserId, rentalId)` и `cancelRental(adminUserId, rentalId, reason)` в общий сервис.
- И Telegram-админка, и будущий сайт вызывают этот сервис.

### 5. Убрать гонки в проверке доступности

Проблема:

- Есть проверка overlap до вставки и внутри transaction.
- Но в БД нет ограничения, которое гарантирует отсутствие пересечений при двух параллельных бронированиях.

Критичные места:

- `handlers/book_add_rental.js:46` вызывает `hasOverlap`.
- `handlers/book_confirm.js:90` повторно проверяет overlap.
- `utils/overlap.js:6` строит условие пересечения.

Почему это критично:

- После появления web-админки и большего числа операций вероятность параллельных изменений выше.
- Без DB-level защиты возможны двойные бронирования при гонке.

Минимальное решение:

- На время операции брать advisory lock по `bike_id` или row lock там, где возможно.
- Централизовать создание/подтверждение аренды в одном сервисе.

Более сильное решение:

- Для PostgreSQL рассмотреть range/exclusion constraint на период аренды и bike_id.
- Перед этим нужно нормализовать datetime-поля и статусы.

## P1: исправить перед выносом админки в web

### 6. Вынести бизнес-логику из Telegram-хендлеров

Сейчас handlers одновременно:

- читают Telegram context;
- проверяют пользователя;
- считают цену;
- проверяют доступность;
- пишут в БД;
- отправляют уведомления;
- меняют статусы.

Это заблокирует аккуратный web-админ:

- сайт не должен импортировать Telegram handlers;
- иначе будет дублирование правил в API;
- дублирование почти гарантированно сломает доступность, статусы или цены.

Целевая структура:

```text
domain/
  rentals.js
  pricing.js
  availability.js
  users.js
  adminRentals.js

repositories/
  rentalsRepository.js
  bikesRepository.js
  usersRepository.js

adapters/
  telegram/
  web/
```

Первый практический шаг:

- Не переписывать всё сразу.
- Вынести только 3 функции:
  - `calculateRentalQuote(input)`
  - `createDraftRental(userId, bookingInput)`
  - `submitRentalDraft(userId)`
  - `approveRental(adminUserId, rentalId)`

### 7. Сделать pricing модель версионируемой

Проблема:

- Формулы зашиты в `utils/pricingProfiles.js`.
- В БД хранится только `bike_prices.price_per_day`.
- Не сохраняется, по какой формуле и с какими коэффициентами была рассчитана цена.

Критичные места:

- `utils/pricingProfiles.js:19` содержит profiles.
- `utils/pricingProfiles.js:91` рассчитывает строки цен.
- `handlers/book_select_bike.js:119` выбирает `daysType`.
- `handlers/book_select_bike.js:134` берёт price row.

Почему это важно:

- Если формулы будут переработаны, старые бронирования должны сохранить старую цену.
- Админу нужно понимать, цена ручная, автоматическая, старая или пересчитанная.

Рекомендация:

- Ввести `price_quote` snapshot в `rentals.meta` или отдельные поля:
  - `price_source`: `auto/manual/tbd`
  - `pricing_version`
  - `season_id`
  - `days_type`
  - `price_per_day`
  - `days_count`
  - `total_price`
  - `currency`
- Для новой web-админки дать возможность ручной корректировки с причиной.

### 8. Добавить ограничения целостности в БД

Сейчас схема довольно мягкая:

- `rentals.status` - свободная строка.
- `booking_public_id` не unique.
- `bike_prices` не имеет unique на `(bike_id, season_id, days_type)`.
- многие FK nullable.

Критичные места:

- `migrations/007_create_rentals.js:10` создаёт свободный `status`.
- `migrations/013_extend_rentals_for_wizard.js:9` добавляет `booking_public_id` без unique.
- `utils/bookingId.js:5` генерирует короткую random-часть через `Math.random`.
- `migrations/006_create_bike_prices.js:5` нет unique для price rows.

Рекомендуемые миграции:

- unique index на `rentals.booking_public_id`, если поле заполнено;
- unique index на `bike_prices(bike_id, season_id, days_type)`;
- check constraint для известных statuses;
- индексы на `rentals(bike_id, status, start_date, end_date)`;
- индексы на `rentals(user_id, status)`;
- опционально `created_by`, `updated_by`, `cancel_reason`, `approved_at`.

### 9. Привести admin config к одному источнику

Проблема:

- В `.env` есть `ADMIN_IDS`.
- Код его не использует.
- Админ-доступ хранится в `users.is_admin`.

Решение:

- Или удалить `ADMIN_IDS` из документации/env.
- Или добавить bootstrap: если telegram id входит в `ADMIN_IDS`, пользователь автоматически становится admin.

Для web-админки лучше:

- оставить `users.is_admin`;
- позже добавить роли/permissions;
- `ADMIN_IDS` использовать только как bootstrap для первого админа.

### 10. Удалить устаревший calendar handler

Проблема:

- В `bot.js` зарегистрированы два handler на `book:select_date`.
- Более общий `^book:select_date:` стоит раньше и покрывает конкретный `^book:select_date:\d{4}-\d{2}-\d{2}$`.

Критичные места:

- `bot.js:29` регистрирует `book_select_date`.
- `bot.js:59` регистрирует `calendar_handler`.

Решение:

- Проверить, что `calendar_handler.js` не нужен.
- Удалить регистрацию и файл либо оставить комментарий, если это legacy fallback.

### 11. Убрать прямой `node-fetch` без зависимости

Проблема:

- `services/fileService.js` импортирует `node-fetch`.
- В `package.json` нет прямой зависимости.
- Сейчас это работает только из-за транзитивных зависимостей.

Критичные места:

- `services/fileService.js:3`
- `services/fileService.js:15`

Решение:

- Node 22 имеет global `fetch`: можно убрать импорт.
- Или добавить `node-fetch` явно.

Лучше для текущего проекта:

- убрать `require("node-fetch")` и использовать global `fetch`;
- это уменьшает зависимость от транзитивных пакетов.

### 12. Привести Google Sheets sync к новой модели статусов

Проблема:

- Google Sheets строит календарь по `start_date/end_date`.
- Цвета завязаны на текущие статусы.
- После исправления status-flow таблица должна отражать `pending/approved/active` корректно.

Критичные места:

- `utils/googleSheetsCalendar.js:344` buildStatusGrid.
- `utils/googleSheetsCalendar.js:352` выбирает rentals.
- `utils/googleSheetsCalendar.js:353` берёт только `start_date/end_date/status`.

Решение:

- После нормализации статусов обновить `STATUS_COLORS` и `STATUS_PRIORITY`.
- Решить, показывать ли `pending` в календаре как hold.
- Если важны частичные дни, добавить отображение времени хотя бы в notes/comment.

## P2: желательно до активной разработки

### 13. Добавить минимальные автоматические проверки

Сейчас в `package.json` нет test script.

Минимум перед крупным апдейтом:

- smoke-test загрузки модулей;
- проверка полноты переводов;
- проверка полноты цен для активных байков;
- unit-тест `pricingProfiles.calculatePriceRows`;
- unit/integration-тест `overlap`;
- тест status transitions.

Начать можно без тяжёлого фреймворка:

```powershell
node --test
```

или добавить `vitest`, если появится больше логики.

### 14. Зафиксировать документацию в Git

Сейчас:

- `README.md` untracked;
- `PROJECT_STATE.md` untracked;
- `PRE_UPDATE_AUDIT.md` новый audit-файл.

Решение:

- Добавить docs в Git после согласования.
- README оставить как quickstart.
- `PROJECT_STATE.md` оставить как snapshot.
- `PRE_UPDATE_AUDIT.md` использовать как чеклист стабилизации.

### 15. Подготовить seed/data strategy

Сейчас:

- `bd.sql` - дамп базы со стартовыми данными и миграциями;
- папки `seeds` нет;
- `knex seed:run` не работает.

Перед web-админкой лучше:

- отделить schema migrations от seed data;
- сделать seed для категорий/сезонов/тестовых байков;
- боевые данные не хранить как единственный дамп восстановления.

## Рекомендуемый порядок работ

### Шаг 1. Стабилизационный патч

Цель: не менять UX бота радикально, но сделать правила однозначными.

Состав:

1. Ввести единый список статусов и blocking statuses.
2. Исправить `book_confirm`: `process -> pending`.
3. Исправить админский approve/cancel с проверкой admin и допустимого статуса.
4. Перенести reminders на approved или явно оставить на pending, если так решит бизнес.
5. Исправить active bike без цен: либо заполнить цены, либо разрешить `priceUnknown`.
6. Убрать `node-fetch` как транзитивную зависимость.
7. Удалить/отключить legacy `calendar_handler`.

### Шаг 2. Минимальные тесты и проверки данных

Состав:

1. `npm run check` для syntax/smoke/data checks.
2. Проверка переводов.
3. Проверка активных байков без цен.
4. Проверка допустимых статусов в базе.
5. Тесты для pricing и overlap.

### Шаг 3. Сервисный слой для будущей web-админки

Состав:

1. Вынести rental transitions в `services`/`domain`.
2. Вынести pricing quote.
3. Вынести availability.
4. Telegram handlers оставить тонкими адаптерами.

После этого web-админку можно делать без переписывания правил бронирования.

### Шаг 4. Web-админка

Начинать только после шагов 1-3.

Минимальная первая версия сайта:

- авторизация админа;
- список pending/approved/active;
- карточка заявки;
- approve/cancel/manual price/deposit note;
- управление байками и ценами.

Важно: web-админка должна работать через общий сервисный слой, а не напрямую повторять SQL из Telegram handlers.

## Что не трогать до стабилизации

- Не менять кардинально пользовательский booking flow.
- Не переносить админку в сайт до фикса статусов.
- Не переписывать pricing UI, пока нет решения по formula versioning.
- Не менять `bd.sql` вручную как замену миграциям.
- Не добавлять второй источник прав администратора без ясной модели ролей.

## Definition of Done перед крупным апдейтом

Перед стартом web-админки состояние должно быть таким:

- Есть единая таблица статусов и разрешённых переходов.
- `pending` реально появляется после клиентского подтверждения.
- Админские approve/cancel защищены проверкой прав.
- Все availability-проверки используют один список blocking statuses.
- Нет активных байков без цены, либо есть явный режим manual/TBD quote.
- `booking_public_id` уникален.
- `bike_prices` не допускает дублей `(bike_id, season_id, days_type)`.
- Есть команда проверки проекта.
- Документация добавлена в Git.
- Telegram handlers больше не являются единственным местом бизнес-логики для новых admin actions.

