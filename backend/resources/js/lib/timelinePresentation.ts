// Palette from the client's Timeline mockup. Identity must survive filtering.
const rowColors = [
    '#ee9878',
    '#f1ad80',
    '#f7c688',
    '#fff695',
    '#c5df9a',
    '#a8d49b',
    '#8bcb9c',
    '#87cec8',
    '#7ed1f8',
    '#85a9db',
    '#8796cc',
    '#8983c1',
    '#a188c1',
    '#b88fc1',
    '#ec9cc3',
    '#ed9a9d',
];
const paints: Record<string, string> = {
    red: '#ff3d4f',
    blue: '#4b9cff',
    black: '#050607',
    white: '#ffffff',
    green: '#82ef78',
    silver: '#d5dce4',
    orange: '#ffad54',
};

export function vehicleNameSegments(
    name: string,
): Array<{ text: string; ink: string | null }> {
    return name
        .split(/(\b(?:red|blue|black|white|green|silver|orange)\b)/gi)
        .filter(Boolean)
        .map((text) => ({ text, ink: paints[text.toLowerCase()] ?? null }));
}

export function vehicleRowColor(id: number): string {
    return rowColors[Math.abs(Math.trunc(id) - 1) % rowColors.length];
}

export function vehicleNameParts(name: string): {
    before: string;
    color: string;
    after: string;
    ink: string | null;
} {
    const match = /\b(red|blue|black|white|green|silver|orange)\b/i.exec(name);

    if (!match) {
        return { before: name, color: '', after: '', ink: null };
    }

    return {
        before: name.slice(0, match.index),
        color: match[0],
        after: name.slice(match.index + match[0].length),
        ink: paints[match[0].toLowerCase()],
    };
}

export function vehicleNameLines(name: string): {
    model: string;
    details: string;
} {
    const comma = name.indexOf(',');
    const color = /\b(red|blue|black|white|green|silver|orange)\b/i.exec(name);
    // Prefer the explicit catalogue separator. With legacy names, split only
    // before a known paint word or a trailing year; never discard the suffix.
    const split =
        comma > 0
            ? comma
            : color && color.index > 0
              ? color.index
              : name.search(/\s+(?:19|20)\d{2}\b/);

    return split > 0
        ? {
              model: name.slice(0, split).trim(),
              details: name.slice(split + (comma === split ? 1 : 0)).trim(),
          }
        : { model: name, details: '' };
}

export function timelineMonthBands(
    dates: Array<{ date: string; month: string }>,
): Array<{ start: number; span: number; label: string; month: string }> {
    const bands: Array<{
        start: number;
        span: number;
        label: string;
        month: string;
    }> = [];
    dates.forEach((day, index) => {
        const month = day.date.slice(0, 7);
        const previous = bands.at(-1);

        if (previous && previous.month === month && previous.span < 7) {
            previous.span += 1;
        } else {
            bands.push({ start: index, span: 1, label: day.month, month });
        }
    });

    return bands;
}

export function occupancyLanes<
    T extends { id: number; start_index: number; span_days: number },
>(items: T[]): Array<T & { lane: number }> {
    const ends: number[] = [];

    return [...items]
        .sort((a, b) => a.start_index - b.start_index || a.id - b.id)
        .map((item) => {
            let lane = ends.findIndex((end) => end <= item.start_index);

            if (lane < 0) {
                lane = ends.length;
            }

            ends[lane] = item.start_index + item.span_days;

            return { ...item, lane };
        });
}
