# Текущее состояние проекта

## Актуализация от 2026-07-22

После обсуждения новых задач с заказчиком выбран новый целевой вектор разработки:
первый production-релиз на Laravel backend с web-админкой, при сохранении текущего
Telegram-бота как клиентского интерфейса.

Главное решение:

- Laravel становится основным backend и владельцем бизнес-логики бронирований.
- Node.js Telegram-бот остается отдельным процессом, но работает через Laravel API.
- Web-админка заменяет Excel как основной рабочий инструмент заказчика.
- Система сразу проектируется под скутеры и машины.
- Web-админка реализуется внутри Laravel на Vue 3 + TypeScript + Inertia.
- Клиент, его Telegram-аккаунт и контакты разделяются в новой модели данных.
- Будущий клиентский сайт будет использовать те же Laravel-сервисы; его разработка
  не входит в текущий релиз, но архитектурная готовность входит.

Текущий статус реализации: этапы 0-9 roadmap закрыты; инженерная и локально проверяемая
часть этапа 10 выполнена 2026-07-22. Этапы 0-8 прошли промежуточный аудит. Созданы Laravel foundation,
целевая доменная схема, импорт техники и цен, транзакционный booking/availability core
и web-админка бронирований с timeline занятости, каталогом техники и категорий,
фотографиями, редактором сезонных тарифов, клиентскими карточками, приватными
документами и CSV-экспортом. Администратор может создать бронь без Telegram,
проверить цену и доступность, выполнить статусные действия, изменить даты или
финальную цену, явно пересчитать цену по актуальным тарифам, вести внутреннюю заметку,
управлять публикацией техники и открыть историю клиента. Реализован
versioned Laravel Bot API, Node.js-бот переведен на него под feature flag, временная
корзина хранится в Redis, а прямой доступ к legacy PostgreSQL в новом режиме запрещен.
Цены, доступность и атомарное создание броней выполняет только Laravel. Реализованы
Redis queue delivery notification outbox, локализованные Telegram-шаблоны,
retry/backoff, autoexpiry pending, reminders, reconciliation, monitoring и
housekeeping. Для production подготовлены strict release preflight, scheduler heartbeat,
security headers, secret scan, Nginx/PHP-FPM/systemd configs, immutable deployment,
backup/verify/restore, smoke/load scripts, rollback и operations runbook.

Dependency risk legacy Google API закрыт обновлением до `googleapis@173`; текущий
`npm audit --omit=dev` возвращает 0 известных уязвимостей. До production cutover
остается внешний acceptance gate этапа 10: реальный сервер/DNS/TLS/SMTP, Telegram bot token,
numeric admin chat ID, реальный контакт менеджера, утвержденная версия условий, production
admin/service client, primary photo, подтверждение финального каталога, online Telegram smoke,
production-like load/rollback rehearsal и staging-приемка заказчиком.

Финальный precheck этапа 10 прошел: Laravel 182 теста / 1159 assertions (5 PostgreSQL-only
ожидаемо skipped в SQLite suite), отдельно
5 PostgreSQL constraint/concurrency tests / 8 assertions, Pint и PHPStan без ошибок,
Vue lint/types/build без ошибок, Node.js 15/15 unit tests, 317 ключей переводов,
Laravel-mode boot и Redis concurrency check. PostgreSQL и Redis отвечают `ok` на
`/health/ready`; notification probe обработан реальным Redis worker. Composer audit
и оба `npm audit --omit=dev` не обнаружили известных
production-уязвимостей. Реальный Telegram smoke будет выполнен на staging после
получения token и admin chat ID.

