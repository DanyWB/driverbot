import {
    bangkokToday,
    bookingUrlWithDates,
    busyRange,
    isBusy,
    monthDays,
    moveCalendarDay,
    publicEvent,
    quoteParameters,
    selectCalendarDay,
    validDate,
    validPeriod,
} from './domain';
import type { BusyPeriod, Quote } from './domain';

const root = document.querySelector<HTMLElement>('[data-website]');
document.documentElement.classList.add('website-enhanced');
const locale = root?.dataset.locale || 'en';
const earliestPickup = root?.dataset.earliestPickupDate || bangkokToday();
const businessToday = root?.dataset.todayDate || bangkokToday();
const messageNode = document.querySelector('[data-website-messages]');
const messages: Record<string, string> = messageNode
    ? JSON.parse(messageNode.textContent || '{}')
    : {};
const translate = (key: string) => messages[key] || key;
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

function track(name: string, properties: Record<string, unknown> = {}) {
    const payload = publicEvent(name, properties);

    // No provider or network call is installed. Explicit opt-in is required.
    if (root?.dataset.analyticsEnabled === 'true' && payload) {
        document.dispatchEvent(
            new CustomEvent('drive:analytics', { detail: payload }),
        );
    }
}

function setupDisclosures() {
    document
        .querySelectorAll<HTMLDetailsElement>('[data-disclosure]')
        .forEach((details) => {
            const summary = details.querySelector('summary')!;
            let closing: ReturnType<typeof setTimeout> | undefined;
            const close = () => {
                details.classList.remove('is-visible');
                summary.setAttribute('aria-expanded', 'false');
                clearTimeout(closing);
                closing = setTimeout(
                    () => {
                        details.open = false;
                    },
                    reduceMotion.matches ? 0 : 350,
                );
            };
            summary.setAttribute('aria-expanded', String(details.open));
            summary.addEventListener('click', (event) => {
                event.preventDefault();

                if (details.classList.contains('is-visible')) {
                    close();
                } else {
                    clearTimeout(closing);
                    details.open = true;
                    summary.setAttribute('aria-expanded', 'true');
                    requestAnimationFrame(() =>
                        details.classList.add('is-visible'),
                    );
                }
            });
            details.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    close();
                    summary.focus();
                }
            });
        });
}

function setupMenu() {
    const toggle =
        document.querySelector<HTMLButtonElement>('[data-menu-toggle]');
    const menu = document.getElementById('site-navigation');

    if (!toggle || !menu) {
        return;
    }

    const close = () => {
        menu.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    };
    toggle.addEventListener('click', () => {
        const open = menu.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.classList.contains('is-open')) {
            close();
            toggle.focus();
        }
    });
    document.addEventListener('click', (event) => {
        if (
            event.target instanceof Node &&
            !menu.contains(event.target) &&
            !toggle.contains(event.target)
        ) {
            close();
        }
    });
    menu.querySelectorAll('a').forEach((link) =>
        link.addEventListener('click', close),
    );
}

