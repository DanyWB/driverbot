# Этап 8. Laravel Bot API и перевод Telegram-бота

Дата закрытия: 2026-07-22.

Статус: реализовано и проверено локально. Этап переводит интерактивные клиентские
сценарии на Laravel, но сам по себе не является разрешением на production cutover:
доставка уведомлений, expiry pending-заявок и напоминания закрываются на этапе 9,
production deployment и backup/restore - на этапе 10.

## 1. Итоговая граница системы

В режиме `BOT_DATA_MODE=laravel`:

- Node.js принимает Telegram updates, строит кнопки и хранит временный диалог;
- Laravel является единственным владельцем клиентов, каталога, доступности, цены,
  бронирований, документов и истории статусов;
- PostgreSQL credentials боту не нужны, попытка прямого обращения к `connect.js`
  завершается ошибкой;
- временная корзина нескольких единиц техники хранится в Redis;
- Node.js не рассчитывает цену и не проверяет пересечения локально;
- встроенные Telegram-admin handlers, legacy reminders и Google Sheets sync отключены;
- старый режим остается только как feature-flag rollback, без dual-write.

OpenAPI является машинно-проверяемым контрактом:
`backend/openapi/bot-api.v1.yaml`.

## 2. Реализованный API

Базовый путь: `/api/v1/bot`.

| Метод | Путь | Назначение |
|---|---|---|
| `GET` | `/configuration` | timezone, currency, версия условий, TTL pending, контакт менеджера |
| `GET` | `/categories` | активные категории с опубликованной техникой |
| `GET` | `/vehicles` | опубликованный каталог с фото и фильтрами |
| `GET` | `/vehicles/available` | техника, свободная на диапазон дат |
| `GET` | `/vehicles/{id}` | карточка техники |
| `GET` | `/vehicles/{id}/availability` | недоступные даты, диапазон до 93 дней |
| `POST` | `/quotes` | расчет текущей цены и проверка доступности |
| `POST` | `/customers/sync` | идемпотентный upsert Telegram identity |
| `GET` | `/customers/me` | клиентский профиль |
| `PATCH` | `/customers/me` | имя, язык, телефон, username, номер паспорта |
| `POST` | `/bookings` | атомарное создание корзины броней |
| `GET` | `/customers/me/bookings` | текущие брони или история с `limit/offset` пагинацией |
| `GET` | `/bookings/{uuid}` | клиентская карточка собственной брони |
| `POST` | `/bookings/{uuid}/cancel` | отмена с учетом правила 24 часов |
| `POST` | `/customers/me/documents` | приватный документ клиента |
| `POST` | `/bookings/{uuid}/documents` | приватный документ конкретной брони |

Ответы имеют стабильную форму `data/meta` или `error`; `X-Request-ID` возвращается
в заголовке и теле ошибки. Внутренние поля, admin notes и пути приватных файлов в
Bot API не выдаются.

## 3. Авторизация и защита

Service token хранится в БД только как SHA-256 hash. Доступ разделен abilities:

- `bot:read` - конфигурация, каталог, цены и чтение броней;
- `bot:write` - sync/update клиента, создание и отмена;
- `bot:documents` - загрузка документов.

Команды управления:

```powershell
cd backend
php artisan bot-api:client issue --name="Production Telegram Bot"
php artisan bot-api:client rotate --id=<CLIENT_ID>
php artisan bot-api:client revoke --id=<CLIENT_ID>
```

Токен показывается один раз. Его нельзя помещать в Git, документацию или логи.
Production API должен быть доступен боту только по HTTPS.

Rate limit двухуровневый:

- невалидные bearer tokens: `30/min` на IP;
- общий предел на service token: `600/min` по умолчанию;
- предел на одного Telegram-пользователя: `90/min` по умолчанию.

Так один клиент не блокирует остальных, а скомпрометированный token не получает
неограниченный трафик. Лимиты настраиваются через env.

## 4. Идемпотентность и конкурентность

Все mutation endpoints требуют `Idempotency-Key`.

- один сетевой retry использует тот же key;
- новая операция sync/profile получает новый key, чтобы не вернуть устаревший профиль;
- подтверждение корзины использует стабильный nonce из Redis;
- nonce меняется только при изменении состава или опций корзины;
- повтор подтверждения возвращает тот же результат;
- reuse одного key с другим payload возвращает `409 IDEMPOTENCY_KEY_REUSED`.