Локальная репетиция этапа 10 создала и проверила production-format backup, реально восстановила
его в отдельную PostgreSQL БД (29 public tables), подняла изолированный HTTP staging и прошла
Bot API E2E. Гонка 10 клиентов завершилась как `1x201 + 9x409`; после cleanup осталось 0
blocking occupancy и 0 failed jobs. Реальные scheduler heartbeat и Redis queue probe прошли,
error/critical logs отсутствовали. Текущий каталог содержит 29 active visible vehicles с полной
матрицей цен, но все 29 пока без primary photo. Локальный `artisan serve` дал p95 5798 ms и не
является production benchmark; обязательный p95 gate проверяется на Nginx/PHP-FPM.

Актуальные документы по новому направлению:

- `PRODUCTION_LARAVEL_PLAN.md` - целевой production-план и архитектурный вектор.
- `FINAL_TECHNICAL_SPEC.md` - подробное ТЗ для разработки Laravel/admin/bot.
- `PRICING_IMPORT_SPEC.md` - правила импорта техники/цен из Excel и алгоритм расчета.
- `BOOKINGS_ADMIN_SPEC.md` - требования к таблице бронирований, timeline и ручному созданию броней.
- `ARCHITECTURE.md` - утвержденная целевая архитектура, границы компонентов, данные,
  надежность и готовность к будущему клиентскому сайту.
- `IMPLEMENTATION_ROADMAP.md` - порядок реализации, оценки этапов и acceptance gates.
- `DEVELOPMENT_BASELINE.md` - проверенное окружение и acceptance gate этапа 0.
- `STAGE_1_FOUNDATION.md` - фактический результат и acceptance gate этапа 1.
- `STAGE_2_DOMAIN_SCHEMA.md` - целевая схема PostgreSQL и доменные модели.
- `STAGE_3_PRICING_IMPORT.md` - импорт техники, тарифы и расчет цены.
- `STAGE_4_BOOKING_CORE.md` - booking/availability invariants и конкурентность.
- `STAGE_5_BOOKINGS_ADMIN.md` - реализованные административные сценарии бронирований.
- `STAGE_6_TIMELINE.md` - реализованная шахматка занятости и ее acceptance gate.
- `STAGE_7_CATALOG_CUSTOMERS_EXPORT.md` - каталог, фото, тарифы, клиенты, приватные
  документы и CSV-экспорт.
- `STAGE_8_BOT_API.md` - реализованный Bot API, Redis-session, перевод Node.js-бота,
  security/cutover/rollback и acceptance gate.
- `STAGE_9_AUTOMATION_NOTIFICATIONS.md` - delivery outbox, Telegram templates,
  expiry, reminders, retry/backoff, monitoring и operations runbook.
- `STAGE_10_RELEASE_HARDENING.md` - production-контур, security, локальная backup/restore и
  HTTP staging-репетиция, точная граница внешнего acceptance gate.
- `OPERATIONS_RUNBOOK.md` - установка, release, monitoring, queue, backup/restore и rollback.
- `RELEASE_ACCEPTANCE_CHECKLIST.md` - обязательная приемка production-релиза.
- `INTERMEDIATE_AUDIT_2026-07-22.md` - сверка этапов 0-8 с ТЗ, исправленные риски,
  UI-аудит и точная граница работ до production.

Ключевые подтвержденные решения:

- отмена клиентом менее чем за 24 часа не выполняется автоматически, бот просит
  написать менеджеру напрямую;
- pending-заявки автоотменяются через 24 часа с уведомлением клиента;
- `no_show` применяется только к `approved`, не к `active`;
- при изменении дат Laravel автоматически пересчитывает цену, админ может вручную
  скорректировать итог;
- смена техники внутри брони в первый production-релиз не входит;
- экспорт делается в CSV;
- фото и документы в первом релизе хранятся локально на сервере;
- характеристики машин и скутеров хранятся динамически текстом, без жестких полей
  под коробку, места, страховку и залог.
- вся техника из Excel импортируется в БД, после чего ее можно включать/выключать
  из админки;
- админ редактирует итоговые суммы тарифов `1/7/14/21/month`, а backend считает
  дневные ставки и итог;
