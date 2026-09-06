# Целевая архитектура Drive Phangan

Дата фиксации: 2026-07-21.

Статус: принято как целевая архитектура первого production-релиза.

Реализация: этапы 0-8 закрыты; фактический Bot API и перевод Node.js-процесса
описаны в `STAGE_8_BOT_API.md`. Уведомления и production cutover еще не закрыты.

Этот документ является источником истины по техническим границам системы. Детальные
бизнес-правила находятся в `FINAL_TECHNICAL_SPEC.md`, правила цен и импорта - в
`PRICING_IMPORT_SPEC.md`, интерфейс бронирований - в `BOOKINGS_ADMIN_SPEC.md`, порядок
реализации - в `IMPLEMENTATION_ROADMAP.md`.

## 1. Цель архитектуры

Построить единое ядро управления арендой, которое обслуживает несколько каналов:

- Telegram-бот для клиентов;
- web-админку для операционной работы;
- будущий публичный сайт с каталогом и бронированием;
- будущие интеграции без копирования бизнес-логики.

Первый production-релиз включает Laravel backend, web-админку и обновленный
Telegram-бот. Разработка публичного клиентского сайта в текущий релиз не входит, но
модель данных и сервисы сразу должны быть готовы к его подключению.

## 2. Архитектурные принципы

1. Laravel - единственный владелец бизнес-логики и боевых данных.
2. PostgreSQL - единственный источник истины по клиентам, технике, ценам и броням.
3. Telegram, админка и будущий сайт являются каналами доступа к одним сервисам.
4. Бот не обращается к PostgreSQL и не принимает бизнес-решения самостоятельно.
5. Админка не дублирует REST-вызовы к собственному backend: Inertia-контроллеры
   вызывают те же Laravel Actions/Services внутри приложения.
6. Каждая изменяющая состояние операция выполняется транзакционно, идемпотентно и
   оставляет запись в истории.
7. Доступность техники защищается на уровне сервиса и базы данных.
8. Изменение тарифов не изменяет цену уже созданной брони.
9. Канал клиента не является самим клиентом: Telegram-аккаунт, телефон, email и
   будущая web-учетка привязываются к общей сущности `Customer`.
10. Инфраструктурные детали - файловый диск, очередь, канал уведомлений - скрыты за
    Laravel-интерфейсами и могут быть заменены без переписывания домена.

## 3. Контекст системы

```text
                         +----------------------+
Telegram client -------> | Node.js Telegram Bot |
                         +----------+-----------+
                                    |
                                    | HTTPS /api/v1/bot
                                    v
+----------------+       +----------+-----------+       +----------------+
| Admin browser  | ----> | Laravel Application  | ----> | PostgreSQL     |
| Vue + Inertia  |       | Domain + Application |       | source of truth|
+----------------+       +----------+-----------+       +----------------+
                                    |
                                    +------------------> Redis
                                    |                    queue/cache/locks
                                    |
                                    +------------------> Laravel Storage
                                    |                    photos/documents
                                    |
                                    +------------------> Telegram Bot API
                                                         async messages

Future public website -- Inertia routes or /api/v1/public --^ same services
```

В production Node.js-бот принимает входящие Telegram updates. Интерактивные ответы
пользователю отправляет бот, а отложенные системные уведомления и напоминания
Laravel отправляет через Telegram Bot API из надежной очереди.

## 4. Технологический стек

### Backend и admin frontend

- Laravel 13.x на PHP 8.3+;
- Vue 3 Composition API;
- TypeScript в обязательном режиме;
- Inertia 3;
- Vite;
- Tailwind CSS 4;
- shadcn-vue как база контролов, без привязки доменной логики к UI-библиотеке;
- PostgreSQL;
- Redis для очередей, cache, rate limit и краткоживущих блокировок;
- Laravel Queue и Scheduler.

Версии фиксируются lock-файлами. Обновление major-версий выполняется отдельно после
проверки changelog и полного набора тестов.

### Telegram-бот

- текущий Node.js LTS runtime;
- grammY;
- TypeScript рекомендуется добавить во время переноса к API либо использовать
  строгую runtime-валидацию контрактов, если миграция на TypeScript увеличит риск;
- Redis-backed session для временного состояния диалога;
- Laravel API для всех долговечных и бизнес-критичных операций.

### Локальная разработка

На текущей машине есть PHP 8.3.6, Composer 2.10.2 и Node.js 22.20.0. Laravel foundation
работает локально через OSPanel/PostgreSQL/Redis; Docker для локальной разработки не
требуется. Конфигурация не зависит от OSPanel и задается документированными
env-переменными.

