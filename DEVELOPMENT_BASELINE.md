# Development baseline

Дата: 2026-07-21.

Статус: этап 0 технического roadmap.

## 1. Зафиксированная точка

- архитектура и roadmap: commit `a1d2ff7`;
- текущий Node.js-бот остается behavioral baseline до cutover;
- основной аудит: `PRE_UPDATE_AUDIT.md`;
- аудит rental flow и нагрузки: `RENTAL_ROUTE_LOAD_AUDIT.md`;
- единая проверка legacy-бота: `npm run preflight`.

Старая Node.js/PostgreSQL реализация является только legacy source. После cutover
Laravel становится единственным writer бизнес-данных. Постоянный dual-write запрещен.

## 2. Проверенное локальное окружение

| Компонент | Версия/состояние |
|---|---|
| PHP | 8.3.6, OSPanel |
| Composer | 2.10.2; обновлен со штатной возможностью rollback на 2.6.6 |
| Node.js | 22.20.0 |
| npm | 10.9.3 |
| PostgreSQL | 17.5, подключение legacy-бота работает |
| Legacy database | `bike_rent` |
| Legacy DB timezone | `Europe/Moscow` |
| Business timezone | `Asia/Bangkok` |
| Redis | OSPanel Redis 7.2.3, `127.0.0.1:6379` |
| Docker | не установлен и не требуется для первого локального запуска |

Для новой Laravel-базы системные timestamps хранятся в UTC. Даты и время аренды
интерпретируются в `Asia/Bangkok`. Текущая timezone legacy-базы не переносится как
архитектурное решение.

## 3. Локальный Redis

Redis уже доступен как модуль OSPanel. Воспроизводимый запуск из PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-local-redis.ps1
```

Скрипт:

- использует `REDIS_HOME`, если переменная задана;
- иначе ищет Redis 7.2/7.0 в стандартной папке OSPanel;
- слушает только `127.0.0.1`;
- хранит локальные runtime-файлы в ignored-папке `.runtime/redis`;
- не запускает второй процесс, если Redis уже отвечает.

Проверка PostgreSQL и Redis:

```powershell
npm run check:environment
```

## 4. Composer

PHP загружает конфигурацию:

```text
C:\OSPanel\modules\PHP-8.3\PHP\php.ini
```

`sys_temp_dir` указывает на `C:/OSPanel/temp/PHP-8.3/default`. Папка существует и
Composer корректно использует ее при обычном запуске. Внутри ограниченного sandbox
Composer требует запуск с разрешением на внешнюю temp-папку; это не дефект локальной
PHP-конфигурации.

Composer обновлен с 2.6.6 до stable 2.10.2 командой `composer self-update --stable`.

Перед scaffold Laravel выполнить:

```powershell
composer diagnose --no-interaction
```

## 5. Env и секреты

- реальный `.env` исключен из Git;
- безопасный набор переменных находится в `.env.example`;
- `PG_PASSWORD` больше не имеет fallback-пароля в `knexfile.js`;
- реальные Telegram/PostgreSQL/Google credentials не должны попадать в команды,
  логи, документацию или Git;
- Laravel и bot получат отдельные `.env` после разделения папок.

## 6. Контрольные сценарии legacy-бота

До каждого этапа миграции должны сохраняться сценарии:

1. Bot module загружается без ошибки.
2. Клиент проходит текущий wizard выбора техники и дат.
3. Перед созданием блокирующей заявки доступность проверяется повторно.
4. Pending/approved/active блокируют технику.
5. Отмена освобождает технику.
6. Rental service отклоняет недопустимые переходы.
7. В базе нет неизвестных статусов, дублей public ID и некорректных диапазонов дат.

Автоматизированная часть выполняется `npm run preflight`. Клиентский Telegram flow
дополнительно проверяется end-to-end перед и после переключения на Laravel API.

## 7. Известное предупреждение baseline

Legacy data check сообщает об активной технике без цен:

```text
vehicle_id=6, ADV 160cc, ABS, Black, 2022, prices=0/15
```

Это не исправляется в старом справочнике: новая техника и финальные цены будут
импортированы в Laravel из утвержденного файла. До cutover этот warning остается
явно разрешенным только в `check:data:preflight`.

## 8. Acceptance gate этапа 0

- [x] архитектура и roadmap закоммичены;
- [x] текущий bot preflight проходит;
- [x] `.env` исключен из Git и создан `.env.example`;
- [x] PHP, Composer, Node.js и npm проверены;
- [x] PostgreSQL доступен;
- [x] Redis установлен, запущен и отвечает;
- [x] hardcoded fallback-пароль удален;
- [x] legacy database помечена как source до cutover;
- [x] повторные environment-check и bot preflight после изменений проходят.

Этап 0 закрыт. Следующий этап - Laravel foundation в `backend/`.
