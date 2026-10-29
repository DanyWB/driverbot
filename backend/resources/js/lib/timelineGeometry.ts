export const timelineDayWidths = [36, 48, 60] as const;
export const defaultTimelineDayWidth = 48;

export function timelineDayWidth(value: number): number {
    return timelineDayWidths.includes(value as 36 | 48 | 60)
        ? value
        : defaultTimelineDayWidth;
}

export function timelineVehicleWidth(
    _viewportWidth: number,
    nameWidth = 0,
): number {
    return Math.max(250, Math.ceil(nameWidth) + 72);
}

// Controls stay usable at 50%; neither the info button nor the calendar
// toolbar may force the frozen column wider than its computed geometry.
export function timelineScaledVehicleWidth(
    nameWidth: number,
    zoom: number,
    narrow = false,
): number {
    return Math.max(
        184,
        (narrow
            ? Math.max(180, Math.ceil(nameWidth) + 80)
            : timelineVehicleWidth(0, nameWidth)) * zoom,
    );
}

export function timelineOccupancyLayout(
    zoom: number,
    compact: boolean,
    lane: number,
): { top: number; height: number } {
    return {
        top: (compact ? 5 : 10) * zoom + lane * Math.max(28, 30 * zoom),
        height: Math.max(24, 26 * zoom),
    };
}

export function timelineGridWidth(
    vehicleWidth: number,
    days: number,
    dayWidth: number,
): number {
    return vehicleWidth + days * dayWidth;
}

export function timelineBookingGeometry(
    vehicleWidth: number,
    dayWidth: number,
    startIndex: number,
    span: number,
    zoom = 1,
): { left: number; width: number } {
    return {
        left: vehicleWidth + startIndex * dayWidth + 2 * zoom,
        width: Math.max(dayWidth - 4 * zoom, span * dayWidth - 4 * zoom),
    };
}

// The frozen column covers x=0..vehicleWidth, so the first uncovered day
// advances by scrollLeft/dayWidth, without subtracting the frozen column.
export function timelineVisibleDay(
    scrollLeft: number,
    dayWidth: number,
): number {
    return Math.max(0, scrollLeft) / dayWidth;
}

export function timelineAnchorScroll(
    visibleDay: number,
    dayWidth: number,
    gridWidth: number,
    viewportWidth: number,
): number {
    return Math.max(
        0,
        Math.min(visibleDay * dayWidth, gridWidth - viewportWidth),
    );
}

export function timelinePointerMoved(
    start: { x: number; y: number },
    current: { x: number; y: number },
): boolean {
    return Math.hypot(current.x - start.x, current.y - start.y) > 8;
}