Корзина создается одной транзакцией. Техника блокируется в стабильном порядке,
а PostgreSQL exclusion constraint остается последней защитой от пересечения. Если
занята хотя бы одна позиция, не создается ни одна бронь.

## 5. Бизнес-правила клиентского flow

- Категории и названия полностью приходят из Laravel; привязки к старым ID `1/2/3`
  в новом режиме нет.
- Фото техники приходят из публичного storage Laravel.
- Цена всегда получается через `PricingService`; локальные сезонные PNG в новом
  режиме не показываются.
- Максимальный клиентский период одного расчета/брони - 366 дней.
- Бронь в прошлом отклоняется в timezone `Asia/Bangkok`.
- В корзине нельзя дважды добавить одну и ту же технику.
- Подтверждение сохраняет количество шлемов, доставку, адрес, комментарий,
  pickup/return time и снимок цены.
- При выбранной доставке непустой адрес обязателен на уровнях Bot API, общего
  `BookingService` и PostgreSQL CHECK constraint.
- Согласие привязано к конкретному `BOOKING_TERMS_VERSION`. Если версия изменилась,
  Laravel возвращает конфликт, а бот просит принять условия заново.
- `pending` получает `pending_expires_at`; фактическая автоотмена выполняется этапом 9.
- Клиент может отменить `pending` и `approved` ранее чем за 24 часа.
- Менее чем за 24 часа `approved` не отменяется автоматически: API и бот возвращают
  runtime-контакт менеджера. `active` клиентом не отменяется.
- `expired` и `no_show` корректно отображаются во всех трех языках.

Telegram locale `uk` нормализуется во внутренний `ua`; неизвестный системный язык
не ломает регистрацию и приводит к явному выбору языка.

Первый update после истечения `BOT_CUSTOMER_SYNC_TTL_SECONDS` синхронизирует Telegram
identity автоматически. Команда `/start` сохраняет отдельный registration flow. Это
позволяет использовать старые кнопки после cutover без записи в БД на каждый update.
Текущие и исторические брони выводятся страницами по пять позиций; длинные поля
показываются сокращенно, чтобы не превысить Telegram limit 4096 символов.

## 6. Данные и файлы

Миграция `2026_07_22_000900_add_bot_api_profile_and_booking_options.php` добавляет:

- encrypted `customers.private_data` для номера паспорта и признаков заполнения;
- шлемы, доставку, адрес и versioned terms acceptance в `bookings`;
- service actor в `booking_status_history`.

Миграция `2026_07_22_001000_enforce_booking_delivery_address.php` добавляет
PostgreSQL CHECK для обязательного адреса при включенной доставке.

Паспортные файлы не сохраняются локально в Node.js в Laravel-режиме. Бот скачивает
их потоково с timeout и лимитом, затем передает Laravel. Laravel проверяет MIME/размер
и хранит документ на private disk. При откате DB-транзакции уже записанный файл
компенсационно удаляется.

## 7. Redis session

Ключ по умолчанию:
`drive-phangan:bot:session:v1:<telegram_id>`.

Каждый update одного пользователя проходит под Redis-lock. Lease продлевается во
время долгой обработки, владение повторно проверяется перед записью session, а
освобождение token-safe через Lua. Session имеет TTL, поврежденный JSON сбрасывается.
Это исключает потерю обновлений корзины при параллельных callback от одного клиента,
даже если обработчик работает дольше исходного lock TTL. При `SIGINT` и `SIGTERM` bot
polling и Redis client закрываются корректно.

## 8. Конфигурация

Laravel:

```dotenv
APP_URL=https://example.com
APP_TIMEZONE=Asia/Bangkok
BUSINESS_TIMEZONE=Asia/Bangkok
MANAGER_TELEGRAM_USERNAME=@username
BOOKING_TERMS_VERSION=2026-07-22
CUSTOMER_DOCUMENT_MAX_MB=10
BOT_API_AUTH_RATE_LIMIT_PER_MINUTE=30
BOT_API_SERVICE_RATE_LIMIT_PER_MINUTE=600
BOT_API_USER_RATE_LIMIT_PER_MINUTE=90
BOT_API_IDEMPOTENCY_TTL_HOURS=72
BOT_API_MAX_BATCH_SIZE=10
BOT_API_MAX_RENTAL_DAYS=366
```

