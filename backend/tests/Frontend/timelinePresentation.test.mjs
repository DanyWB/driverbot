import assert from 'node:assert/strict';
import test from 'node:test';
import {occupancyLanes, timelineMonthBands, vehicleNameParts, vehicleRowColor} from '../../resources/js/lib/timelinePresentation.ts';

test('vehicle identity and name colors do not depend on filtering order', () => {
    assert.equal(vehicleRowColor(51), vehicleRowColor(51));
    assert.notEqual(vehicleRowColor(1), vehicleRowColor(2));
    assert.equal(vehicleNameParts('PCX 160cc, Black, 2022').color, 'Black');
    assert.equal(vehicleNameParts('Unrecognized <script>').before, 'Unrecognized <script>');
    assert.equal(vehicleNameParts('Redwood scooter').ink, null);
});

test('month bands stop at month/year boundaries and repeat every seven days', () => {
    const dates = Array.from({length: 20}, (_, index) => {
        const date = new Date(Date.UTC(2026, 11, 28 + index)).toISOString().slice(0, 10);

        return {date, month: date.slice(0, 7)};
    });
    const bands = timelineMonthBands(dates);
    assert.deepEqual(bands.map(({span}) => span), [4, 7, 7, 2]);
    assert.deepEqual(bands.map(({start}) => start), [0, 4, 11, 18]);
});

test('overlapping requests remain independently clickable and adjacent ranges reuse a lane', () => {
    const input = [
        {id: 3, start_index: 5, span_days: 2},
        {id: 2, start_index: 1, span_days: 3},
        {id: 1, start_index: 0, span_days: 5},
    ];
    assert.deepEqual(occupancyLanes(input).map(({id, lane}) => [id, lane]), [[1, 0], [2, 1], [3, 0]]);
    assert.equal(input[0].id, 3);
});