## 5. Структура репозитория

Целевой формат - один репозиторий:

```text
phangan/
  backend/              Laravel, Inertia admin, future public web
  bot/                  Node.js Telegram bot
  infra/                deployment templates and process configs
  ARCHITECTURE.md
  IMPLEMENTATION_ROADMAP.md
  FINAL_TECHNICAL_SPEC.md
  PRICING_IMPORT_SPEC.md
  BOOKINGS_ADMIN_SPEC.md
```

Node.js-проект перенесен в `bot/` механически, без изменения поведения. Общие команды
запуска и проверок доступны из корневого `package.json`; Laravel находится в `backend/`.

Будущий публичный сайт по умолчанию размещается в Laravel-приложении как отдельная
группа Inertia pages/layouts. Отдельный Nuxt frontend рассматривается только если
появятся доказанные требования к независимому релизному циклу, сложному контентному
SEO или отдельной frontend-команде.

## 6. Границы компонентов

| Компонент | Отвечает за | Не отвечает за |
|---|---|---|
| Node.js Bot | Telegram updates, кнопки, шаги диалога, локализация UI, отправка введенных данных | цену, доступность, статусы, запись в бизнес-таблицы |
| Laravel Web | маршруты админки, авторизацию, Inertia responses, формы | отдельную копию бизнес-правил |
| Laravel API | стабильные контракты бота и будущего сайта, auth, validation, rate limit | Telegram UI |
| Application layer | use cases, транзакции, authorization, orchestration | HTTP/Telegram-форматирование |
| Domain services | бронирование, доступность, цены, state machine | хранение HTTP-сессий и отображение |
| PostgreSQL | долговечные данные, ограничения, audit, price snapshots | временное состояние диалога |
| Redis | очереди, cache, rate limit, bot sessions | источник истины по броням |
| Storage | фото техники и приватные документы | права доступа без Laravel |

## 7. Внутренняя структура Laravel

Используется модульный монолит. Микросервисы для текущего масштаба не нужны.

Основные доменные модули:

- `Customers` - клиент, контакты и внешние идентичности;
- `Vehicles` - техника, категории, характеристики, фото, видимость;
- `Pricing` - сезоны, тарифы, расчет и снимки цены;
- `Bookings` - создание, статусы, даты, отмены, история;
- `Availability` - занятость, пересечения, служебные блокировки;
- `Documents` - приватные документы клиента;
- `Notifications` - события, шаблоны, доставка и retry;
- `Admin` - web use cases, таблица, timeline, CSV;
- `Integrations/Bot` - API-контракт Telegram-бота.

Рекомендуемые уровни:

```text
HTTP Controller / Inertia Controller / Console Command
                    |
                    v
Application Action + DTO + Policy + Transaction
                    |
                    v
Domain Service / State Machine / Pricing Rules
                    |
                    v
Eloquent Models + Repositories where they add value
                    |
                    v
PostgreSQL / Redis / Storage / Telegram API
```

Контроллеры остаются тонкими. Form Requests валидируют входной формат, но
межсущностные правила проверяются внутри application/domain слоя. Один use case
должен одинаково вызываться из bot API, admin controller и будущего public API.

Не создаются универсальные `BaseService`, `BaseRepository` и другие абстракции без
реального повторного использования.

## 8. Модель клиента и каналов

Telegram ID нельзя использовать как основной ID клиента.

Основные таблицы:

- `customers` - имя, язык, заметки, timestamps;
- `customer_contacts` - тип `phone/email/whatsapp/telegram_username`, значение,
  нормализованное значение, основной контакт, подтверждение;
- `customer_identities` - provider `telegram/web`, внешний ID, metadata;
- `customer_documents` - приватные файлы клиента;
- `admin_users` - сотрудники админки, на первом релизе один полный доступ.

Для Telegram уникальна пара `(provider, external_id)`. Телефон хранится в
нормализованном виде, но автоматическое объединение двух клиентов только по похожему
имени запрещено. Админ сможет вручную исправлять контакты; полноценное merge клиентов
не входит в первый релиз.

Источник брони хранится отдельно:

- `telegram`;
- `admin_phone`;
- `admin_whatsapp`;
- `admin_instagram`;
- `admin_manual`;
- `website` - зарезервирован для будущего сайта.

## 9. Модель данных

Ключевые таблицы новой схемы:

```text
admin_users
customers
customer_contacts
customer_identities
categories
vehicles
vehicle_photos
pricing_seasons
pricing_season_months
vehicle_price_tiers
bookings
booking_price_snapshots
vehicle_occupancies
customer_documents
booking_status_history
audit_logs
notification_outbox
admin_telegram_bindings
admin_telegram_binding_codes
service_api_clients
idempotency_keys
```

