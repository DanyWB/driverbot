export type BusyPeriod = { starts_on: string; ends_on: string };
export type Quote = {
    final_total: number;
    average_daily: number;
    currency: string;
    total_days: number;
    payments?: Record<
        'prepayment' | 'deposit',
        {
            amount: number | string | null;
            currency: string | null;
            status: string;
        }
    >;
};

export function validDate(value: string): boolean {
    return (
        /^\d{4}-\d{2}-\d{2}$/.test(value) &&
        !Number.isNaN(Date.parse(`${value}T00:00:00Z`)) &&
        new Date(`${value}T00:00:00Z`).toISOString().slice(0, 10) === value
    );
}

export function validPeriod(start: string, end: string): boolean {
    return validDate(start) && validDate(end) && end > start;
}

export function isBusy(day: string, periods: BusyPeriod[]): boolean {
    return periods.some(
        (period) => day >= period.starts_on && day <= period.ends_on,
    );
}

export type CalendarSelection = { start: string; end: string };

export function bangkokToday(now = new Date()): string {
    const parts = new Intl.DateTimeFormat('en', {
        timeZone: 'Asia/Bangkok',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(now);
    const part = (name: Intl.DateTimeFormatPartTypes) =>
        parts.find((value) => value.type === name)!.value;

    return `${part('year')}-${part('month')}-${part('day')}`;
}

export function busyRange(
    start: string,
    end: string,
    periods: BusyPeriod[],
): boolean {
    return periods.some(
        (period) => start <= period.ends_on && end >= period.starts_on,
    );
}

export function selectCalendarDay(
    selection: CalendarSelection,
    day: string,
    today: string,
    periods: BusyPeriod[],
): { selection: CalendarSelection; error: 'past' | 'busy' | null } {
    if (!validDate(day) || day < today) {
        return { selection, error: 'past' };
    }

    if (isBusy(day, periods)) {
        return { selection, error: 'busy' };
    }

    if (!selection.start || selection.end || day <= selection.start) {
        return { selection: { start: day, end: '' }, error: null };
    }

    if (busyRange(selection.start, day, periods)) {
        return { selection, error: 'busy' };
    }

    return { selection: { start: selection.start, end: day }, error: null };
}

export function moveCalendarDay(day: string, offset: number): string {
    const date = new Date(`${day}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + offset);

    return date.toISOString().slice(0, 10);
}

export function monthDays(year: number, month: number): string[] {
    return Array.from(
        { length: new Date(Date.UTC(year, month + 1, 0)).getUTCDate() },
        (_, index) =>
            `${year}-${String(month + 1).padStart(2, '0')}-${String(index + 1).padStart(2, '0')}`,
    );
}

export function quoteParameters(start: string, end: string): URLSearchParams {
    return new URLSearchParams({ starts_on: start, ends_on: end });
}

export function bookingUrlWithDates(
    base: string,
    start: string,
    end: string,
): string {
    const url = new URL(base);

    if (validPeriod(start, end)) {
        url.searchParams.set('starts_on', start);
        url.searchParams.set('ends_on', end);
    } else {
        url.searchParams.delete('starts_on');
        url.searchParams.delete('ends_on');
    }

    return url.href;
}

// Never accept arbitrary event properties or any customer-entered values.
export function publicEvent(
    name: string,
    input: Record<string, unknown> = {},
): Record<string, string | number> | null {
    if (
        ![
            'vehicle_view',
            'date_selection',
            'booking_start',
            'booking_success',
            'channel_click',
        ].includes(name)
    ) {
        return null;
    }

    const output: Record<string, string | number> = { event: name };

    if (
        typeof input.vehicle_id === 'number' &&
        Number.isSafeInteger(input.vehicle_id) &&
        input.vehicle_id > 0
    ) {
        output.vehicle_id = input.vehicle_id;
    }

    if (
        typeof input.channel === 'string' &&
        ['telegram', 'whatsapp', 'email', 'phone', 'maps'].includes(
            input.channel,
        )
    ) {
        output.channel = input.channel;
    }

    return output;
}
