export type TimelineEscapeAction = 'exit_fullscreen' | 'clear_selection';

export function timelineEscapeAction({
    key,
    fullscreen,
    bookingSheetOpen,
    hasRangeSelection,
}: {
    key: string;
    fullscreen: boolean;
    bookingSheetOpen: boolean;
    hasRangeSelection: boolean;
}): TimelineEscapeAction | null {
    if (key !== 'Escape' || bookingSheetOpen) {
        return null;
    }

    if (fullscreen) {
        return 'exit_fullscreen';
    }

    return hasRangeSelection ? 'clear_selection' : null;
}