Основные правила данных:

- внутренние PK могут быть bigint;
- наружу бронь отдается по непредсказуемому `public_id`;
- денежные итоговые суммы хранятся целыми батами, промежуточные дневные ставки -
  `numeric`, вычисления запрещено выполнять через binary float;
- даты аренды хранятся как `date`, время выдачи - nullable local `time`;
- бизнес-таймзона - `Asia/Bangkok`;
- системные timestamps хранятся в UTC и показываются в бизнес-таймзоне;
- бизнес-статусы хранятся строковыми кодами с DB check constraint, а не PostgreSQL enum,
  чтобы миграции state machine оставались управляемыми;
- критичные изменения имеют actor, request ID, старое и новое значение.

## 10. Бронирование и доступность

`process` является временным черновиком канала и не занимает технику. Долговечная
бронь создается или становится блокирующей при переходе в `pending`.

Технику блокируют:

- `pending`;
- `approved`;
- `active`;
- ручная служебная блокировка/maintenance.

Не блокируют:

- `process`;
- `completed`;
- `cancelled`;
- `cancelled_by_client`;
- `expired`;
- `no_show`.

Все блокирующие интервалы отражаются в `vehicle_occupancies`. Бизнес использует
включительные даты, а внутри проверки интервал приводится к полуоткрытому виду
`[start_date, end_date + 1 day)`. Это исключает расхождение между SQL и интерфейсом.

Создание или изменение брони выполняется так:

1. Проверить формат, права и допустимость перехода статуса.
2. Начать DB transaction.
3. Заблокировать строку `vehicles` через `FOR UPDATE`.
4. Проверить пересечения в `vehicle_occupancies`.
5. Рассчитать и сохранить новый price snapshot.
6. Создать/обновить booking и occupancy.
7. Записать status history, audit и notification outbox.
8. Зафиксировать транзакцию.
9. Отправить асинхронные уведомления после commit.

На `vehicle_occupancies` добавляется PostgreSQL exclusion constraint по vehicle и
диапазону дат для активных блокировок. Это последняя защита от двух одновременных
запросов. Конфликт возвращается вызывающему каналу как `409 VEHICLE_UNAVAILABLE`.

State machine и разрешенные переходы определяются в одном PHP enum/классе. Прямое
изменение `bookings.status` из контроллеров запрещено.

## 11. Ценообразование

Единственный расчет выполняет Laravel `PricingService`.

Зафиксированные правила:

- тарифные уровни: `1d`, `7d`, `14d`, `21d`, `month`;
- уровень выбирается по полной длительности брони;
- 30 и более дней используют месячный тариф;
- дни считаются включительно;
- при пересечении сезонов каждый день получает сезон своей даты;
- дневная ставка равна итоговой сумме тарифа, деленной на anchor days;
- итог округляется до ближайших 100 бат методом half-up;
- админ может заменить итоговую сумму, указав причину;
- каждая калькуляция сохраняется как неизменяемый versioned snapshot;
- изменение тарифов не пересчитывает существующие брони автоматически;
- изменение дат создает новую версию расчета и уведомляет клиента об изменении.

Полный алгоритм и источник Excel описаны в `PRICING_IMPORT_SPEC.md`.

## 12. API-контракты

### Bot API

Базовый путь: `/api/v1/bot`.

Группы endpoints:

- identity/customer sync;
- categories and vehicles;
- availability and quote;
- create/read/cancel booking;
- upload document;
- current customer bookings.

Изменяющие операции принимают `Idempotency-Key`. Для Telegram также сохраняется
уникальный `telegram_update_id` или стабильный client request ID. Повтор запроса не
должен создавать вторую бронь или повторять переход статуса.

Auth: отдельный service API client с хешированным bearer token, abilities,
`last_used_at`, `revoked_at` и возможностью ротации. Секрет хранится только в env бота.
В production весь трафик идет по HTTPS и ограничивается rate limit.

Формат ошибок стабилен:

```json
{
  "error": {
    "code": "VEHICLE_UNAVAILABLE",
    "message": "Vehicle is no longer available for selected dates",
    "fields": {},
    "request_id": "..."
  }
}
```

Бот принимает решения по UI на основании `code`, а не разбора текста. Контракт
описывается OpenAPI и проверяется integration/contract tests.

### Admin web

Admin routes используют cookie session, CSRF и Laravel authorization policies.
Inertia controllers вызывают application actions напрямую. Создавать отдельный API
слой только ради admin frontend не нужно.

### Future public API

