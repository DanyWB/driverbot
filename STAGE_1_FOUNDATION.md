# Этап 1. Laravel foundation и структура репозитория

Дата закрытия: 2026-07-21.

Статус: выполнен.

## 1. Результат

Репозиторий разделен на два приложения с общей документацией и командами:

```text
phangan/
  backend/   Laravel 13, Vue 3, TypeScript, Inertia
  bot/       существующий Node.js Telegram-бот
  scripts/   общие локальные операции
```

Перенос бота был механическим. Бизнес-логика и данные legacy-приложения на этом этапе
не менялись. Корневой `package.json` делегирует запуск и preflight в `bot/`.

## 2. Laravel foundation

- Laravel Framework 13.21.1 и PHP 8.3.
- Vue 3.5, TypeScript, Inertia 3, Vite 8 и Tailwind CSS 4.
- Официальный starter kit сохранен как основа UI и аутентификации.
- Публичная регистрация отключена на уровне Fortify и маршрутов.
- Администратор создается командой `admin:create`; пароль вводится скрыто и должен
  соответствовать усиленным требованиям.
- Корневой маршрут ведет в защищенную admin-зону; гостя перенаправляет на login.
- Базовый dashboard сделан как рабочая административная поверхность, без landing page.

## 3. Данные и инфраструктура

- Laravel использует отдельные PostgreSQL role/database: `drive_phangan_app` и
  `drive_phangan`.
- Credentials генерируются локально и записываются только в ignored `backend/.env`.
- Redis используется для session, cache и queue через PHP extension `phpredis`.
- Application timezone: `UTC`; бизнес-даты и расписания используют отдельную
  `BUSINESS_TIMEZONE=Asia/Bangkok`.
- Добавлены liveness `GET /health/live` и readiness `GET /health/ready` для PostgreSQL
  и Redis.
- Middleware создает или сохраняет безопасный `X-Request-ID`, добавляет его в response
  и logging context.

## 4. Queue и scheduler

Команда `operations:queue-probe` отправляет безвредную job в Redis. Проверено локально:
job была обработана отдельным `queue:work --once`, затем результат найден в Redis cache.

Scheduler содержит ежедневную очистку failed jobs в `03:00` по `Asia/Bangkok` с
distributed lock. Расписание проверяется через `php artisan schedule:list`.

## 5. Quality gates

Backend:

- Laravel tests: 44 tests, 151 assertions;
- PHPStan/Larastan: без ошибок;
- Laravel Pint: без ошибок;
- ESLint: без ошибок;
- Prettier: без ошибок;
- Vue TypeScript check: без ошибок;
- production frontend build: успешно.

Legacy bot:

- JavaScript syntax;
- migration status;
- module load;
- rental service checks;
- data integrity checks.

Полный `npm run preflight` после переноса проходит. Остается ранее согласованное
content warning: активный `ADV 160` не имеет матрицы цен. Оно будет закрыто новым
импортом техники и не относится к Laravel foundation.

Единый корневой `npm run precheck` последовательно запускает bot preflight,
production-сборку frontend и полный backend CI.

## 6. CI и безопасность

- GitHub Actions проверяет backend build, PHP/TypeScript/static analysis и tests.
- Отдельный workflow проверяет синтаксис legacy-бота без production credentials.
- Dependabot настроен для Composer, backend npm, bot npm и GitHub Actions.
- Реальные `.env`, dependencies, build artifacts и runtime-файлы исключены из Git.
- Public registration отсутствует; admin password не передается аргументом командной
  строки.

Dependency audit:

- backend npm: 0 известных vulnerabilities;
- backend Composer: 0 известных security advisories;
- совместимое обновление legacy bot dependencies устранило high advisory;
- в legacy `googleapis@133` остаются 7 moderate findings транзитивного `uuid`. Полное
  устранение требует отдельного major-upgrade до `googleapis@173` или удаления старой
  Google Sheets интеграции. Это production blocker, но не blocker локального этапа 2:
  уязвимый UUID API напрямую проектом не вызывается, а интеграция до cutover опциональна.

Актуализация 2026-07-22: blocker закрыт на этапе 8 обновлением до `googleapis@173`;
`npm audit --omit=dev` возвращает 0 известных vulnerabilities.

## 7. Acceptance gate

- [x] Защищенные login/logout и dashboard доступны.
- [x] Публичная регистрация возвращает 404.
- [x] Laravel подключен к PostgreSQL и Redis.
- [x] Redis queue job обработана реальным worker.
- [x] Scheduler зарегистрирован и отображается.
- [x] Frontend собирается без lint/format/type ошибок.
- [x] Backend tests и static analysis проходят.
- [x] Bot после переноса проходит прежний preflight.
- [x] Health endpoints готовы для локального и production monitoring.

## 8. Следующий этап

Этап 2: создать новую Laravel-схему и базовые модели для клиентов, контактов, техники,
цен, бронирований, занятости, документов, аудита, idempotency и outbox. Импорт Excel и
переключение Telegram-бота на API на этом этапе не выполняются.
