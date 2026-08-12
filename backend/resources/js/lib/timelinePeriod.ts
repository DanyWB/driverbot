export const timelineQuickRangeDays = [30, 45, 60, 90] as const;

export type TimelineQuickRangeDays = (typeof timelineQuickRangeDays)[number];

export type TimelinePeriodDirection = -1 | 1;

export function timelineRangeFromToday(
    today: string,
    days: TimelineQuickRangeDays,
): { startsOn: string; endsOn: string } | null {
    const start = parseIsoDate(today);

    if (!start) {
        return null;
    }

    const end = new Date(start);
    end.setUTCDate(end.getUTCDate() + days - 1);

    return {
        startsOn: today,
        endsOn: toIsoDate(end),
    };
}

export function timelineTwoMonthRange(
    today: string,
): { startsOn: string; endsOn: string } | null {
    const current = parseIsoDate(today);

    if (!current) {
        return null;
    }

    return {
        startsOn: toIsoDate(
            new Date(
                Date.UTC(current.getUTCFullYear(), current.getUTCMonth(), 1),
            ),
        ),
        endsOn: toIsoDate(
            new Date(
                Date.UTC(
                    current.getUTCFullYear(),
                    current.getUTCMonth() + 2,
                    0,
                ),
            ),
        ),
    };
}

export function shiftTimelineRange(
    startsOn: string,
    endsOn: string,
    direction: TimelinePeriodDirection,
): { startsOn: string; endsOn: string } | null {
    const start = parseIsoDate(startsOn);
    const end = parseIsoDate(endsOn);

    if (!start || !end || end < start) {
        return null;
    }

    const monthSpan = fullMonthSpan(start, end);

    if (monthSpan !== null) {
        const nextStart = new Date(
            Date.UTC(
                start.getUTCFullYear(),
                start.getUTCMonth() + monthSpan * direction,
                1,
            ),
        );

        return {
            startsOn: toIsoDate(nextStart),
            endsOn: toIsoDate(
                new Date(
                    Date.UTC(
                        nextStart.getUTCFullYear(),
                        nextStart.getUTCMonth() + monthSpan,
                        0,
                    ),
                ),
            ),
        };
    }

    const days = inclusiveDays(start, end);
    start.setUTCDate(start.getUTCDate() + days * direction);
    end.setUTCDate(end.getUTCDate() + days * direction);

    return {
        startsOn: toIsoDate(start),
        endsOn: toIsoDate(end),
    };
}

function parseIsoDate(value: string): Date | null {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return null;
    }

    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));

    return toIsoDate(date) === value ? date : null;
}

function toIsoDate(value: Date): string {
    return value.toISOString().slice(0, 10);
}

function inclusiveDays(start: Date, end: Date): number {
    return Math.floor((end.getTime() - start.getTime()) / 86_400_000) + 1;
}

function fullMonthSpan(start: Date, end: Date): number | null {
    const lastDay = new Date(
        Date.UTC(end.getUTCFullYear(), end.getUTCMonth() + 1, 0),
    );

    if (start.getUTCDate() !== 1 || end.getUTCDate() !== lastDay.getUTCDate()) {
        return null;
    }

    const months =
        (end.getUTCFullYear() - start.getUTCFullYear()) * 12 +
        end.getUTCMonth() -
        start.getUTCMonth() +
        1;

    return months > 0 ? months : null;
}
