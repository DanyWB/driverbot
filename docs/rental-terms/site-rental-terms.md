# Rental Terms — content for the website (developer request)

Page: `/{en,ru,uk}/policies/terms` (already exists, currently shows the "TEST PREVIEW ONLY" placeholder).

## ✅ Ready-made markup: `docs/site-terms/terms-preview.html`

Open it in a browser — EN / RU / UK / TH switch, desktop + mobile checked (375px, no horizontal scroll).

How to integrate (Laravel `website` views):
1. **Style** follows the new design canvas ("Drive Phangan Website", Home page B): navy `#0A0E18` / cards `#121828` / band `#0D1220`, orange `#FF7A1A` as the only accent (no cyan), Anton headings, Manrope body, pill buttons, inline stroke SVG icons (no emoji).
2. **CSS** — copy the block between `COPY FROM HERE` / `COPY TO HERE` into the site stylesheet. All classes are prefixed `tp-`; colours are local CSS variables on `.tp`, so they don't touch the rest of the site. Needs the Manrope + Anton Google Fonts.
3. **HTML** — each language is one `<div class="tp" data-terms-lang="…">` block (hero + 6 cards → 7 accordion sections → contact card). It's a full-width page body: render it in place of the current `.policy-page` section, e.g. as Blade partials `website/policies/terms/{en,ru,uk}.blade.php`. Drop the `data-terms-lang` attribute and the `is-active` class — they're for the preview switch only.
4. Remove the preview-only bits: `.preview-langs` buttons, the `[data-terms-lang]` display rule and the `<script>`.
5. Today the page body comes from `config('website.policies.terms.{locale}')` as plain text. Render the partial instead (trusted static markup — don't feed it through the CMS `terms` page, its blocks don't support tables/HTML, and `WEBSITE_CMS_POLICY_PUBLISHING_ENABLED` is off).
6. `<title>`/H1: EN "Rental terms", RU "Условия аренды", UK "Умови оренди".
   TH "เงื่อนไขการเช่า" — **Thai block added** (`data-terms-lang="th"`). The site currently has only en/ru/uk: add `th` to `config('website.locales')` and the language switcher. Thai needs the **Noto Sans Thai** font (Anton/Manrope have no Thai glyphs); the CSS block already switches Thai headings to it.
7. Accordions are native `<details>` — no JS, keyboard accessible. Section 01 is open by default.
8. Contacts in the CTA are the same as in the site footer (Telegram `KohPhanganDrive`, WhatsApp). If the site has them in config/CMS settings, use those values instead of hard-coded links.
9. Bump `WEBSITE_TERMS_VERSION` when these terms go live (guest booking stores the accepted terms version).
10. **Theme tokens.** All colours, fonts, radius and heading size come from CSS variables on `.tp` (`--tp-bg`, `--tp-band`, `--tp-card`, `--tp-text`, `--tp-soft`, `--tp-muted`, `--tp-orange`, `--tp-orange-hi`, `--tp-on-accent`, `--tp-font-display`, `--tp-display-weight`, `--tp-font-body`, `--tp-radius`, `--tp-h1-size`); tints are derived with `color-mix()`. The owner tunes them in the preview's **Дизайн** panel and sends you the result via **Скопировать CSS** — paste that block after the terms CSS. The panel itself (`DESIGN PANEL` block at the end of the file) is a preview tool: do not copy it to the site.

## Layout notes for the developer

1. **Top: "At a glance" — 4–6 short cards** (icon + 1 line). Most visitors read only this.
2. **Below: accordion sections** (collapsed by default on mobile, first one open).
3. Prices in THB / USD — keep as plain text so the manager can edit them in one place (lang files / CMS).
4. Bottom CTA: "Have a question? Message us" → Telegram / WhatsApp.
5. Bot-only wording ("in the bot") is removed — on the site it should say "when booking".

---

## EN

### Rental terms

#### At a glance
- 🪪 **Passport + driving licence**, age 18+
- 💵 **Deposit** — passport or cash, the amount depends on the model
- 💳 **Pay in cash or online** — no hidden fees
- 📍 **Pick-up in Ban Tai** — free delivery within Ban Tai, island-wide delivery available
- 🔧 **Damage is charged at the real cost** of original parts and labour. No hidden fees. No inflated charges
- ⏱️ **Book at least 3 days ahead** — minimum rental 5 days. We confirm within 48 hours max, usually within a few hours

#### 1. How booking works
- Book **at least 3 days** before your rental starts. The minimum rental period is **5 days**.
- We confirm your request within **48 hours** at most — usually within a few hours.
- Prepayment and deposit are agreed separately.

#### 2. Documents
On pick-up please bring:
- Passport
- Driving licence (an International Driving Permit is recommended)
- You must be **18 or older**

Every rental comes with a **signed rental agreement**.

#### 3. Deposit & payment
**Deposit** — your passport or a cash deposit. The amount depends on the model:

| Vehicle | Deposit |
|---|---|
| Regular scooters (110–160cc) | $100–300 |
| Maxi scooters (300–350cc) | from $400 |
| Cars | from $400 |

**Payment**
- **Booking prepayment** — from 1,500 THB, non-refundable.
- We accept: cash, Visa / Mastercard, bank transfer, USDT.

All prices are agreed in advance, with no hidden fees.

#### 4. Returning early
If you return the vehicle early, the following applies:
- **Daily rental:** returning less than 3 days before the end of the agreement — the unused days are not refunded.
- **Monthly rental:** returning less than 7 days before the end of the agreement — the rental price is not recalculated.
- **In all other cases:** the price is recalculated at the standard rate for the actual period of use.