Зарезервирован `/api/v1/public`. Он не реализуется до начала разработки клиентского
сайта. Публичные endpoints будут вызывать те же quote, availability и booking actions,
но иметь собственные правила auth, rate limit и anti-abuse.

## 13. Web-админка

Админка реализуется внутри `backend/` на Vue 3 + TypeScript + Inertia.

Основные области:

- таблица бронирований;
- timeline/шахматка;
- карточка и ручное создание брони;
- техника и фотографии;
- цены и сезоны;
- клиенты и документы;
- CSV-экспорт;
- системные настройки.

Состояние фильтров кодируется в URL, чтобы страницу можно было обновить или открыть
по ссылке. Большие таблицы используют серверную пагинацию. Timeline запрашивает
только выбранный диапазон и не загружает всю историю.

Смена дат выполняется через карточку брони. Drag-and-drop не входит в первый релиз,
потому что может скрыть важные проверки доступности и перерасчета.

## 14. Будущий клиентский сайт

Архитектурная готовность сайта входит в текущую работу, сам сайт - нет.

Сейчас обязательно обеспечить:

- channel-independent `Customer`;
- `booking_source=website` в допустимых источниках;
- отсутствие Telegram ID в обязательных полях booking use cases;
- reusable availability, quote and booking actions;
- публично пригодные vehicle descriptions/photos;
- разделение публичных фото и приватных документов;
- локализацию текстов отдельно от бизнес-кодов;
- API/versioning и rate limiting.

Когда появится задача сайта, предпочтительный первый вариант - новый public layout и
pages в том же Laravel + Vue + Inertia приложении. Inertia SSR можно включить для
публичных SEO-страниц. Nuxt выделяется отдельно только при подтвержденной необходимости.

## 15. Очереди, Scheduler и уведомления

Redis-backed Laravel Queue используется для:

- Telegram-уведомлений;
- напоминаний;
- обработки загруженных изображений;
- тяжелого CSV при необходимости;
- повторяемых внешних интеграций.

Laravel Scheduler:

- переводит необработанный `pending` в `expired` через 24 часа;
- планирует напоминание в день выдачи;
- планирует напоминание за час до выдачи, если есть время;
- повторно подбирает зависшие записи notification outbox;
- выполняет housekeeping временных файлов и idempotency keys.

Notification intent сохраняется в `notification_outbox` в той же транзакции, что и
бизнес-событие. Worker доставляет сообщение и фиксирует attempts, sent_at и last_error.
Уникальный ключ `(booking_id, notification_type, event_version)` предотвращает дубли.

Шаблоны уведомлений принадлежат Laravel. Интерактивные ответы текущего шага диалога
принадлежат Node.js-боту, но используют стабильные коды и данные Laravel.

Административные Telegram-получатели также принадлежат Laravel. Каждый `admin_user`
имеет не более одной persistent binding/tombstone-записи; один Telegram ID может
принадлежать только одному администратору. Web-админка выдает короткоживущий одноразовый
код, а доверенный Node.js-бот передает Laravel подтвержденную Telegram identity из личного
чата. Laravel создает отдельный notification intent для каждого активного и verified
администратора. Версия `generation` не позволяет доставить старую очередь аккаунту после
отвязки или повторного подключения. Eloquent observer и PostgreSQL lifecycle-trigger
дублируют защиту: деактивация, снятие email verification или удаление администратора
отзывают активные одноразовые коды, очищают Telegram PII и увеличивают `generation`,
даже если изменение выполнено bulk SQL.

## 16. Файлы и изображения

Все обращения идут через Laravel Storage.

- vehicle photos - отдельный public disk;
- customer documents - private disk, без прямого URL;
- доступ к документам - только через authorization controller;
- whitelist MIME/type, лимит размера, случайные server filenames;
- original filename хранится только как metadata;
- для изображений создаются нормализованные варианты/thumbnail;
- путь и disk хранятся отдельно от публичного URL.

Первый production использует локальный серверный диск. Переезд на S3 не меняет
доменную модель и выполняется сменой storage adapter и миграцией объектов.

## 17. Безопасность

- публичная регистрация админов выключена;
- пароль хешируется стандартным Laravel hasher;
- login rate limit и session expiration;
- CSRF для web, bearer auth для bot API;
- authorization policies даже при одном администраторе;
- secrets только в env/secret storage, никогда в Git;
- документы приватны и проверяются на доступ;
- входные файлы и параметры валидируются;
- все ручные изменения цены, дат и статуса попадают в audit log;
- production работает только по HTTPS;
- database и Redis не публикуются в интернет;
- backup содержит БД и файлы, восстановление проверяется до запуска.