function setupSelects() {
    document
        .querySelectorAll<HTMLElement>('[data-custom-select]')
        .forEach((container) => {
            const details = container.querySelector('details')!;
            const trigger = container.querySelector<HTMLElement>('summary')!;
            const value = container.querySelector<HTMLElement>(
                '[data-select-value]',
            )!;
            const options = [
                ...container.querySelectorAll<HTMLElement>(
                    '[data-select-option]',
                ),
            ];
            const list =
                container.querySelector<HTMLElement>('[role="listbox"]')!;
            let closeTimer: ReturnType<typeof setTimeout> | undefined;
            let isOpen = false;
            let search = '';
            let searchTimer: ReturnType<typeof setTimeout> | undefined;
            options.forEach((option, index) => {
                option.tabIndex = -1;
                option.id = `${list.id}-${index}`;
                option.querySelector('input')!.tabIndex = -1;
            });
            const close = (restoreFocus = false) => {
                isOpen = false;
                trigger.setAttribute('aria-expanded', 'false');
                details.classList.add('is-closing');
                list.inert = true;

                if (restoreFocus) {
                    trigger.focus();
                }

                clearTimeout(closeTimer);
                closeTimer = setTimeout(
                    () => {
                        details.open = false;
                        details.classList.remove('is-closing');
                        list.inert = false;
                    },
                    reduceMotion.matches ? 0 : 350,
                );
            };
            const open = (last = false) => {
                clearTimeout(closeTimer);
                details.classList.remove('is-closing');
                list.inert = false;
                details.open = true;
                isOpen = true;
                trigger.setAttribute('aria-expanded', 'true');
                const selected = options.find(
                    (option) =>
                        option.querySelector<HTMLInputElement>('input')!
                            .checked,
                );
                (selected || options[last ? options.length - 1 : 0])?.focus();
            };
            const choose = (option: HTMLElement) => {
                const input = option.querySelector<HTMLInputElement>('input')!;
                input.checked = true;
                options.forEach((candidate) =>
                    candidate.setAttribute(
                        'aria-selected',
                        String(candidate === option),
                    ),
                );
                value.textContent = option.querySelector('span')!.textContent;
                input.dispatchEvent(new Event('change', { bubbles: true }));
                close(true);
            };
            trigger.addEventListener('click', (event) => {
                event.preventDefault();

                if (isOpen) {
                    close();
                } else {
                    open();
                }
            });
            container.addEventListener('keydown', (event) => {
                const current = options.indexOf(
                    document.activeElement as HTMLElement,
                );

                if (event.key === 'Escape') {
                    event.preventDefault();
                    close(true);
                } else if (event.key === 'Tab') {
                    // Do not trap Tab or restore trigger focus when advancing.
                    // Start native Tab navigation at the combobox, not an inert option.
                    trigger.focus();
                    close();
                } else if (
                    ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)
                ) {
                    event.preventDefault();

                    if (!isOpen) {
                        open(event.key === 'ArrowUp' || event.key === 'End');
                    } else {
                        const next =
                            event.key === 'Home'
                                ? 0
                                : event.key === 'End'
                                  ? options.length - 1
                                  : (current +
                                        (event.key === 'ArrowDown' ? 1 : -1) +
                                        options.length) %
                                    options.length;
                        options[next]?.focus();
                    }
                } else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();

                    if (isOpen && current >= 0) {
                        choose(options[current]);
                    } else {
                        open();
                    }
                } else if (
                    isOpen &&
                    event.key.length === 1 &&
                    !event.ctrlKey &&
                    !event.metaKey &&
                    !event.altKey
                ) {
                    search += event.key.toLocaleLowerCase(locale);
                    clearTimeout(searchTimer);
                    searchTimer = setTimeout(() => {
                        search = '';
                    }, 700);
                    options
                        .find((option) =>
                            option.textContent
                                ?.trim()
                                .toLocaleLowerCase(locale)
                                .startsWith(search),
                        )
                        ?.focus();
                }
            });
            options.forEach((option) =>
                option.addEventListener('click', (event) => {
                    event.preventDefault();
                    choose(option);
                }),
            );
            document.addEventListener('pointerdown', (event) => {
                if (
                    isOpen &&
                    event.target instanceof Node &&
                    !container.contains(event.target)
                ) {
                    close();
                }
            });
            container.addEventListener('focusout', (event) => {
                if (
                    isOpen &&
                    event.relatedTarget instanceof Node &&
                    !container.contains(event.relatedTarget)
                ) {
                    close();
                }
            });
        });
}

