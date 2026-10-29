export const timelineZoomLimits = { min: 0.5, max: 1.5 } as const;

export function timelineZoom(value: number): number {
    return Number.isFinite(value)
        ? Math.max(
              timelineZoomLimits.min,
              Math.min(timelineZoomLimits.max, value),
          )
        : 1;
}

export type TimelineTouch = {
    identifier: number;
    clientX: number;
    clientY: number;
};

export function timelineTouchPair(
    touches: readonly TimelineTouch[],
    ids?: readonly number[],
): {
    distance: number;
    x: number;
    y: number;
} | null {
    if (
        touches.length !== 2 ||
        touches[0].identifier === touches[1].identifier ||
        (ids && touches.some((touch) => !ids.includes(touch.identifier)))
    ) {
        return null;
    }

    const [first, second] = touches;
    const distance = Math.hypot(
        second.clientX - first.clientX,
        second.clientY - first.clientY,
    );

    if (!Number.isFinite(distance) || distance < 8) {
        return null;
    }

    return {
        distance,
        x: (first.clientX + second.clientX) / 2,
        y: (first.clientY + second.clientY) / 2,
    };
}

// Coordinates are local to the scroll viewport. The frozen vehicle column
// covers the first pixels, but it is not subtracted from scrollLeft.
export function timelineZoomDay(
    scrollLeft: number,
    x: number,
    vehicleWidth: number,
    dayWidth: number,
): number {
    return (Math.max(0, scrollLeft) + Math.max(0, x - vehicleWidth)) / dayWidth;
}

export function timelineZoomScroll(
    day: number,
    x: number,
    vehicleWidth: number,
    dayWidth: number,
    gridWidth: number,
    viewportWidth: number,
): number {
    return Math.max(
        0,
        Math.min(
            day * dayWidth - Math.max(0, x - vehicleWidth),
            Math.max(0, gridWidth - viewportWidth),
        ),
    );
}

export function timelineZoomTop(
    contentY: number,
    y: number,
    zoom: number,
    scrollHeight: number,
    viewportHeight: number,
): number {
    return Math.max(
        0,
        Math.min(
            contentY * zoom - y,
            Math.max(0, scrollHeight - viewportHeight),
        ),
    );
}
