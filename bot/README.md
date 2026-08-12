# Bike Rent Bot: локальный запуск на Windows

Инструкция для legacy-приложения в каталоге `bot/`. Если явно не указано иное,
команды ниже выполняются из этого каталога.

## Актуальный Laravel-режим

Целевой режим после этапа 8 - `BOT_DATA_MODE=laravel`. В нем бот является Telegram UI,
хранит временную корзину в Redis и обращается к `/api/v1/bot`; цены, доступность,
клиенты, документы и брони принадлежат Laravel. `PG_*` и `DATABASE_URL` процессу бота
не нужны. Старые Knex, Telegram-admin handlers, reminders и Google Sheets не запускаются.

Минимальная конфигурация:

```dotenv
NODE_ENV=production
BOT_TOKEN=<telegram-token>
BOT_DATA_MODE=laravel
BOT_API_URL=https://example.com/api/v1/bot
BOT_API_TOKEN=<laravel-service-token>
BOT_API_TIMEOUT_MS=5000
BOT_API_MAX_ATTEMPTS=3
REDIS_URL=redis://127.0.0.1:6379/1
BOOKING_TZ=Asia/Bangkok
```

Дополнительные UI-материалы не блокируют запуск:

- сезонные изображения ищутся сначала в `images/prices/<lang>/<season>.png`, затем
  в универсальном `images/prices/<season>.png`; при отсутствии файла бот показывает
  локализованный fallback, а цену бронирования всё равно получает только из Laravel;
- цветные иконки категорий настраиваются необязательными
  `TELEGRAM_CATEGORY_LIGHT_ICON_ID`, `TELEGRAM_CATEGORY_COMFORT_ICON_ID`,
  `TELEGRAM_CATEGORY_MAXI_ICON_ID` и `TELEGRAM_CATEGORY_CAR_ICON_ID`;
- если custom emoji ID пуст или Telegram его отклонил, бот автоматически использует
  обычные `🛵`/`🚗` и продолжает сценарий.

Проверка и запуск:

```powershell
npm ci
npm run preflight
npm start
```

`npm run preflight` сам выбирает Laravel или legacy-набор по `BOT_DATA_MODE`.
Подробные правила token rotation, Redis, API, cutover и rollback находятся в
`../STAGE_8_BOT_API.md`.

## Legacy rollback

Остальная часть файла описывает старый режим `BOT_DATA_MODE=legacy`. Он сохраняется
для контролируемого отката и не должен работать параллельно с Laravel-режимом.

## Что скачать и установить

1. **Node.js LTS**
   Скачивать с официального сайта: https://nodejs.org/en/download
   Нужна именно LTS-версия, а не Current. `npm` установится вместе с Node.js.

2. **PostgreSQL 17 или новее**
   Скачивать установщик для Windows: https://www.postgresql.org/download/windows/
   При установке запомни пароль пользователя `postgres`. Он понадобится для создания базы.

3. **Git for Windows**
   Скачивать отсюда: https://git-scm.com/download/win
   Нужен, чтобы скачать проект из репозитория и работать с командами `git`.

4. **pgAdmin 4, опционально**
   Обычно ставится вместе с PostgreSQL. Если нет, можно скачать отдельно: https://www.pgadmin.org/download/
   Это графический интерфейс для просмотра базы. Для запуска бота он не обязателен.

5. **Visual Studio Code, опционально**
   Удобно для редактирования `.env` и файлов проекта: https://code.visualstudio.com/

После установки открой новый PowerShell и проверь:

```powershell
node -v
npm -v
git --version
psql --version
```

Если `psql` не найден, добавь папку PostgreSQL в `PATH`, например:

```text
C:\Program Files\PostgreSQL\17\bin
```

Версия в пути может отличаться.

## Получить код проекта

Если проект уже лежит на диске:

```powershell
cd C:\Users\user\Desktop\phangan
```

Если нужно скачать заново:

```powershell
cd C:\Users\user\Desktop
git clone <URL_РЕПОЗИТОРИЯ> phangan
cd phangan
```

## Установить зависимости проекта

В папке проекта выполни:

```powershell
npm ci
```

Если `npm ci` ругается на lock-файл, используй:

```powershell
npm install
```

## Создать Telegram-бота

1. Открой в Telegram `@BotFather`.
2. Выполни команду `/newbot`.
3. Скопируй токен бота.
4. Этот токен нужно записать в `.env` как `BOT_TOKEN`.

Важно: если этот же бот уже запущен на сервере, локальный запуск может дать ошибку `409 Conflict`. Для локальной разработки лучше использовать отдельного тестового бота или остановить серверную копию.

## Настроить PostgreSQL

В PowerShell из папки проекта выполни команды ниже. PostgreSQL спросит пароль пользователя `postgres`, который задавался при установке.

```powershell
psql -U postgres -c "CREATE USER driverbot_user WITH PASSWORD 'CHANGE_ME_LOCAL';"
psql -U postgres -c "CREATE DATABASE driverbot OWNER driverbot_user;"
psql -U postgres -d driverbot -f .\bd.sql
psql -U postgres -d driverbot -c "REASSIGN OWNED BY postgres TO driverbot_user;"
psql -U postgres -d driverbot -c "ALTER DATABASE driverbot OWNER TO driverbot_user;"
```

После импорта дампа накати свежие миграции проекта:

```powershell
npm run migrate
```

