import assert from 'node:assert/strict';
import test from 'node:test';

import {
    shiftTimelineRange,
    timelineQuickRangeDays,
    timelineRangeFromToday,
    timelineTwoMonthRange,
} from '../../resources/js/lib/timelinePeriod.ts';

test('fullscreen quick ranges expose the approved day options', () => {
    assert.deepEqual(timelineQuickRangeDays, [30, 45, 60, 90]);
});

test('quick ranges include today as the first displayed day', () => {
    assert.deepEqual(timelineRangeFromToday('2026-08-12', 30), {
        startsOn: '2026-08-12',
        endsOn: '2026-09-10',
    });
    assert.deepEqual(timelineRangeFromToday('2026-08-12', 90), {
        startsOn: '2026-08-12',
        endsOn: '2026-11-09',
    });
});

test('quick ranges reject an invalid business date', () => {
    assert.equal(timelineRangeFromToday('2026-02-30', 30), null);
});

test('the Today action restores the current and following calendar months', () => {
    assert.deepEqual(timelineTwoMonthRange('2026-07-21'), {
        startsOn: '2026-07-01',
        endsOn: '2026-08-31',
    });
});

test('period arrows preserve a two-calendar-month window', () => {
    assert.deepEqual(
        shiftTimelineRange('2026-07-01', '2026-08-31', 1),
        {
            startsOn: '2026-09-01',
            endsOn: '2026-10-31',
        },
    );
    assert.deepEqual(
        shiftTimelineRange('2026-07-01', '2026-08-31', -1),
        {
            startsOn: '2026-05-01',
            endsOn: '2026-06-30',
        },
    );
});