## 18. Нагрузка и отказоустойчивость

Система проектируется для нескольких параллельных запросов и безопасного повторения
операций, а не под конкретное число пользователей.

Обязательные меры:

- DB indexes под availability, timeline, фильтры и scheduler;
- транзакции и DB constraint от пересечений;
- idempotency mutation endpoints;
- queue retry с exponential backoff и failed jobs;
- connection/read timeouts между ботом и Laravel;
- бот показывает безопасную повторяемую ошибку, если backend временно недоступен;
- request/correlation ID проходит через bot API, logs и audit;
- health endpoints отдельно для приложения и зависимостей;
- структурированные логи без токенов и персональных документов;
- ежедневные backup и политика хранения;
- zero dual-write: после cutover бот не пишет в старые таблицы.

Cache не используется для принятия окончательного решения о доступности. Финальная
проверка всегда выполняется в PostgreSQL внутри транзакции.

## 19. Production topology

Минимальная production-схема на одном сервере:

```text
Nginx
  -> PHP-FPM / Laravel web

Outgoing application process
  -> Node.js bot long polling Telegram
  -> Laravel Bot API

systemd
  -> Laravel queue workers
  -> Laravel scheduler process/cron
  -> Node.js bot process

Private services
  -> PostgreSQL
  -> Redis
  -> local persistent storage
```

Процессы перезапускаются автоматически. Deployment создает immutable release, связывает shared
env/storage, снимает backup, выполняет миграции перед атомарным переключением `current`, затем
перезапускает workers/scheduler/bot и выполняет smoke. Конкретные конфиги находятся в `deploy/`,
эксплуатационные процедуры - в `OPERATIONS_RUNBOOK.md`.

## 20. Миграция данных и cutover

Новая Laravel-схема создается миграциями и не обязана повторять старые названия
`bikes`, `bike_prices`, `rentals`.

Переносим:

- финальный список техники из Excel;
- активность/видимость техники;
- итоговые тарифы и сезоны;
- подготовленные фотографии после их получения.

Не переносим как production-данные:

- старые брони из Excel;
- старые тестовые/черновые брони Node.js;
- цвет и merged cells Excel как бизнес-данные.

Импорт реализуется повторяемой Laravel command с dry-run, отчетом ошибок и
идемпотентным external code. Перед cutover выполняется сверка количества техники,
полноты тарифов и контрольных расчетов.

Cutover:

1. Развернуть Laravel и импортировать финальные справочники.
2. Прогнать smoke и concurrency tests.
3. Переключить bot feature flag на Laravel API.
4. Запретить старому боту запись в PostgreSQL/Knex.
5. Проверить одну тестовую бронь end-to-end.
6. Включить production traffic и наблюдать logs/failed jobs.
7. Старую БД оставить read-only на согласованный период.

## 21. Стратегия тестирования

- unit: pricing, date boundaries, rounding, state transitions;
- feature: application actions, policies, validation, scheduler;
- database: overlap constraint, transactions and rollback;
- concurrency: два параллельных запроса на одну технику и даты;
- contract: OpenAPI requests/responses между Node и Laravel;
- frontend: критичные формы и timeline calculations;
- end-to-end: Telegram request -> admin approve -> client notification;
- import: повторный dry-run/run не создает дубли;
- security: private files, auth failures, rate limits;
- deployment smoke: health, DB, Redis, queue, scheduler, bot API.

Критическая бизнес-логика покрывается тестами до подключения UI. Тесты с текущего
Node.js preflight сохраняются до полного cutover.

## 22. Зафиксированные решения

- модульный Laravel-монолит, не микросервисы;
- web-админка: Vue 3 + TypeScript + Inertia;
- Node.js Telegram-бот остается отдельным процессом;
- bot не имеет прямого доступа к business DB после cutover;
- одна PostgreSQL-база и Redis;
- новый Laravel schema-first подход вместо наследования старых таблиц;
- единый `Customer` независимо от Telegram;
- versioned Bot API и зарезервированный Public API;
- DB-level защита от двойной брони обязательна;
- очередь и transactional notification outbox обязательны;
- локальный Storage через adapter с готовностью к S3;
- публичный клиентский сайт архитектурно учтен, но не входит в текущий релиз.

## 23. Открытые runtime-настройки

Эти пункты не блокируют разработку архитектуры:

- Telegram username менеджера для поздней отмены;
- доменное имя и TLS-конфигурация;
- характеристики production-сервера;
- backup retention;
- SMTP/канал системных alert;
- необходимость отдельного `ready_to_pickup` после обратной связи в эксплуатации.