- технический `pricing_profile` скрыт из формы техники; для первичного заполнения
  цен есть необязательный генератор по шаблону скидок и трем базовым дневным
  ценам high/middle/low;
- генератор рассчитывается только в Laravel: `1 day` равен базовой цене сезона,
  пакеты `7/14/21/month` округляются вниз до 100 бат; генератор заполняет черновик,
  и до сохранения админ может изменить любую ячейку вручную;
- схема скидок запоминается только при сохранении сгенерированной таблицы;
- тарифный уровень выбирается по общей длительности аренды, `30+` дней использует
  месячный тариф;
- если аренда пересекает сезоны, цена считается по дням: каждый день берет сезон
  своей даты и дневную ставку выбранного тарифного уровня;
- итоговая цена округляется до 100 бат.
- старые брони из Excel не импортируются как боевые данные, проект стартует с нуля;
- timeline/шахматка занятости входит в первый production-релиз;
- цвета из Excel переводятся в системные статусы/категории;
- ручное создание брони из web-админки обязательно для клиентов без Telegram;
- в timeline период выбирается двумя кликами в строке техники, после чего ручная
  бронь создается в боковой форме без потери календарного контекста;
- первый релиз делает один тип администратора с полным доступом;
- разработка начинается локально, production будет на сервере позже;
- клиента уведомляем, если админ вручную изменил даты или цену;
- депозит/предоплата в первом релизе хранятся как заметка, без финансового модуля;
- контакт менеджера для поздней отмены остается открытым runtime-настройкой.

Обновленная оценка с учетом работы через AI/Codex GPT-5.5 Extra High:

- сумма этапов разработки: 112-144 часа;
- integration/review reserve: 12-20 часов;
- реалистичный итоговый коридор: 124-164 часа;
- для договоренности с заказчиком: 130-165 часов.

## Исторический legacy baseline

Ниже сохранен аудит старой Node.js-системы на дату 2026-07-01. Числа, риски и
инструкции этого раздела не описывают текущую Laravel-реализацию и нужны только для
rollback/сопоставления поведения.

Дата анализа: 2026-07-01.

Проект: Telegram-бот аренды байков `bike-rent-bot` для бронирования, клиентского кабинета, админской обработки заявок, цен, депозитов, напоминаний и синхронизации календаря в Google Sheets.

## Краткий вывод

Проект рабочий локально: зависимости установлены, JS-синтаксис файлов проекта проходит проверку, локальная PostgreSQL-база доступна, миграции применены до `024_add_vehicle_type_to_bikes.js`, pending-миграций нет.

Перед новыми задачами лучше сначала стабилизировать несколько мест:

1. Исправить рассинхронизацию статусов бронирования: пользовательское подтверждение переводит заявки сразу в `active`, а админка имеет отдельный список `pending`.
2. Заполнить недостающие цены для активного байка `ADV 160cc, ABS, Black, 2022` или разрешить сценарий `price TBD` до конца.
3. Сделать `README.md` отслеживаемым Git-файлом или перенести его в согласованную документацию.
4. Убрать хрупкие места: прямой `require("node-fetch")` без явной зависимости, дублирующий calendar-handler, неиспользуемый `ADMIN_IDS`.
5. Перед изменением бизнес-логики добавить хотя бы минимальные автоматические проверки для pricing/overlap/status-flow.

## Стек и зависимости

Runtime:

- Node.js: локально проверено `v22.20.0`.
- npm: локально проверено `10.9.3`.
- PostgreSQL через `pg` и `knex`.
- Telegram Bot API через `grammy`.
- Google Sheets API через `googleapis`.
- Даты: `dayjs`, частично timezone/utc plugins.

Основные команды из `package.json`:

```powershell
npm start
npm run dev
npm run migrate
```

Проверки, которые прошли:

```powershell
rg --files -g *.js -g !node_modules/** | ForEach-Object { node --check $_ }
npx knex migrate:status
node -e "require('./bot'); console.log('bot require ok')"
```