`bd.sql` - основной дамп базы со стартовыми данными. Файл `insert.sql` старый и может не совпадать с текущей схемой после всех миграций, поэтому для обычного локального запуска его лучше не использовать.

## Создать `.env`

В корне проекта создай `.env` из безопасного шаблона:

```powershell
Copy-Item .env.example .env
```

Затем укажи реальные локальные credentials. Основные поля:

```env
BOT_TOKEN=CHANGE_ME_TELEGRAM_BOT_TOKEN
NODE_ENV=development

PG_HOST=127.0.0.1
PG_PORT=5432
PG_USER=driverbot_user
PG_PASSWORD=CHANGE_ME_LOCAL
PG_DATABASE=driverbot

BOOKING_TZ=Asia/Bangkok
```

Для локального запуска `DATABASE_URL` не нужен. В режиме `development` проект берет настройки базы из `PG_HOST`, `PG_PORT`, `PG_USER`, `PG_PASSWORD`, `PG_DATABASE`.

Опциональные переменные для Google Sheets, если нужна синхронизация календаря:

```env
GOOGLE_SHEETS_ID=
GOOGLE_SHEETS_SHEET_NAME=Calendar
GOOGLE_SHEETS_DAYS_AHEAD=90
GOOGLE_SHEETS_SYNC_INTERVAL_MINUTES=60
GOOGLE_SHEETS_SERVICE_ACCOUNT_PATH=
GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON=
```

Если `GOOGLE_SHEETS_ID` не задан, синхронизация Google Sheets просто не запускается.

Опциональные переменные для округления цен:

```env
BIKE_PRICE_ROUNDING=floor
BIKE_PRICE_ROUNDING_STEP=100
BIKE_PRICE_ROUNDING_MIN_DAYS=7
```

## Проверить локальную инфраструктуру

Для будущего Laravel backend и сессий бота нужен Redis. На текущем Windows/OSPanel
окружении он запускается так:

```powershell
cd ..
powershell -ExecutionPolicy Bypass -File .\scripts\start-local-redis.ps1
npm run check:environment
cd bot
```

Первая команда безопасно переиспользует уже запущенный Redis. Вторая проверяет
подключение текущего проекта к PostgreSQL и Redis без вывода credentials.

## Запустить бота

Обычный запуск:

```powershell
npm start
```

Запуск для разработки с автоперезапуском:

```powershell
npm run dev
```

После запуска открой Telegram, найди своего бота и отправь `/start`.

## Выдать себе права администратора

Сначала отправь боту `/start`, чтобы он создал пользователя в таблице `users`.

Потом посмотри свой `telegram_id`:

```powershell
psql -U driverbot_user -d driverbot -c "SELECT id, telegram_id, telegram_name, is_admin FROM users;"
```

Выдай права администратора:

```powershell
psql -U driverbot_user -d driverbot -c "UPDATE users SET is_admin = true WHERE telegram_id = 123456789;"
```

Вместо `123456789` поставь свой `telegram_id`.

После этого в Telegram отправь боту:

```text
/admin
```

## Полезные команды

```powershell
npm start
npm run dev
npm run migrate
```

Проверить подключение к базе:

```powershell
psql -U driverbot_user -d driverbot -c "SELECT now();"
```

Посмотреть таблицы:

```powershell
psql -U driverbot_user -d driverbot -c "\dt"
```

## Частые ошибки

### `psql` не найден

PostgreSQL установлен, но его `bin`-папка не добавлена в `PATH`.

Добавь в `PATH`:

```text
C:\Program Files\PostgreSQL\17\bin
```

Потом закрой и заново открой PowerShell.

### `password authentication failed`

Неверный пароль в `.env` или при вводе в `psql`.

Проверь:

```env
PG_USER=driverbot_user
PG_PASSWORD=CHANGE_ME_LOCAL
```

### `database "driverbot" does not exist`

База не создана. Повтори шаг `Настроить PostgreSQL`.

### `relation "users" does not exist`

Схема базы не создана или не импортирован дамп. Выполни:

```powershell
psql -U postgres -d driverbot -f .\bd.sql
npm run migrate
```

### `409 Conflict` от Telegram

Один и тот же Telegram-бот уже запущен где-то еще. Останови другую копию или используй отдельный токен тестового бота.

### Бот запустился, но Google Sheets не обновляется

Для локального запуска Google Sheets не обязателен. Если синхронизация нужна, нужно заполнить `GOOGLE_SHEETS_ID` и один из вариантов сервисного аккаунта:

```env
GOOGLE_SHEETS_SERVICE_ACCOUNT_PATH=C:\path\to\service-account.json
```

или

```env
GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON={"type":"service_account",...}
```

## Preflight перед работой

Перед новой задачей или перед переносом изменений на сервер запусти:

```powershell
npm run preflight
```

Команда выполняет:

- `check:syntax` - синтаксис всех JS-файлов без `node_modules`;
- `check:migrations` - отсутствие pending migrations;
- `check:bot-load` - smoke-check загрузки `bot.js`;
- `check:rental-service` - rollback-интеграционный сценарий бронирования;
- `check:data:preflight` - аудит данных с допустимыми контентными warning.

Строгая проверка данных для релиза:

```powershell
npm run check:data
```

Сейчас строгий `check:data` ожидаемо падает на старом активном `vehicle_id=6` без матрицы цен. Это не исправляем до нового списка техники; в `npm run preflight` этот пункт считается warning, а не blocker.
