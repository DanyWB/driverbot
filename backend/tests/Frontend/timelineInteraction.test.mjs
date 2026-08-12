import assert from 'node:assert/strict';
import test from 'node:test';

import { timelineEscapeAction } from '../../resources/js/lib/timelineInteraction.ts';

test('Escape exits fullscreen before clearing an active range selection', () => {
    assert.equal(
        timelineEscapeAction({
            key: 'Escape',
            fullscreen: true,
            bookingSheetOpen: false,
            hasRangeSelection: true,
        }),
        'exit_fullscreen',
    );
});

test('an open booking sheet owns Escape above the fullscreen timeline', () => {
    assert.equal(
        timelineEscapeAction({
            key: 'Escape',
            fullscreen: true,
            bookingSheetOpen: true,
            hasRangeSelection: false,
        }),
        null,
    );
});

test('Escape clears a range only after fullscreen is closed', () => {
    assert.equal(
        timelineEscapeAction({
            key: 'Escape',
            fullscreen: false,
            bookingSheetOpen: false,
            hasRangeSelection: true,
        }),
        'clear_selection',
    );
});