Примечание: `require('./bot')` успешно загружается, но процесс остаётся живым из-за планировщиков `setInterval`, поэтому для такой проверки нужен явный `process.exit()` или отдельный smoke-скрипт.

`knex seed:run` не является рабочей командой: папки `seeds` нет. Данные восстанавливаются через `bd.sql` плюс миграции.

## Git-состояние

Текущая ветка называется `origin` и отслеживает `origin/origin`.

Последний коммит:

```text
f1fbf88 add admin panel add & del & edit bikes
```

Remote:

```text
https://github.com/DanyWB/driverbot.git
```

Рабочее дерево:

- Изменённых tracked-файлов нет.
- `README.md` находится в статусе untracked.
- `.gitignore` содержит только `node_modules` и `.env`.

Риск: локальная инструкция в `README.md` полезна, но сейчас не попадёт в репозиторий при коммите.

## Документация

Есть локальный `README.md` с инструкцией по запуску на Windows:

- установка Node.js LTS, PostgreSQL, Git;
- создание базы `driverbot`;
- импорт `bd.sql`;
- запуск миграций;
- пример `.env`;
- выдача прав администратора через `users.is_admin`;
- частые ошибки.

Важно: PowerShell без `-Encoding UTF8` показывает русские тексты и emoji как mojibake. Читать так:

```powershell
Get-Content -Encoding UTF8 README.md
```

## Конфигурация

Фактически используемые env-переменные:

- `BOT_TOKEN`
- `NODE_ENV`
- `DATABASE_URL`
- `PG_SSL`
- `PG_HOST`
- `PG_PORT`
- `PG_USER`
- `PG_PASSWORD`
- `PG_DATABASE`
- `BOOKING_TZ`
- `GOOGLE_SHEETS_ID`
- `GOOGLE_SHEETS_SHEET_NAME`
- `GOOGLE_SHEETS_DAYS_AHEAD`
- `GOOGLE_SHEETS_SYNC_INTERVAL_MINUTES`
- `GOOGLE_SHEETS_SERVICE_ACCOUNT_PATH`
- `GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON`
- `BIKE_PRICE_ROUNDING`
- `BIKE_PRICE_ROUNDING_STEP`
- `BIKE_PRICE_ROUNDING_MIN_DAYS`

В локальном `.env` также есть `ADMIN_IDS`, но код его не использует. Админ-доступ сейчас определяется только полем `users.is_admin` в базе.

## Структура проекта

Основные файлы:

- `index.js` - старт бота.
- `bot.js` - регистрация middleware, команд, callback handlers, scheduler напоминаний, Google Sheets sync.
- `connect.js` - общий Knex instance.
- `knexfile.js` - development/production настройки PostgreSQL.
- `commands/` - slash-команды.
- `handlers/` - обработчики сообщений, callback-flow, бронирования, админки.
- `middlewares/` - DB attach, session storage, reset flow.
- `services/` - профиль пользователя, booking session object, загрузка файлов.
- `utils/` - i18n, календарь, overlap, reminders, Google Sheets, pricing profiles.
- `migrations/` - 23 migration-файла, нумерация до `024` (номер `005` отсутствует).
- `bd.sql` - дамп базы со стартовыми данными и таблицей `knex_migrations`.
- `images/prices/` - картинки сезонных прайсов.

Размер кода проекта без `node_modules`: около 6639 строк JS.

## Данные и схема БД

Локальная база доступна, все миграции применены.

Таблицы:

- `users`
- `categories`
- `bikes`
- `seasons`
- `bike_prices`
- `rentals`
- `sessions`
- `reminders`
- `no_availability_requests`
- `knex_migrations`
- `knex_migrations_lock`

Ключевые поля:

