# Реализация change request CR-01–CR-11 — 2026-08

## Состояние

Кодовая реализация `CR-01`–`CR-11` завершена. Laravel сохранен единственным источником
бизнес-логики бронирования, цены, доступности и статусов. Формула расчета бронирования,
сезонные правила, статусы и ручная итоговая корректировка цены не изменялись.

Не закрыт только внешний визуальный acceptance gate `CR-11`: обязательная Browser/
Playwright-сессия недоступна в текущем runtime. Автоматические frontend-проверки пройдены;
визуальную матрицу нельзя считать выполненной до подключения браузера.

## Реализованные требования

- `CR-01`: reply keyboard из двух действий, inline-главное меню, отдельные `/start` и
  `menu:main`, общие обработчики Booking/Prices, RU/EN.
- `CR-02`: сезонные изображения во всех runtime modes; localized-first, universal fallback,
  Telegram `file_id` cache и локализованный текст при ошибке файла.
- `CR-03`: selectable tail days следующего месяца, корректные месяц/год, availability
  для полного видимого диапазона, декабрь/январь и leap year.
- `CR-04`: mapping по Laravel category `code`, точные RU/EN-подписи, env custom emoji,
  однократная диагностика и Unicode fallback.
- `CR-05`: `BotScreenRenderer`, одно активное UI-сообщение, text/photo replacement,
  bounded navigation stack, best-effort callback/delete и защита транзакционных сообщений.
- `CR-06`: страницы по 6 записей, точный total из Bot API, компактные кнопки,
  клиентская карточка без UUID/raw/null и возврат на сохраненную страницу.
- `CR-07`: `termsOrigin`, возврат из условий к текущему draft или в main menu без потери cart.
- `CR-08`: admin outbox для pending/client cancel/auto-expired, actor-aware защита от
  self-notification, сохраненные retry/backoff/dedupe и operational diagnosis.
- `CR-09`: публичный cancellation reason в Bot API/OpenAPI, bot details и RU/EN/UA
  Telegram templates; HTML escaping и entity/tag-safe ограничение длины.
- `CR-10`: шаг 50 только для каталожных тарифов и генератора, точные field errors,
  RU/EN, frontend/backend validation; manual booking total остается с шагом 1.
- `CR-11`: строки 40 px, блоки 32 px, fullscreen без второго DOM, Escape-first,
  focus restoration, body-scroll cleanup, responsive scroll и overlay layering.

## Baseline и дополнительные исправления

- Четыре старых Bot API теста зависели от прошедших абсолютных дат; тестовое время
  зафиксировано детерминированно без изменения production-логики.
- OSPanel PHP ссылался на недоступный системный temp и ломал PHPUnit/Composer subprocesses;
  precheck использует проектный `storage/framework/testing` через переносимый ini override.
- HTTP E2E script не загружал `bot/.env` и падал до первого запроса; его bootstrap исправлен.
- Локальный Redis helper завершался на ожидаемом первом `connection refused`; probe сделан
  безопасным, Redis session concurrency check проходит.
- В notification outbox найдены 4 старые due-записи без `last_error` при пустом
  `failed_jobs`: это соответствует отсутствующим scheduler/worker, а не Telegram rejection.
  Опасная массовая отправка локального backlog не выполнялась; runbook дополнен.
- Независимый backend review обнаружил риск обрыва HTML entity при длине Telegram 4096;
  добавлено visible-length усечение с целыми entities и закрытием тегов.
- Повторный аудит пользовательского bot-flow обнаружил сброс контекста кнопкой «Назад»
  в календаре, накопление карточек с фото, тупик после ввода адреса/примечаний и потерю
  страницы при отмене. Переходы переведены на единый renderer, контексты category/bike/date
  сохраняются, а действие отмены получило локализованную текстовую подпись.
- При проверке исправленного маршрута обнаружено неверное поле клавиатуры после выбора
  техники (`calendar.reply_markup` вместо готового markup); календарь мог открыться без
  кнопок. Ошибка исправлена и покрыта тестами пользовательского потока.
- В мобильном fullscreen заголовок календаря закреплен сверху, поэтому кнопка выхода
  остается доступной при вертикальной прокрутке. Ошибки каталожных цен связаны с полями
  через `aria-describedby`, объявляются как alerts, после неуспешной отправки фокус
  переходит на первое некорректное значение.

## Аудит существующих каталоговых данных

Данные не менялись и не округлялись автоматически. Из 480 активных строк тарифов 27 не
кратны 50; среди активной видимой техники — 25 из 450. Подробный перечень находится в
`CATALOG_PRICE_MULTIPLE_AUDIT_2026-08.md`. Эти значения продолжают участвовать в текущем
Laravel-расчете и должны быть исправлены администратором при следующем сохранении матрицы.

## Проверки

- Backend CI: 209 tests, 204 passed, 5 PostgreSQL-only skipped, 1281 assertions.
- Backend targeted CR suite: 82/82, 585 assertions.
- PostgreSQL-only constraints/concurrency: 5/5, 8 assertions.
- Pint и PHPStan level 7: passed, 0 errors.
- Frontend: ESLint, Prettier, Vue TypeScript, production build — passed.
- Frontend Node tests: 7/7 — price validation/error mapping, body scroll, Escape priority.
- Bot: 63/63; JS syntax 147 files; RU/EN/UA 334 keys; Laravel-mode and Redis session — passed.
- Живой локальный Bot API authentication/read smoke — passed.
- HTTP E2E customer → quote/availability → idempotent booking → list → document → cancel —
  passed; все созданные QA-записи, intents и файл удалены по точным идентификаторам.
- `git diff --check` — passed (информационные CRLF warnings у bot language files).
- Единый `npm run precheck` — passed: secret scan, полный bot preflight с live Bot API,
  production frontend build и полный backend CI.

## Внешние материалы и gate'ы

- Финальные `high`/`middle`/`low` price images и при необходимости отдельные RU/EN версии.
- Валидные custom emoji ID зеленого, желтого и красного байка; подтверждение прав Telegram
  для аккаунта владельца бота. До этого работают текущие universal images и Unicode fallback.
- Подключенная Browser-сессия для обязательных desktop/mobile, light/dark,
  normal/fullscreen screenshots и интерактивной проверки Escape/scroll/filters.
- Перед production: реальные Telegram token/admin chat ID, запущенные scheduler и worker,
  контролируемый online smoke и решение по старому notification backlog.

Commit, push и production deploy не выполнялись.
