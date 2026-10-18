// Rental duration is a date difference; timeline occupancy cells stay inclusive.
export function rentalDayCount(startsOn: string, endsOn: string): number {
    const start = Date.parse(`${startsOn}T00:00:00Z`);
    const end = Date.parse(`${endsOn}T00:00:00Z`);

    if (!Number.isFinite(start) || !Number.isFinite(end) || end < start) {
        return 0;
    }

    if (
        new Date(start).toISOString().slice(0, 10) !== startsOn ||
        new Date(end).toISOString().slice(0, 10) !== endsOn
    ) {
        return 0;
    }

    return Math.max(1, Math.floor((end - start) / 86_400_000));
}