- `users`: `telegram_id`, `telegram_name`, `name`, `phone`, `passport_photo_file_id`, `is_admin`, `meta`, `lang`.
- `bikes`: `name`, `category_id`, `description`, `meta`, `emoji`, `is_active`, `pricing_profile`.
- `bike_prices`: `bike_id`, `season_id`, `days_type`, `price_per_day`.
- `rentals`: `user_id`, `bike_id`, `start_date`, `end_date`, `start_at`, `end_at`, `total_price`, `status`, `accept_terms`, `helmets_qty`, `delivery_required`, `delivery_address`, `docs_missing`, `booking_public_id`, `deposit_required`, `deposit_paid`, `contract_file_id`, `deposit_note`.
- `sessions`: `user_id`, `data`.
- `reminders`: `rental_id`, `type`, `send_at`, `sent`.

Текущее локальное состояние данных:

- users: 4
- admins: 1
- categories: 3
- bikes: 25, все активные
- seasons: 3
- bike_prices: 360
- rentals: 13
- reminders: 12
- no_availability_requests: 0

Статусы текущих rentals:

- `approved`: 3
- `cancelled`: 2
- `cancelled_by_client`: 8

Категории:

- `1` Light Scooters
- `2` Comfort Scooters
- `3` Maxy Scooters

Сезоны:

- `Low`: месяцы 6, 7, 8, 9
- `Middle`: месяцы 4, 5, 10, 11
- `High`: месяцы 12, 1, 2, 3

Ожидаемая полнота цен: 25 байков × 3 сезона × 5 типов дней = 375 строк. Сейчас 360 строк. У байка `ADV 160cc, ABS, Black, 2022` нет цен.

## Пользовательские сценарии

Регистрация:

1. `/start`.
2. Создание записи `users`, если её нет.
3. Выбор языка `ru/en/ua`.
4. Сбор имени.
5. Сбор телефона.
6. Сбор паспорта: фото или текстовый номер в `users.meta.passport_number`.
7. Показ главного меню.

Главное меню:

- аренда;
- поддержка;
- цены;
- аккаунт;
- условия;
- о компании.

Бронирование:

1. `/book` или кнопка аренды.
2. Выбор маршрута: сначала дата или сначала байк.
3. Выбор периода.
4. Опционально выбор времени, шлемов, доставки, адреса, заметок.
5. Выбор категории и байка.
6. Расчёт цены по сезону и `days_type`.
7. Добавление в draft rentals со статусом `process`.
8. Принятие условий.
9. Подтверждение заявки.
10. Уведомление администратора.

Клиентский кабинет:

- текущие аренды и черновики;
- история;
- договор/условия;
- платежи/депозит;
- настройки профиля;
- поддержка;
- отмена активной заявки.

Цены:

- отправляются картинками из `images/prices/low.png`, `middle.png`, `high.png`.

Поддержка:

- call / FAQ / how to find;
- ссылка на Telegram-админа из `utils/constants.js`.

## Админские сценарии

Доступ:

- `/admin` доступен только если `users.is_admin = true`.
- callback-разделы админки также проверяют `is_admin` в `admin_menu` и `admin_bikes`.

Функции:

- список активных/ожидающих/подтверждённых заявок;
- approve/cancel заявки;
- запрос причины отказа;
- заметка по депозиту;
- добавление байка;
- редактирование имени, описания, emoji, категории, цен;
- включение/выключение байка;
- копирование цен с другого байка.

Ценообразование в админке:

- профили лежат в `utils/pricingProfiles.js`;
- поддерживаются `click`, `aerox`, `adv160`, `pcx160`, `price`;
- сезонные базовые цены превращаются в 15 строк `bike_prices`;
- округление задаётся env-переменными `BIKE_PRICE_ROUNDING*`.

## Интеграции и фоновые задачи

Напоминания:

- `utils/reminders.js`;
- scheduler запускается каждую минуту в `bot.js`;
- типы: `start_24h`, `start_1h`, `end_24h`, `end_1h`;
- timezone по умолчанию `Asia/Bangkok`, можно переопределить `BOOKING_TZ`.