function setupDates() {
    document
        .querySelectorAll<HTMLElement>('[data-date-fields]')
        .forEach((fields) => {
            const start =
                fields.querySelector<HTMLInputElement>('[data-start-date]')!;
            start.min = earliestPickup;
            const end =
                fields.querySelector<HTMLInputElement>('[data-end-date]')!;
            const validate = () => {
                start.setCustomValidity(
                    start.value && start.value < earliestPickup
                        ? start.dataset.minimumMessage || ''
                        : '',
                );
                const invalid =
                    start.value &&
                    end.value &&
                    !validPeriod(start.value, end.value);
                end.setCustomValidity(
                    invalid
                        ? start.dataset.invalidMessage ||
                              'Choose a later return date.'
                        : '',
                );
                const startDay = new Date(`${start.value}T00:00:00Z`);

                if (!Number.isNaN(startDay.getTime())) {
                    startDay.setUTCDate(startDay.getUTCDate() + 1);
                    const selectedMinimum = startDay.toISOString().slice(0, 10);
                    const allowedMinimum = moveCalendarDay(earliestPickup, 1);
                    end.min =
                        selectedMinimum > allowedMinimum
                            ? selectedMinimum
                            : allowedMinimum;
                } else {
                    end.min = moveCalendarDay(earliestPickup, 1);
                }
            };
            start.addEventListener('input', validate);
            end.addEventListener('input', validate);
            fields.addEventListener('change', () => track('date_selection'));
            validate();
        });
}

function renderQuote(target: HTMLElement, quote: Quote) {
    for (const paymentType of ['prepayment', 'deposit'] as const) {
        const paymentNode = document.querySelector<HTMLElement>(
            `[data-payment="${paymentType}"]`,
        );
        const payment = quote.payments?.[paymentType];

        if (paymentNode) {
            paymentNode.textContent =
                payment?.amount != null
                    ? `${new Intl.NumberFormat(locale).format(Number(payment.amount))} ${payment.currency || quote.currency}`
                    : translate('confirm_with_team');
        }
    }

    target.replaceChildren();
    const available = document.createElement('p');
    available.className = 'availability-state';
    available.textContent = translate('available');
    const list = document.createElement('dl');
    list.className = 'quote-breakdown';

    for (const [label, amount, primary] of [
        [translate('period_total'), quote.final_total, true],
        [translate('daily_average'), quote.average_daily, false],
    ] as const) {
        const row = document.createElement('div');
        const term = document.createElement('dt');
        const description = document.createElement('dd');
        term.textContent = label;
        description.textContent = `${new Intl.NumberFormat(locale, { maximumFractionDigits: primary ? 0 : 2 }).format(amount)} ${quote.currency}`;

        if (primary) {
            description.className = 'quote-total';
        }

        row.append(term, description);
        list.append(row);
    }

    const days = document.createElement('p');
    days.className = 'field-help';
    days.textContent = translate(
        locale === 'en' && quote.total_days === 1 ? 'days_one' : 'days',
    ).replace(':count', String(quote.total_days));
    target.append(available, list, days);
}

