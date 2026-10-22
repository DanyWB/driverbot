export const timelineDayWidths = [36, 48, 60] as const;
export const defaultTimelineDayWidth = 48;

export function timelineDayWidth(value: number): number {
    return timelineDayWidths.includes(value as 36 | 48 | 60)
        ? value
        : defaultTimelineDayWidth;
}

export function timelineVehicleWidth(viewportWidth: number): number {
    return viewportWidth <= 640 ? 180 : viewportWidth <= 1024 ? 220 : 255;
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
): { left: number; width: number } {
    return {
        left: vehicleWidth + startIndex * dayWidth + 2,
        width: Math.max(dayWidth - 4, span * dayWidth - 4),
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