Google Sheets:

- `utils/googleSheetsCalendar.js`;
- запускается только если есть `GOOGLE_SHEETS_ID` и service account;
- обновляет лист календаря с цветами по статусам;
- период по умолчанию 90 дней.

Важное ограничение: Google Sheets grid сейчас строится по `start_date/end_date`, без учёта точного времени `start_at/end_at`.

## Найденные риски и дефекты

### 1. Статусы заявки рассинхронизированы

`book_confirm` после клиентского подтверждения ставит `status: "active"`, а админка имеет список `pending`, который ищет только `pending`. При этом администратору отправляются кнопки approve/cancel, а approve меняет статус на `approved`.

Нужно выбрать модель:

- `process` - черновик пользователя;
- `pending` - заявка отправлена админу;
- `approved` - админ подтвердил;
- `active` - аренда фактически началась;
- `completed/returned` - завершена;
- `cancelled/cancelled_by_client` - отменена.

Это самый важный участок для стабилизации перед новыми задачами.

### 2. У одного активного байка нет цен

`ADV 160cc, ABS, Black, 2022` активен, но имеет 0 строк `bike_prices`.

Код умеет показать `booking_price_tbd`, но `book_add_rental` требует truthy `booking.totalPrice`, поэтому такой байк нельзя нормально добавить в аренду. Нужно либо заполнить цены, либо разрешить `priceUnknown` как валидный сценарий.

### 3. `node-fetch` используется без прямой зависимости

`services/fileService.js` делает `require("node-fetch")`, но в `package.json` такой зависимости нет. Сейчас модуль находится транзитивно через `grammy/googleapis`, но это хрупко. Лучше либо добавить `node-fetch` явно, либо использовать глобальный `fetch` Node 22.

### 4. Дублирующий calendar handler

В `bot.js` есть оба обработчика:

- общий `^book:select_date:`;
- более конкретный `^book:select_date:\d{4}-\d{2}-\d{2}$`.

Первый обработчик покрывает второй callback. `handlers/calendar_handler.js` выглядит как устаревшая ветка и, вероятно, не используется.

### 5. Проверка занятости по дням иногда грубее, чем datetime-overlap

Основная проверка пересечений через `utils/overlap.js` учитывает `start_at/end_at` и legacy dates. Но `getBusyDatesForBike` блокирует целые дни по `start_date/end_date`. Для частичных дневных аренд это может запрещать свободные интервалы.

### 6. Неиспользуемый `ADMIN_IDS`

Переменная есть в `.env`, но код её не читает. Это может путать при переносе проекта.

### 7. `README.md` не отслеживается Git

Документ актуален и полезен, но сейчас untracked. Нужно решить: добавить в Git или перенести в другой документ.

### 8. Нет автоматических тестов

В `package.json` нет тестовой команды. Для дальнейшей разработки рискованнее всего менять:

- расчёт цены;
- выбор доступных байков;
- overlap;
- статусы заявки;
- подтверждение/отмена;
- админские действия.

## Рекомендуемая стратегия новых задач

Оптимальный вариант: сначала короткий стабилизационный этап, потом новая функциональность.

### Этап 1. Зафиксировать правила

Нужно явно принять:

- жизненный цикл статусов rental;
- обязательность паспорта для бронирования;
- можно ли создавать заявку без точной цены;
- какие статусы блокируют байк;
- как считать частичный день аренды;
- какие роли и админы используются: только `users.is_admin` или ещё `ADMIN_IDS`.

### Этап 2. Минимальные технические исправления

Рекомендуемый порядок:

1. Привести статус-flow к одной модели, скорее всего `process -> pending -> approved -> active -> completed`.
2. Исправить price TBD или заполнить недостающие цены.
3. Удалить/заменить устаревший `calendar_handler.js`.
4. Сделать прямую зависимость `node-fetch` или перейти на global `fetch`.
5. Добавить `README.md` в Git.
6. Добавить smoke-check script, который грузит модули и сам завершает процесс.

