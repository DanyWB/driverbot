# Этап 3. Цены и импорт техники

Дата закрытия: 2026-07-21.

Статус: выполнен.

## 1. Результат

Laravel стал единственным расчетным контуром цен. Excel используется только как
версионируемый источник техники и финальных тарифов, его формулы не переносятся в
runtime.

Реализовано:

- `PricingService` с точной rational/decimal-арифметикой без binary float;
- включительный расчет календарных дней;
- единый tier по полной длительности аренды;
- разбивка аренды по сезонам для каждой даты;
- месячный tier для 30+ дней;
- half-up округление итоговой суммы до 100 THB;
- неизменяемые versioned price snapshots;
- ручной override только новой версией, с обязательной причиной и audit log;
- dry-run и apply импорт `.xlsx`;
- журнал import runs с именем файла, SHA-256, статусом и итоговым отчетом;
- проверка целостности нормализованного каталога;
- диагностический расчет цены через production `PricingService`.

## 2. Фактический источник

Файл: `Drive 2026 - Booking Shedule_MAY 2026 -Bot Reference.xlsx`.

SHA-256:
`87612df5a7d2112bdd09f27a9069475640cacebb836ca5b3f75c64f0532f3626`.

Использованные листы:

- `Prices FINAL!!!`, блок `ALL VEHICLES LIST`;
- `LIST BIKES`.

Результат разбора и локального импорта:

- 32 единицы техники;
- 31 полная матрица цен;
- 465 строк цен: 31 x 3 сезона x 5 tiers;
- 29 активных и видимых для бронирования позиций;
- 3 неактивных позиции из checkbox-значений файла;
- 1 позиция без цены и без доступа к бронированию.

Неактивные позиции:

- `pcx-blue-160-2026-A`;
- `adv-green-160-2026-A`;
- `unpriced-list-24-xmax-300cc-abs-black-2019`.

У последнего Xmax нет строки в финальном прайс-блоке. Он импортирован в каталог с
`0/15` тарифов, `is_active=false` и `is_visible_for_booking=false`.

## 3. Алгоритм цены

1. Даты проверяются и считаются включительно.
2. По общей длительности выбирается `1d`, `7d`, `14d`, `21d` или `month`.
3. Каждая дата относится к high, middle или low season.
4. Для каждой группы считается точная дробь `package_total / anchor_days * days`.
5. Группы складываются без промежуточного округления.
6. `calculated_total` сохраняется с шестью знаками.
7. Итог округляется до ближайших 100 THB методом half-up.

Контрольный пример на реально импортированном `click-blue-125-2019-A`:

```text
2027-03-17 .. 2027-04-15 = 30 дней
tier = month
high:   15 * (4400 / 30) = 2200
middle: 15 * (3900 / 30) = 1950
calculated_total = 4150
rounded_total = final_total = 4200 THB
```

## 4. Безопасность импорта

- без `--apply` команда всегда выполняет dry-run;
- источник идентифицируется SHA-256;
- строки связываются по стабильному `external_code`, а не только по названию;
- повторный apply обновляет 32 записи и не создает дубли;
- источник checkbox управляет активностью, но вся техника сохраняется в каталоге;
- неполная матрица автоматически запрещает клиентское бронирование;
- существующие price rows синхронизируются с источником и не остаются устаревшими;
- каждый apply создает `data_import_runs` и одну агрегированную audit-запись;
- пути к workbook не сохраняются и не попадают в Git.

## 5. Команды

```powershell
# структура и диагностические ячейки
php -d memory_limit=1024M artisan pricing:inspect-workbook "C:\path\pricing.xlsx"

# dry-run по умолчанию
php -d memory_limit=1024M artisan pricing:import-workbook "C:\path\pricing.xlsx" --json

# применение
php -d memory_limit=1024M artisan pricing:import-workbook "C:\path\pricing.xlsx" --apply

# аудит БД
php artisan pricing:audit --json

# контрольный расчет
php artisan pricing:quote click-blue-125-2019-A 2027-03-17 2027-04-15
```

В production путь можно задать через `PRICING_WORKBOOK_PATH`.

## 6. Проверки

- Composer dependencies ограничены совместимыми major-версиями;
- Laravel Pint и PHPStan/Larastan level 7 проходят;
- 71 Laravel tests: 69 passed, 2 PostgreSQL-only skipped в SQLite-наборе;
- отдельные PostgreSQL exclusion tests: 2 passed;
- migration rollback/re-run этапа 3 выполнен;
- реальный импорт применен повторно без дублей;
- `pricing:audit` прошел без ошибок;
- golden tests покрывают границы tier, сезоны, округление и ручной override.

## 7. Следующий этап

Этап 4: booking state machine и availability core. Он будет использовать уже готовые
`PricingService`, snapshots и PostgreSQL exclusion constraint при создании, изменении
дат, подтверждении, отмене, expiry и no-show.