**Early return service fee — 300 THB.** Covers processing the early return and the lost chance to pre-book the vehicle online for the freed-up period.

#### 5. Extending your rental
- Tell us about an extension **well in advance** — in high season vehicles are often booked for the very next day.
- **Half-day extension** (e.g. afternoon to evening) is possible by arrangement, usually about **50% of the daily price**.

#### 6. Damage & responsibility
- We take **photos and a video** of the scooter together with you before the rental.
- Damage is paid at the **real cost of original parts and labour**. In rare cases, downtime during repairs may also be charged.
- In most cases the deposit is enough to cover it.

The renter is responsible for:
- **Fines and damage to third parties** — any fine or damage must be covered by the renter in full.
- **Flat tyre** — fixed by the renter at the nearest repair shop (usually 100–200 THB).
- **Dead battery from lights left on** — service fee 100–400 THB, depending on location.

[ View rental agreement ] — button, link to the agreement (URL / PDF to be provided)

#### 7. Pick-up & delivery
| Where | Price |
|---|---|
| Our office in Ban Tai | Free |
| Delivery within Ban Tai | Free |
| Thong Sala pier | 200 THB one way · 300 THB round trip |
| Around the island | 100–400 THB one way (by distance) |

---

## RU

### Условия аренды

#### Коротко о главном
- 🪪 **Паспорт + права**, возраст от 18 лет
- 💵 **Депозит** — паспорт или деньги, сумма зависит от модели
- 💳 **Оплата наличными или онлайн** — без скрытых платежей
- 📍 **Выдача на Бантае** — доставка по Бантаю бесплатно, есть возможность доставки по острову
- 🔧 **Повреждения — по реальной стоимости** оригинальных запчастей и работы. Без скрытых платежей и завышенных цен
- ⏱️ **Бронирование минимум за 3 суток** — минимальный срок аренды 5 дней. Подтверждаем заявку максимум в течение 48 часов, обычно — в течение нескольких часов

#### 1. Как проходит бронирование
- Бронируйте **минимум за 3 суток** до начала аренды. Минимальный срок аренды — **5 дней**.
- Подтверждаем заявку максимум в течение **48 часов** — обычно в течение нескольких часов.
- Предоплата и депозит согласуются отдельно.

#### 2. Документы
При получении техники нужны:
- Паспорт
- Водительские права (желательно международные)
- Возраст **от 18 лет**

Каждая аренда оформляется **договором**, который подписывается при выдаче.

#### 3. Депозит и оплата
**Депозит** — паспорт или денежный залог. Сумма зависит от модели:

| Техника | Депозит |
|---|---|
| Обычные скутеры (110–160cc) | 100–300$ |
| Макси-скутеры (300–350cc) | от 400$ |
| Авто | от 400$ |

**Оплата**
- **Предоплата за бронирование** — от 1 500 бат, не возвращается.
- Принимаем: наличные, Visa / Mastercard, банковский перевод, USDT.

Все условия согласуем заранее, никаких скрытых платежей.

#### 4. Досрочный возврат
При досрочном возврате транспортного средства применяются следующие условия:
- **Посуточная аренда:** при возврате менее чем за 3 дня до окончания договора неиспользованные дни не компенсируются.
- **Помесячная аренда:** при возврате менее чем за 7 дней до окончания договора перерасчёт стоимости аренды не производится.
- **В остальных случаях:** стоимость аренды пересчитывается по стандартному тарифу за фактический период использования транспортного средства.

**Сервисный сбор за досрочный возврат — 300 THB.** Учитывает обработку досрочного возврата и потерю возможности предварительного онлайн-бронирования транспортного средства на освобождаемый период.

#### 5. Продление
- Предупреждайте о продлении **заблаговременно** — в высокий сезон технику часто бронируют день в день.
- **Продление на полдня** (например, с обеда до вечера) — по договорённости, обычно около **50% стоимости дня**.

#### 6. Повреждения и ответственность
- Перед арендой вместе с вами делаем **фото и видео** байка.
- Повреждения оплачиваются по **реальной стоимости оригинальных запчастей и работы**. В редких случаях может учитываться простой на время ремонта.
- В большинстве случаев достаточно депозита.

Арендатор несёт ответственность за:
- **Штрафы и ущерб третьим лицам** — арендатор возмещает любой штраф или ущерб полностью.
- **Пробитое колесо** — арендатор ремонтирует сам в ближайшей мастерской (обычно 100–200 бат).
- **Сел аккумулятор из-за включённых фар** — сервисный сбор 100–400 бат, в зависимости от локации.

[ Посмотреть договор аренды ] — кнопка, ссылка на договор (URL / PDF нужно предоставить)

#### 7. Получение и доставка
| Где | Стоимость |
|---|---|
| Наш офис на Бантае | Бесплатно |
| Доставка по Бантаю | Бесплатно |
| Пирс Тонгсала | 200 бат в одну сторону · 300 бат в обе стороны |
| По острову | 100–400 бат в одну сторону (по расстоянию) |

---

## Open questions for the owner (before publishing)

1. **Rental agreement link** — the "View rental agreement" button currently points to `#rental-agreement`. Provide the agreement (PDF or page URL) for EN / RU / UK.
2. **Cars** — car deposit is now "from $400". Are car documents / damage rules the same as for scooters (section 6 mentions "байк")?
3. **Is there an office address** in Ban Tai to show (Google Maps link)?