function setupQuotes() {
    const form = document.querySelector<HTMLFormElement>(
        '[data-quote-form], [data-booking-form]',
    );
    const target = document.querySelector<HTMLElement>('[data-quote-result]');

    if (!form || !target) {
        return;
    }

    const inlineQuote = document.querySelector<HTMLElement>(
        '[data-inline-quote]',
    );

    if (inlineQuote) {
        const syncInlineQuote = () =>
            inlineQuote.replaceChildren(
                ...[...target.childNodes].map((node) => node.cloneNode(true)),
            );
        new MutationObserver(syncInlineQuote).observe(target, {
            childList: true,
            subtree: true,
            characterData: true,
        });
        syncInlineQuote();
    }

    const button = form.querySelector<HTMLButtonElement>(
        '[data-quote-button], [data-booking-quote]',
    )!;
    const bookingLink = document.querySelector<HTMLAnchorElement>(
        '[data-booking-link]',
    );
    const buttonText = button.textContent;
    let currentRequest: AbortController | undefined;
    const startInput =
        form.querySelector<HTMLInputElement>('[data-start-date]')!;
    const endInput = form.querySelector<HTMLInputElement>('[data-end-date]')!;
    const clearStaleQuote = () => {
        currentRequest?.abort();
        currentRequest = undefined;
        button.disabled = false;
        button.textContent = buttonText;
        target.removeAttribute('aria-busy');
        const notice = document.createElement('p');
        notice.className = 'muted';
        notice.textContent = translate('choose_dates');
        target.replaceChildren(notice);
        document
            .querySelectorAll<HTMLElement>('[data-payment]')
            .forEach((payment) => {
                payment.textContent = translate('confirm_with_team');
            });

        if (bookingLink) {
            bookingLink.href = bookingUrlWithDates(
                form.dataset.bookingUrl!,
                startInput.value,
                endInput.value,
            );
            bookingLink.removeAttribute('aria-disabled');
            bookingLink.removeAttribute('tabindex');
        }
    };
    startInput.addEventListener('input', clearStaleQuote);
    endInput.addEventListener('input', clearStaleQuote);
    const check = async () => {
        const start =
            form.querySelector<HTMLInputElement>('[data-start-date]')!;
        const end = form.querySelector<HTMLInputElement>('[data-end-date]')!;

        if (
            !start.reportValidity() ||
            !end.reportValidity() ||
            !validPeriod(start.value, end.value)
        ) {
            return;
        }

        currentRequest?.abort();
        currentRequest = new AbortController();
        const request = currentRequest;
        button.disabled = true;
        button.textContent = translate('checking');
        target.setAttribute('aria-busy', 'true');
        const parameters = quoteParameters(start.value, end.value);

        try {
            const endpoint =
                form.dataset.endpoint || form.dataset.quoteEndpoint!;
            const response = await fetch(`${endpoint}?${parameters}`, {
                signal: request.signal,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json();

            if (request !== currentRequest) {
                return;
            }

            if (
                !response.ok ||
                !payload.data?.available ||
                !payload.data?.quote
            ) {
                const error = document.createElement('p');
                error.className = 'field-error';
                error.textContent = translate(
                    response.status === 409 ? 'unavailable' : 'quote_error',
                );
                target.replaceChildren(error);

                if (bookingLink && response.status === 409) {
                    bookingLink.removeAttribute('href');
                    bookingLink.setAttribute('aria-disabled', 'true');
                    bookingLink.tabIndex = -1;
                }
            } else {
                renderQuote(target, payload.data.quote);

                if (bookingLink) {
                    bookingLink.href = bookingUrlWithDates(
                        form.dataset.bookingUrl!,
                        start.value,
                        end.value,
                    );
                    bookingLink.removeAttribute('aria-disabled');
                    bookingLink.removeAttribute('tabindex');
                }

                if (form.hasAttribute('data-quote-form')) {
                    const url = new URL(window.location.href);
                    url.searchParams.set('starts_on', start.value);
                    url.searchParams.set('ends_on', end.value);
                    history.replaceState(null, '', url);
                }
            }
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            const notice = document.createElement('p');
            notice.className = 'field-error';
            notice.textContent = translate('network_error');
            target.replaceChildren(notice);
        } finally {
            if (request === currentRequest) {
                button.disabled = false;
                button.textContent = buttonText;
                target.removeAttribute('aria-busy');
            }
        }
    };

    if (form.hasAttribute('data-quote-form')) {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            void check();
        });
    } else {
        button.addEventListener('click', () => {
            void check();
        });
        let started = false;
        form.addEventListener('focusin', () => {
            if (!started) {
                track('booking_start');
                started = true;
            }
        });
        form.addEventListener('submit', () => {
            const submit = form.querySelector<HTMLButtonElement>(
                '[data-submit-request]',
            )!;
            submit.dataset.originalText = submit.textContent || '';
            submit.disabled = true;
            submit.textContent = translate('sending');
            form.setAttribute('aria-busy', 'true');
        });
        form.addEventListener('change', () => {
            const channel = form.querySelector<HTMLInputElement>(
                '[name="contact_channel"]:checked',
            )?.value;
            const contact = form.querySelector<HTMLInputElement>(
                '[name="contact_value"]',
            )!;
            contact.type = channel === 'email' ? 'email' : 'text';
            contact.inputMode =
                channel === 'phone' || channel === 'whatsapp'
                    ? 'tel'
                    : channel === 'email'
                      ? 'email'
                      : 'text';
        });
    }

    window.addEventListener('pageshow', () => {
        const submit = form.querySelector<HTMLButtonElement>(
            '[data-submit-request]',
        );

        if (submit) {
            submit.disabled = false;
            submit.textContent =
                submit.dataset.originalText || submit.textContent;
        }

        form.removeAttribute('aria-busy');
    });
}

