# Production release acceptance checklist

Дата актуализации: 2026-09-02.

Release разрешен только после прохождения всех обязательных пунктов на staging с production-like
Nginx/PHP-FPM, PostgreSQL и Redis.

## 1. Входные данные и доступы

- [ ] Утверждены финальный список техники и видимость каждой позиции.
- [ ] У всех 29 текущих visible vehicles есть primary photo либо каталог повторно импортирован.
- [ ] Утверждены тарифы, сезоны и контрольные расчеты.
- [ ] Утверждены текст и `BOOKING_TERMS_VERSION`.
- [ ] Получены домен/TLS, Telegram token, numeric admin chat ID и manager username.
- [ ] `BOT_TOKEN` бота и `TELEGRAM_BOT_TOKEN` backend содержат один и тот же Telegram token.
- [ ] Настроен рабочий SMTP для password reset.
- [ ] Создан один active admin, email подтверждён, включены 2FA/passkey.
- [ ] Выпущен отдельный production Bot API service token.
- [ ] `operations:release-preflight --strict` завершен успешно.

## 2. Бизнес-сценарии

- [ ] Telegram-клиент регистрируется и обновляет контакты.
- [ ] Клиент выбирает даты/технику, видит расчет и создает pending.
- [ ] Повтор Telegram update/idempotency key не создает дубль.
- [ ] Админ получает pending и подтверждает бронь; клиент получает approved.
- [ ] Админ вручную создает approved-бронь для клиента без Telegram.
- [ ] Изменение дат пересчитывает цену и отправляет уведомление.
- [ ] Ручная цена хранит расчетную/итоговую сумму и причину.
- [ ] Pending истекает через 24 часа, освобождает технику и уведомляет клиента.
- [ ] `no_show` разрешен только из `approved`.
- [ ] Отмена клиентом за 24+ часа проходит; поздняя отмена ведет к менеджеру.
- [ ] Reminder приходит в день выдачи и за час при наличии pickup time.
- [ ] Скрытая техника не появляется в новом поиске и не ломает историю.
- [ ] CSV соответствует фильтрам таблицы бронирований.
- [ ] Документ доступен только authenticated active admin; прямого public URL нет.

## 3. Конкурентность и нагрузка

- [ ] PostgreSQL constraint/concurrency suite проходит.
- [ ] `LOAD_CLIENTS=10 npm --prefix bot run test:load:api` дает ровно `1x201 + 9x409`.
- [ ] На staging Nginx/PHP-FPM нет 5xx/timeouts, booking-create p95 не выше 3000 ms при 10 клиентах.
- [ ] После race cleanup нет blocking occupancy у отмененной synthetic-брони.
- [ ] Rate limits возвращают 429 и не создают частичные данные.

## 4. Infrastructure и recovery

- [ ] `/health/live` и `/health/ready` возвращают 200.
- [ ] `operations:runtime-status --wait=20` проходит.
- [ ] `REDIS_QUEUE_RETRY_AFTER` больше максимального worker timeout (`120 > 90`).
- [ ] Принудительный restart каждого worker/scheduler/bot завершается автоматическим запуском.
- [ ] Bot работает отдельным system user; bot unit не видит `shared/backend` через `InaccessiblePaths`.
- [ ] Notification worker retry проверен на временной Telegram ошибке.
- [ ] Backup создан и `verify-backup.sh` проходит.
- [ ] Backup реально восстановлен в отдельную `_restore_test` БД.
- [ ] Code rollback возвращает предыдущий release и проходит smoke.
- [ ] Старые production данные при rehearsal не удалялись.
- [ ] Backup скопирован за пределы production-сервера.

## 5. Security и приемка

- [ ] В Git нет `.env`, tokens/private keys; `npm run check:secrets` проходит.
- [ ] `APP_DEBUG=false`, HTTPS-only cookie, CSP, HSTS и trusted host активны.
- [ ] Nginx не исполняет произвольные `.php` и не отдает dotfiles.
- [ ] Upload size/type/dimensions и private documents проверены.
- [ ] Composer/npm audits не содержат high/critical production vulnerabilities.
- [ ] `npm run precheck` и полный PostgreSQL suite проходят на release commit.
- [ ] Реальный `npm --prefix bot run release:smoke` проходит с Telegram `getMe`.
- [ ] Заказчик принял Telegram flow, таблицу броней, timeline, ручную бронь, каталог и цены.
- [ ] Зафиксированы release commit, backup timestamp, ответственный и время cutover.
