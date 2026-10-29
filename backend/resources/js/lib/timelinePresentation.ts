type NamedVehicle = { name: string };

export function timelineVehicleModel(name: string): string {
    const model = vehicleNameLines(name)
        .model.replace(
            /^(?:Honda|Yamaha|Kawasaki|Suzuki|Toyota|Nissan|Mazda|Mitsubishi|Ford|Hyundai|Kia|Isuzu|BYD|MG)\s+/i,
            '',
        )
        .replace(/(\D)\d+(?:\.\d+)?\s*cc\b.*$/i, '$1')
        .trim();

    // Keep model families together across engine sizes/years, while retaining
    // meaningful multi-word models (Super Cub, Grand Filano) and HR-V hyphens.
    return (
        model
            .split(/\s+(?:\d+(?:\.\d+)?(?:\s*cc)?\b|ABS\b|CBS\b)/i)[0]
            .trim() || model
    );
}

export function timelineVehicleModels(rows: readonly NamedVehicle[]): string[] {
    const models = new Map<string, string>();

    for (const row of rows) {
        const model = timelineVehicleModel(row.name);

        if (model) {
            models.set(
                model.toLocaleLowerCase(),
                models.get(model.toLocaleLowerCase()) ?? model,
            );
        }
    }

    return [...models.values()].sort((a, b) => a.localeCompare(b));
}

export function filterTimelineRows<T extends NamedVehicle>(
    rows: readonly T[],
    query: string,
    model: string,
): T[] {
    const terms = query.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
    const selectedModel = model.toLocaleLowerCase();

    return rows.filter(
        (row) =>
            terms.every((term) =>
                row.name.toLocaleLowerCase().includes(term),
            ) &&
            (!selectedModel ||
                timelineVehicleModel(row.name).toLocaleLowerCase() ===
                    selectedModel),
    );
}

export function timelineVisibleStats(
    rows: readonly {
        blocked_ranges: readonly unknown[];
        occupancies: readonly unknown[];
    }[],
): { vehicles: number; available: number; occupancies: number } {
    return {
        vehicles: rows.length,
        available: rows.filter((row) => row.blocked_ranges.length === 0).length,
        occupancies: rows.reduce(
            (sum, row) => sum + row.blocked_ranges.length,
            0,
        ),
    };
}

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
    beige: '#d8c2a5',
};

export function vehicleNameSegments(
    name: string,
): Array<{ text: string; ink: string | null }> {
    return name
        .split(/(\b(?:red|blue|black|white|green|silver|orange|beige)\b)/gi)
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
    const match = /\b(red|blue|black|white|green|silver|orange|beige)\b/i.exec(
        name,
    );

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
    const color = /\b(red|blue|black|white|green|silver|orange|beige)\b/i.exec(
        name,
    );
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