Bot:

```dotenv
BOT_DATA_MODE=laravel
BOT_API_URL=https://example.com/api/v1/bot
BOT_API_TOKEN=<one-time-issued-token>
BOT_API_TIMEOUT_MS=5000
BOT_API_MAX_ATTEMPTS=3
BOT_CUSTOMER_SYNC_TTL_SECONDS=86400
REDIS_URL=redis://127.0.0.1:6379/1
BOT_SESSION_PREFIX=drive-phangan:bot:session:v1
BOT_SESSION_TTL_SECONDS=2592000
BOT_SESSION_LOCK_TTL_MS=30000
BOT_SESSION_LOCK_WAIT_MS=5000
BOT_FILE_DOWNLOAD_TIMEOUT_MS=15000
BOT_DOCUMENT_MAX_MB=10
BOOKING_TZ=Asia/Bangkok
```

`BOT_DOCUMENT_MAX_MB` и `CUSTOMER_DOCUMENT_MAX_MB` должны совпадать.
`APP_URL` должен быть публичным HTTPS URL, иначе Telegram не сможет загрузить фото
техники из Laravel storage.

## 9. Precheck

Laravel:

```powershell
cd backend
composer test
npm run types:check
npm run lint:check
npm run format:check
npm run build
```

Bot в новом режиме:

```powershell
cd bot
npm run preflight
npm audit --omit=dev
```

Laravel-профиль `preflight` проверяет JS, unit tests, загрузку без PostgreSQL,
конкурентную Redis-session и read-only Bot API. Legacy-профиль продолжает проверять
старые Knex migrations/data только для rollback.

Реальный HTTP E2E проверен по цепочке: sync, profile, повторный sync без stale data,
configuration, catalog, availability, quote, create, idempotency replay, list,
private document и cancel. Временный token отозван, созданные записи и файлы удалены.

Финальный срез проверок от 2026-07-22:

- единый `npm run precheck` прошел;
- Laravel: 155 тестов, 152 passed, 3 PostgreSQL-only skipped, 1020 assertions;
- Pint и PHPStan прошли без ошибок;
- Vue: ESLint, Prettier, TypeScript и production build прошли;
- Node.js: 13 unit tests, syntax check 128 файлов, 317 ключей и совпадающие
  placeholder-наборы в каждой из `ru/en/ua`;
- Laravel-mode preflight прошел против локальных PostgreSQL, Redis и HTTP API;
- `npm audit --omit=dev` возвращает 0 известных уязвимостей;
- legacy preflight имеет только ожидаемое content warning по старому `vehicle_id=6`
  без тарифов; эта запись не является частью будущего финального импорта техники.

## 10. Cutover и rollback

Cutover выполняется только после этапа 9:

1. Сделать backup обеих БД и persistent storage.
2. Применить Laravel migrations и проверить `/health/ready`.
3. Задать реальный `MANAGER_TELEGRAM_USERNAME` и утвержденную версию условий.
4. Выпустить production service token и положить его только в secret environment бота.
5. Запустить `BOT_DATA_MODE=laravel` preflight.
6. Остановить legacy bot instance, затем запустить ровно один новый instance.
7. Удалить PostgreSQL credentials legacy-БД из окружения нового процесса.
8. Проверить тестовую бронь, админку и delivery outbox.

Rollback: остановить Laravel-mode bot, вернуть `BOT_DATA_MODE=legacy` и legacy DB
credentials, затем запустить старый процесс. Постоянный dual-write запрещен. Брони,
созданные в Laravel после cutover, автоматически в legacy-БД не копируются, поэтому
перед rollback нужна операционная сверка и ручное решение по новым заявкам.

## 11. Что остается дальше

Этап 9 должен реализовать worker/outbox delivery, уведомление админа о новой заявке,
approve/cancel/expired сообщения клиенту, автоexpiry через 24 часа, напоминания в день
выдачи и за час, retry/backoff и housekeeping. До production обязательно получить
реальный Telegram username менеджера и утвердить фактический текст условий аренды;
placeholder-контакты и `example.com` использовать нельзя.
