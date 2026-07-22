# Технический roadmap production-релиза

Дата фиксации: 2026-07-21.

Основа: `ARCHITECTURE.md`, `FINAL_TECHNICAL_SPEC.md`, `PRICING_IMPORT_SPEC.md` и
`BOOKINGS_ADMIN_SPEC.md`.

## 1. Результат roadmap

В конце работ должна существовать production-система, в которой:

- Laravel является единственным backend и владельцем бизнес-логики;
- web-админка полностью заменяет Excel в ежедневной работе;
- Telegram-бот сохраняет привычный клиентский сценарий, но работает через Laravel API;
- техника, цены, клиенты и брони находятся в новой PostgreSQL-схеме;
- параллельные запросы не могут создать двойную бронь;
- будущий клиентский сайт может подключиться к тем же use cases без переделки ядра;
- deployment, backup, очереди, scheduler и проверки документированы.

Публичный клиентский сайт не входит в эту оценку. В нее входит только архитектурная
готовность backend и данных к будущему каналу `website`.

## 2. Правила выполнения

- Этап закрывается только после его acceptance gate.
- Сначала реализуется домен и тесты, затем интерфейс соответствующего сценария.
- Новая и старая логика бронирования не работают в режиме постоянного dual-write.
- Изменения схемы выполняются только Laravel migrations.
- Импорт Excel выполняется командой с dry-run, а не ручным SQL на production.
- Каждая интеграция имеет timeout, retry policy и понятную ошибку.
- Решения, меняющие этот roadmap, фиксируются в `ARCHITECTURE.md` или отдельном ADR.

## 3. Этап 0. Baseline и подготовка окружения

Оценка: 3-4 часа.

Статус: выполнен 2026-07-21. Фактический baseline описан в
`DEVELOPMENT_BASELINE.md`.

Работы:

- зафиксировать текущий commit и успешный `npm run preflight`;
- проверить ветку, `.gitignore`, отсутствие секретов в Git;
- исправить локальный `sys_temp_dir` Composer;
- зафиксировать версии PHP, Composer, Node.js и npm;
- определить локальный способ запуска PostgreSQL и Redis;
- создать `.env.example` без секретов;
- сохранить контрольные сценарии текущего Telegram-бота;
- отметить старую Node.js БД как legacy source до cutover.

Acceptance gate:

- текущий бот проходит preflight;
- Composer может устанавливать зависимости;
- PostgreSQL и Redis доступны локально;
- есть воспроизводимая инструкция запуска;
- рабочее дерево содержит только осознанные изменения.

## 4. Этап 1. Laravel foundation и структура репозитория

Оценка: 6-8 часов.

Статус: выполнен 2026-07-21. Фактический результат и команды проверки описаны в
`STAGE_1_FOUNDATION.md`.

Работы:

- создать `backend/` на Laravel 13;
- подключить официальный Vue/Inertia/TypeScript starter kit;
- настроить Tailwind и базовые admin layouts;
- оставить публичную регистрацию выключенной;
- создать одного admin user через безопасную command/seeder;
- подключить PostgreSQL, Redis, Queue и Scheduler;
- настроить форматирование, static analysis и test runner;
- подготовить health endpoints и structured logging;
- подготовить целевую папку `bot/` и выполнить механический перенос отдельным коммитом;
- добавить CI-команды для backend и bot.

Acceptance gate:

- админ может войти и выйти;
- Laravel использует PostgreSQL и Redis;
- queue job выполняется локально;
- scheduler test command запускается;
- frontend собирается без TypeScript/lint ошибок;
- Node bot после перемещения проходит прежний preflight без изменения поведения.

## 5. Этап 2. Новая схема данных и базовые модели

Оценка: 8-10 часов.

Статус: выполнен 2026-07-21. Фактический результат и проверки описаны в
`STAGE_2_DOMAIN_SCHEMA.md`.

Работы:

- миграции `customers`, contacts и identities;
- миграции categories, vehicles и photos;
- миграции seasons и price tiers;
- миграции bookings, price snapshots и status history;
- единый `vehicle_occupancies` с exclusion constraint;
- documents, audit, outbox, service API clients и idempotency;
- индексы для availability, timeline, filters и scheduler;
- PHP enums/value objects для status, source, vehicle type и money/date rules;
- factories и seeders для тестовых данных;
- privacy-aware serialization: внутренние поля не попадают в API случайно.