function setupCalendar() {
    const calendar = document.querySelector<HTMLElement>(
        '[data-availability-calendar]',
    );

    if (!calendar) {
        return;
    }

    const start =
        document.querySelector<HTMLInputElement>('[data-start-date]')!;
    const end = document.querySelector<HTMLInputElement>('[data-end-date]')!;
    const minimumPickup = earliestPickup;
    const initial = new Date(
        `${validDate(start.value) && start.value >= minimumPickup ? start.value : minimumPickup}T00:00:00Z`,
    );
    let year = initial.getUTCFullYear();
    let month = initial.getUTCMonth();
    let busy: BusyPeriod[] = [];
    let internalChange = false;
    let focusDay = start.value || minimumPickup;
    const maximum = new Date(`${businessToday}T00:00:00Z`);
    maximum.setUTCFullYear(maximum.getUTCFullYear() + 2);
    const maximumDay = maximum.toISOString().slice(0, 10);
    const grid = calendar.querySelector<HTMLElement>('[data-calendar-grid]')!;
    const message = calendar.querySelector<HTMLElement>(
        '[data-calendar-message]',
    )!;
    const heading = calendar.querySelector<HTMLElement>(
        '[data-calendar-month]',
    )!;
    let currentRequest: AbortController | undefined;
    const previousButton = calendar.querySelector<HTMLButtonElement>(
        '[data-calendar-prev]',
    )!;
    const nextButton = calendar.querySelector<HTMLButtonElement>(
        '[data-calendar-next]',
    )!;
    const instruction = () => {
        message.textContent = validPeriod(start.value, end.value)
            ? translate('calendar_selected')
                  .replace(':start', start.value)
                  .replace(':end', end.value)
            : translate(
                  start.value ? 'calendar_pick_return' : 'calendar_pick_start',
              );
    };
    const paintSelection = () => {
        grid.querySelectorAll<HTMLButtonElement>('[data-calendar-day]').forEach(
            (button) => {
                const day = button.dataset.calendarDay!;
                const past = day < businessToday;
                const beforePickup = day < earliestPickup;
                const unavailable = isBusy(day, busy);
                const selectedStart = day === start.value;
                const selectedEnd = day === end.value;
                const inRange = Boolean(
                    start.value &&
                    end.value &&
                    day > start.value &&
                    day < end.value,
                );
                button.disabled =
                    beforePickup || unavailable || day > maximumDay;
                button.className = `calendar-day${unavailable ? ' is-busy' : ''}${beforePickup ? ' is-past' : ''}${day === businessToday ? ' is-today' : ''}${selectedStart ? ' is-start' : ''}${selectedEnd ? ' is-end' : ''}${inRange ? ' is-range' : ''}`;
                button.setAttribute(
                    'aria-pressed',
                    String(selectedStart || selectedEnd),
                );
                const state = beforePickup
                    ? past
                        ? 'calendar_past'
                        : 'calendar_pickup_unavailable'
                    : unavailable
                      ? 'busy'
                      : selectedStart
                        ? 'starts_on'
                        : selectedEnd
                          ? 'ends_on'
                          : 'free';
                button.setAttribute(
                    'aria-label',
                    `${new Intl.DateTimeFormat(locale, { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(`${day}T00:00:00Z`))}: ${translate(state)}`,
                );
                button.tabIndex = !button.disabled && day === focusDay ? 0 : -1;
            },
        );
        const enabled = [
            ...grid.querySelectorAll<HTMLButtonElement>(
                '[data-calendar-day]:not(:disabled)',
            ),
        ];

        if (
            enabled.length &&
            !enabled.some((button) => button.tabIndex === 0)
        ) {
            enabled[0].tabIndex = 0;
        }

        instruction();
    };
    const updateInputs = (next: { start: string; end: string }) => {
        internalChange = true;
        start.value = next.start;
        end.value = next.end;
        start.dispatchEvent(new Event('input', { bubbles: true }));
        end.dispatchEvent(new Event('input', { bubbles: true }));
        end.dispatchEvent(new Event('change', { bubbles: true }));
        internalChange = false;
        paintSelection();
    };
    const render = async (restoreFocus = false, direction = 1) => {
        currentRequest?.abort();
        currentRequest = new AbortController();
        const request = currentRequest;
        const days = monthDays(year, month);
        previousButton.disabled =
            days[0].slice(0, 7) <= minimumPickup.slice(0, 7);
        nextButton.disabled = days[0].slice(0, 7) >= maximumDay.slice(0, 7);
        heading.textContent = new Intl.DateTimeFormat(locale, {
            month: 'long',
            year: 'numeric',
            timeZone: 'UTC',
        }).format(new Date(`${days[0]}T00:00:00Z`));
        grid.replaceChildren();
        grid.setAttribute('aria-busy', 'true');
        message.textContent = translate('calendar_loading');
        let from = days[0];

        // Load the known gap as well when selecting a return in another month.
        // Longer periods are still validated by the authoritative quote endpoint.
        if (
            start.value &&
            !end.value &&
            start.value < from &&
            (Date.parse(days[days.length - 1]) - Date.parse(start.value)) /
                86400000 <=
                365
        ) {
            from = start.value;
        }

        try {
            const response = await fetch(
                `${calendar.dataset.endpoint}?${new URLSearchParams({ from, to: days[days.length - 1] })}`,
                {
                    signal: request.signal,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                },
            );

            if (!response.ok) {
                throw new Error('availability');
            }

            const loaded: BusyPeriod[] = (await response.json()).data.busy;

            if (currentRequest !== request) {
                return;
            }

            busy = [
                ...busy.filter(
                    (period) =>
                        period.ends_on < from ||
                        period.starts_on > days[days.length - 1],
                ),
                ...loaded,
            ];
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            message.textContent = translate('calendar_error');
            grid.removeAttribute('aria-busy');

            return;
        }

        for (let weekday = 0; weekday < 7; weekday++) {
            const cell = document.createElement('span');
            cell.className = 'calendar-weekday';
            cell.textContent = new Intl.DateTimeFormat(locale, {
                weekday: 'short',
                timeZone: 'UTC',
            }).format(new Date(Date.UTC(2026, 5, 1 + weekday)));
            grid.append(cell);
        }

        const offset = (new Date(`${days[0]}T00:00:00Z`).getUTCDay() + 6) % 7;

        for (let index = 0; index < offset; index++) {
            const spacer = document.createElement('span');
            spacer.setAttribute('aria-hidden', 'true');
            grid.append(spacer);
        }

        for (const day of days) {
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.dataset.calendarDay = day;
            cell.textContent = String(Number(day.slice(-2)));
            cell.addEventListener('click', () => {
                const result = selectCalendarDay(
                    { start: start.value, end: end.value },
                    day,
                    minimumPickup,
                    busy,
                );

                if (result.error) {
                    message.textContent = translate(
                        result.error === 'busy'
                            ? 'calendar_range_busy'
                            : 'calendar_past',
                    );

                    return;
                }

                focusDay = day;
                updateInputs(result.selection);
            });

            if (day === businessToday) {
                cell.setAttribute('aria-current', 'date');
            }

            grid.append(cell);
        }

        paintSelection();
        grid.removeAttribute('aria-busy');

        if (restoreFocus) {
            const enabled = [
                ...grid.querySelectorAll<HTMLButtonElement>(
                    '[data-calendar-day]:not(:disabled)',
                ),
            ];
            const desired =
                enabled.find(
                    (button) => button.dataset.calendarDay === focusDay,
                ) ||
                (direction > 0
                    ? enabled.find(
                          (button) => button.dataset.calendarDay! >= focusDay,
                      ) || enabled[0]
                    : [...enabled]
                          .reverse()
                          .find(
                              (button) =>
                                  button.dataset.calendarDay! <= focusDay,
                          ) || enabled.at(-1));
            desired?.focus();
        }
    };
    calendar.querySelector<HTMLElement>('[data-calendar-controls]')!.hidden =
        false;
    previousButton.addEventListener('click', () => {
        const previous = new Date(Date.UTC(year, month - 1));
        year = previous.getUTCFullYear();
        month = previous.getUTCMonth();
        void render();
    });
    nextButton.addEventListener('click', () => {
        const next = new Date(Date.UTC(year, month + 1));
        year = next.getUTCFullYear();
        month = next.getUTCMonth();
        void render();
    });
    grid.addEventListener('keydown', (event) => {
        const target = event.target;

        if (
            !(target instanceof HTMLButtonElement) ||
            !target.dataset.calendarDay
        ) {
            return;
        }

        const offset = (
            {
                ArrowLeft: -1,
                ArrowRight: 1,
                ArrowUp: -7,
                ArrowDown: 7,
            } as Record<string, number>
        )[event.key];

        if (!offset) {
            return;
        }

        event.preventDefault();
        let desired = moveCalendarDay(target.dataset.calendarDay, offset);

        while (
            desired >= minimumPickup &&
            desired <= maximumDay &&
            isBusy(desired, busy)
        ) {
            desired = moveCalendarDay(desired, Math.sign(offset));
        }

        if (desired < minimumPickup || desired > maximumDay) {
            return;
        }

        focusDay = desired;
        const sameMonth = grid.querySelector<HTMLButtonElement>(
            `[data-calendar-day="${desired}"]`,
        );

        if (sameMonth) {
            grid.querySelectorAll<HTMLButtonElement>(
                '[data-calendar-day]',
            ).forEach((button) => {
                button.tabIndex = button === sameMonth ? 0 : -1;
            });
            sameMonth.focus();
        } else {
            const next = new Date(`${desired}T00:00:00Z`);
            year = next.getUTCFullYear();
            month = next.getUTCMonth();
            void render(true, Math.sign(offset));
        }
    });
    start.addEventListener('input', () => {
        if (internalChange) {
            return;
        }

        if (
            validDate(start.value) &&
            start.value >= minimumPickup &&
            start.value <= maximumDay
        ) {
            const next = new Date(`${start.value}T00:00:00Z`);
            focusDay = start.value;

            if (
                next.getUTCFullYear() !== year ||
                next.getUTCMonth() !== month
            ) {
                year = next.getUTCFullYear();
                month = next.getUTCMonth();
                void render();

                return;
            }
        }

        paintSelection();
    });
    end.addEventListener('input', () => {
        if (!internalChange) {
            paintSelection();

            if (
                validPeriod(start.value, end.value) &&
                busyRange(start.value, end.value, busy)
            ) {
                message.textContent = translate('calendar_range_busy');
            }
        }
    });
    calendar
        .querySelector('[data-calendar-clear]')!
        .addEventListener('click', () => {
            updateInputs({ start: '', end: '' });
        });
    void render();
}

setupMenu();
setupDisclosures();
setupSelects();
setupDates();
setupQuotes();
setupCalendar();
document.querySelector<HTMLElement>('[data-error-summary]')?.focus();
document
    .querySelectorAll<HTMLElement>('[data-contact-channel]')
    .forEach((link) =>
        link.addEventListener('click', () =>
            track('channel_click', { channel: link.dataset.contactChannel }),
        ),
    );
const vehicle = document.querySelector<HTMLElement>('[data-vehicle-id]');

if (vehicle) {
    track('vehicle_view', { vehicle_id: Number(vehicle.dataset.vehicleId) });
}