### Этап 3. Тестовый контур

Минимальный набор без большого рефакторинга:

- unit-проверки `pricingProfiles.calculatePriceRows`;
- unit-проверки `overlap.applyOverlapCondition` через тестовую БД или query builder snapshot;
- integration-проверка статусов `process/pending/approved`;
- проверка, что каждый активный байк имеет 15 строк цен.

### Этап 4. Новые задачи

После стабилизации лучше добавлять новые возможности через существующие границы:

- пользовательские сценарии в `handlers/`;
- бизнес-правила в `utils/` или `services/`;
- изменения схемы только через новые миграции;
- тексты сразу во все `langs/ru.js`, `langs/en.js`, `langs/ua.js`.

## Команды для ежедневной работы

Установка:

```powershell
npm ci
```

Запуск:

```powershell
npm start
```

Разработка:

```powershell
npm run dev
```

Миграции:

```powershell
npm run migrate
npx knex migrate:status
```

Синтаксис JS:

```powershell
rg --files -g *.js -g !node_modules/** | ForEach-Object { node --check $_ }
```

Проверка полноты переводов:

```powershell
node -e "const langs=require('./langs'); const names=Object.keys(langs); const all=[...new Set(names.flatMap(n=>Object.keys(langs[n])))]; for (const n of names){ console.log(n, Object.keys(langs[n]).length, 'missing', all.filter(k=>!(k in langs[n])).length); }"
```

Проверка полноты цен:

```powershell
node -e "const db=require('./connect');(async()=>{const rows=await db('bikes').leftJoin('bike_prices','bikes.id','bike_prices.bike_id').select('bikes.id','bikes.name').count('bike_prices.id as prices').groupBy('bikes.id','bikes.name').havingRaw('count(bike_prices.id) < 15').orderBy('bikes.id'); console.log(rows); await db.destroy();})()"
```

## Рабочие правила для следующих изменений

- Не редактировать `.env` и не коммитить секреты.
- Любое изменение БД делать новой миграцией.
- Не править `bd.sql` вручную как замену миграции.
- Новые тексты добавлять во все 3 словаря.
- Перед изменением бронирования проверять статус-flow и overlap.
- Перед релизом проверять, что нет активных байков без полного набора цен.
- Если используется локальный боевой `BOT_TOKEN`, не запускать параллельно серверную копию бота, иначе Telegram вернёт `409 Conflict`.

## Актуализация после стабилизации 2026-07-01

Checkpoint-коммит: `06c273b Stabilize rental flow before admin update`.

Состояние после стабилизационной пачки:

- Миграции применены до `024_add_vehicle_type_to_bikes.js`.
- Таблица `bikes` остается историческим storage-именем, но теперь имеет `vehicle_type` (`scooter`/`car`), `inventory_code`, `sort_order`.
- Добавлены DB-инварианты: уникальный `booking_public_id`, уникальные price keys, CHECK по rental statuses, CHECK по обязательным диапазонам дат/времени, CHECK по `vehicle_type`.
- Rental route вынесен в `services/rentalService.js`.
- Inventory abstraction добавлена в `services/vehicleService.js`.
- Статусы централизованы в `utils/rentalStatus.js`.
- HTML escaping централизован в `utils/html.js`.
- Добавлены проверки `check:data`, `check:rental-service`, `check:syntax`, `check:migrations`, `check:bot-load`, `preflight`.
- Telegram handlers бронирования теперь в основном являются адаптерами к service layer.

Базовые команды перед следующей задачей:

```powershell
npm run preflight
npm run check:data
```

`npm run preflight` допускает известный content warning по `vehicle_id=6` без цен. `npm run check:data` остается строгой релизной проверкой и падает, пока новый список техники/цен не загружен или старый байк не деактивирован.