Acceptance gate:

- новая БД поднимается с нуля одной командой;
- rollback/re-run миграций проверен локально;
- schema tests подтверждают constraints и индексы;
- Telegram не является обязательным атрибутом Customer/Booking;
- `website` допустим как будущий booking source.

## 6. Этап 3. Цены и импорт техники

Оценка: 10-13 часов.

Статус: выполнен 2026-07-21. Фактический результат и команды проверки описаны в
`STAGE_3_PRICING_IMPORT.md`.

Работы:

- реализовать `PricingService` по утвержденным сезонам и уровням;
- расчет включительных дней и пересечения сезонов;
- месячный тариф для 30+ дней;
- half-up округление до 100 бат;
- versioned immutable price snapshots;
- ручной override с обязательной причиной и audit;
- importer Excel/подготовленного CSV с `dry-run`;
- импорт всей техники, включая hidden/inactive;
- импорт итоговых тарифов `1/7/14/21/month`;
- отчет о технике без цены и неоднозначном mapping;
- контрольные golden tests на примерах из Excel.

Acceptance gate:

- все контрольные расчеты совпадают с утвержденными правилами;
- аренда на границе сезонов считается по дням;
- повторный импорт не создает дубли;
- техника без полного прайса не доступна для бронирования;
- изменение тарифа не меняет существующий snapshot.

## 7. Этап 4. Booking и Availability core

Оценка: 14-18 часов.

Статус: выполнен 2026-07-21. Фактический результат и команды проверки описаны в
`STAGE_4_BOOKING_CORE.md`.

Работы:

- реализовать booking state machine;
- actions для create pending/approved, approve, activate, complete;
- admin/client cancel, expire и no-show;
- изменение дат с повторной доступностью и новым price snapshot;
- occupancy lifecycle для blocking statuses и maintenance;
- транзакции, `FOR UPDATE` и обработка exclusion conflict;
- idempotency mutation operations;
- status history, audit и domain events;
- business error codes;
- unit, feature и concurrency tests.

Acceptance gate:

- разрешены только утвержденные переходы;
- `no_show` возможен только из `approved`;
- pending/approved/active и maintenance блокируют технику;
- два параллельных запроса дают одну бронь и один `409`;
- изменение дат атомарно пересчитывает цену и занятость;
- отмененная/expired бронь освобождает технику;
- прямое изменение статуса вне state machine отсутствует.

## 8. Этап 5. Основная админка бронирований

Оценка: 15-19 часов.

Статус: выполнен 2026-07-21. Фактический результат и проверки описаны в
`STAGE_5_BOOKINGS_ADMIN.md`.

Работы:

- рабочий admin shell и навигация;
- таблица броней с серверной пагинацией;
- фильтры, поиск, сортировка и URL state;
- карточка брони и status history;
- approve/cancel/no-show/activate/complete;
- изменение дат и показ price breakdown;
- ручная финальная цена с причиной;
- ручное создание клиента и брони из звонка/мессенджера;
- уведомление клиента после изменения дат или цены;
- loading, empty, validation, conflict и retry states.

Acceptance gate:

- админ выполняет полный жизненный цикл брони без Telegram;
- ручная бронь проходит тот же Booking Action, что и bot booking;
- конфликт занятости показывается без потери введенной формы;
- audit содержит actor и изменения;
- интерфейс работает на desktop и допустим на mobile без пересечений элементов.

## 9. Этап 6. Timeline / шахматка

Оценка: 10-13 часов.

Статус: выполнен 2026-07-21. Фактический результат и проверки описаны в
`STAGE_6_TIMELINE.md`.

Работы:

- строки техники и календарные колонки;
- загрузка данных только выбранного диапазона;
- status colors и maintenance blocks;
- переход между периодами и быстрый возврат к сегодня;
- фильтры по типу, категории, технике и видимости;
- click booking -> booking card;
- click free slot -> предзаполненная ручная бронь;
- sticky headers/vehicle column, horizontal scroll;
- тестирование длинных названий, широкого диапазона и пересечений.

Drag-and-drop переноса брони не входит в этап.

Acceptance gate:

- timeline и таблица показывают одинаковую занятость;
- блоки корректно отображаются на границе месяца;
- свободный слот нельзя сохранить, если его занял параллельный запрос;
- экран остается рабочим на типичном ноутбуке и планшете;
- открытие карточки не теряет выбранный период и фильтры.

## 10. Этап 7. Техника, фото, цены, клиенты и CSV

Статус: выполнен 2026-07-21. Фактический результат и проверки описаны в
`STAGE_7_CATALOG_CUSTOMERS_EXPORT.md`.

Оценка: 11-14 часов.

Работы:

- CRUD техники и категорий;
- visible/active controls без удаления истории;
- несколько фото, primary photo, сортировка и thumbnails;
- редактор сезонных тарифов с проверкой полноты;
- preview контрольного расчета;
- список и карточка клиента с контактами и бронями;
- приватная загрузка/скачивание документов;
- CSV по текущим фильтрам с UTF-8 BOM/настройкой, совместимой с Excel;
- валидация MIME, размера и доступа к файлам.

Acceptance gate:

- админ может добавить, скрыть и вернуть технику;
- скрытая техника не предлагается клиенту, но видна в старой брони;
- фото отображаются в админке и доступны каналу бота;
- неполный прайс явно помечается и блокирует продажу;
- приватный документ недоступен без admin session;
- CSV открывается в Excel и Google Sheets с корректной кодировкой.

## 11. Этап 8. Bot API и перевод Telegram-бота

Оценка: 14-18 часов.

Статус: выполнен 2026-07-22. Фактическая архитектура, API, конфигурация, cutover,
rollback и результаты проверок описаны в `STAGE_8_BOT_API.md`.

Работы:

- OpenAPI contract `/api/v1/bot`;
- service client token, abilities, rotation и rate limit;
- customer/identity sync;
- vehicle list, availability и quote endpoints;
- create/read/list/cancel booking endpoints;
- document upload;
- request ID, idempotency и стабильные error codes;
- Laravel API client в Node.js с timeout/retry policy;
- поэтапная замена прямых Knex-вызовов;
- Redis-backed bot session;
- feature flag для переключения legacy/Laravel режима;
- contract и end-to-end tests.

Acceptance gate:

- полный клиентский flow работает через Laravel;
- бот не рассчитывает цену и не проверяет пересечения локально;
- после cutover бот не имеет DB credentials;
- повтор Telegram update не создает дубликат;
- временная ошибка API объясняется клиенту и допускает безопасный повтор;
- текущие языки, кнопки и основной UX сохранены.

## 12. Этап 9. Автоматизация и уведомления

Оценка: 8-10 часов.

Работы:

- notification outbox и queue delivery;
- новая pending заявка для админа;
- approve/cancel/expired сообщения клиенту;
- late-cancel message с runtime manager contact;
- expire pending через 24 часа;
- напоминание в день выдачи;
- напоминание за час при наличии времени;
- retry/backoff, failed jobs и защита от дублей;
- housekeeping idempotency/outbox;
- monitoring hooks для ошибок доставки.

Acceptance gate:

- каждое событие отправляет не более одного логического уведомления;
- временный сбой Telegram повторяется через очередь;
- после восстановления worker недоставленные сообщения уходят;
- expired освобождает технику и уведомляет клиента атомарно по смыслу;
- тексты соответствуют утвержденному ТЗ.

## 13. Этап 10. Release hardening и deployment

Оценка: 13-17 часов.

Работы:

- полный regression suite;
- end-to-end приемочные сценарии;
- проверка нагрузки и конкурентных броней;
- security review auth, documents, uploads и secrets;
- production env template;
- Nginx/PHP-FPM/bot/worker/scheduler process configs;
- backup PostgreSQL и persistent files;
- тест восстановления backup;
- staging deployment и smoke tests;
- финальный импорт справочников;
- cutover runbook и rollback plan;
- operational README: запуск, logs, failed jobs, backup, restore;
- наблюдение после переключения.

Acceptance gate:

- все acceptance criteria основного ТЗ пройдены;
- production конфигурация воспроизводима;
- backup реально восстановлен в тестовую среду;
- queue, scheduler и bot автоматически перезапускаются;
- rollback plan проверен без удаления старых данных;
- заказчик принимает ключевые сценарии в staging.

## 14. Оценка и резерв

Сумма этапов разработки: 112-144 часа.

Integration/review reserve: 12-20 часов. Он покрывает найденные при интеграции
расхождения старого bot flow, реальные данные Excel, environment issues и приемочные
правки без расширения функционального состава.

Итоговый реалистичный коридор: 124-164 часа.

Для договоренности с заказчиком безопасно использовать: 130-165 часов.

Оценка учитывает работу через AI/Codex, существующий Node.js-код и уже проведенный
аудит. Она предполагает, что состав первого релиза не расширяется онлайн-оплатой,
клиентским сайтом, сложными ролями или сменой техники внутри брони.

## 15. Зависимости между этапами

```text
Baseline
   -> Laravel foundation
      -> Data model
         -> Pricing/import
         -> Booking/availability
              -> Admin bookings
              -> Bot API/integration
              -> Timeline
         -> Vehicles/photos/clients/CSV
              -> Automation/notifications
                   -> Release hardening/deployment
```

UI-задачи можно частично параллелить после стабилизации Actions и DTO. Bot migration
начинается после фиксации API contract и booking core. Production deployment начинается
только после concurrency и end-to-end tests.

## 16. Контрольные релизные сценарии

1. Клиент Telegram выбирает даты, видит цену, создает pending-заявку.
2. Два клиента одновременно пытаются забронировать одну технику; проходит один.
3. Админ подтверждает бронь, клиент получает сообщение.
4. Админ создает approved-бронь по телефонному звонку.
5. Админ изменяет даты, цена пересчитывается, клиент уведомляется.
6. Админ задает ручную цену, причина и обе суммы сохраняются.
7. Pending без решения истекает через 24 часа и освобождает технику.
8. Approved можно отметить no-show, active - нельзя.
9. Клиент отменяет approved за 24+ часа.
10. При сроке менее 24 часов бот направляет клиента менеджеру и не отменяет бронь.
11. Аренда через два сезона использует один tier и дневные ставки разных сезонов.
12. Бронь на 30+ дней использует month tier.
13. Скрытая техника не доступна новым клиентам, но не ломает историю.
14. Приватный документ видит админ, прямой публичный URL отсутствует.
15. CSV совпадает с выбранными фильтрами.
16. Перезапуск worker не дублирует уведомления.
17. Недоступность Laravel не приводит к локальной записи брони ботом.

## 17. Основные риски и управление ими

| Риск | Мера |
|---|---|
| Неоднозначности Excel | dry-run importer, external code, отчет и golden tests |
| Двойная бронь | transaction, row lock, occupancy exclusion constraint, race tests |
| Регрессия Telegram UX | baseline сценарии, feature flag, contract/E2E tests |
| Дубли уведомлений | transactional outbox и idempotency key |
| Утечка документов | private disk, policy controller, upload validation |
| Расхождение admin и bot | общие Application Actions, запрет бизнес-логики в каналах |
| Зависимость от одного сервера | backup/restore, process supervision, documented recovery |
| Будущий сайт потребует перепись | channel-independent Customer и reusable use cases |
| Разрастание scope | acceptance gate и отдельная оценка новых модулей |

## 18. Definition of Done production-релиза

Релиз завершен, когда одновременно выполнено следующее:

- Laravel является единственным writer бизнес-данных;
- Telegram и admin проходят утвержденные end-to-end сценарии;
- нет известных способов создать пересекающиеся blocking bookings;
- pricing golden tests и Excel import reconciliation проходят;
- очередь, scheduler, уведомления и retry работают;
- фото публичны только по правилам, документы приватны;
- audit позволяет восстановить историю критичных изменений;
- deployment, backup и restore проверены;
- нет секретов в Git и критических ошибок в logs;
- заказчик подтвердил работу staging по acceptance checklist;
- документация соответствует фактической реализации.

## 19. Следующее действие

Начать этап 9: подключить delivery worker к существующему `notification_outbox`,
реализовать автоexpiry pending-заявок, Telegram-уведомления и напоминания с
deduplication/retry. До приемки текстов указать реальный runtime-контакт менеджера.
