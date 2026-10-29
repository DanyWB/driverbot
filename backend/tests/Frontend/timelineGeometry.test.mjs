import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    timelineAnchorScroll,
    timelineBookingGeometry,
    timelineDayWidth,
    timelineGridWidth,
    timelineOccupancyLayout,
    timelinePointerMoved,
    timelineScaledVehicleWidth,
    timelineVehicleWidth,
    timelineVisibleDay,
} from '../../resources/js/lib/timelineGeometry.ts';
import { vehicleNameLines } from '../../resources/js/lib/timelinePresentation.ts';

test('one geometry serves all three densities, frozen widths and booking spans', () => {
    for (const viewport of [320, 390, 640, 844, 1024, 1440]) {
        const frozen = timelineVehicleWidth(viewport);
        assert.equal(frozen, 250);

        for (const width of [36, 48, 60]) {
            assert.equal(timelineGridWidth(frozen, 180, width), frozen + 180 * width);
            assert.deepEqual(timelineBookingGeometry(frozen, width, 17, 4), {
                left: frozen + 17 * width + 2,
                width: 4 * width - 4,
            });
            assert.equal(timelineBookingGeometry(frozen, width, 0, 1).width, width - 4);
        }
    }

    assert.equal(timelineDayWidth(13), 48);
    assert.equal(timelineDayWidth(undefined), 48);
});

test('density and rotation preserve the first uncovered day and its fractional offset', () => {
    const anchor = timelineVisibleDay(17.5 * 48, 48);
    assert.equal(anchor, 17.5);

    for (const width of [36, 48, 60]) {
        for (const viewport of [390, 844]) {
            const grid = timelineGridWidth(timelineVehicleWidth(viewport), 180, width);
            const scroll = timelineAnchorScroll(anchor, width, grid, viewport);
            assert.equal(scroll / width, 17.5);
        }
    }

    assert.equal(timelineAnchorScroll(179, 48, 8820, 844), 7976);
    assert.equal(timelineAnchorScroll(5, 48, 300, 844), 0);
});

test('name lines preserve long models, multi-paint and unknown suffixes', () => {
    assert.deepEqual(vehicleNameLines('Honda PCX 160cc ABS, Black / Silver, 2022 special edition'), {
        model: 'Honda PCX 160cc ABS', details: 'Black / Silver, 2022 special edition',
    });
    assert.deepEqual(vehicleNameLines('PCX 160cc Black 2022'), { model: 'PCX 160cc', details: 'Black 2022' });
    assert.deepEqual(vehicleNameLines('Very long unknown model, Midnight Pearl, 2027 extended suffix'), {
        model: 'Very long unknown model', details: 'Midnight Pearl, 2027 extended suffix',
    });
    assert.deepEqual(vehicleNameLines('Unknown model 2025 extra data'), { model: 'Unknown model', details: '2025 extra data' });
    assert.deepEqual(vehicleNameLines('Redwood scooter'), { model: 'Redwood scooter', details: '' });
    assert.deepEqual(vehicleNameLines('Blackbird SuperLongUnbrokenName'), { model: 'Blackbird SuperLongUnbrokenName', details: '' });
    assert.deepEqual(vehicleNameLines('Red Devil 150'), { model: 'Red Devil 150', details: '' });
});

test('scroll gestures cannot masquerade as ordinary taps', () => {
    assert.equal(timelinePointerMoved({ x: 100, y: 100 }, { x: 105, y: 103 }), false);
    assert.equal(timelinePointerMoved({ x: 100, y: 100 }, { x: 109, y: 100 }), true);
    assert.equal(timelinePointerMoved({ x: 100, y: 100 }, { x: 100, y: 40 }), true);
});

test('mobile controls remain explicit, keyboard accessible and motion aware', async () => {
    const page = await readFile(new URL('../../resources/js/pages/timeline/Index.vue', import.meta.url), 'utf8');
    const info = await readFile(new URL('../../resources/js/components/timeline/TimelineVehicleInfo.vue', import.meta.url), 'utf8');
    assert.match(page, /vehicleInfoOpen.value \|\| event.defaultPrevented/);
    assert.match(page, /@pointercancel.capture="endPointerGesture"/);
    assert.match(page, /handleDateClick\(\s*\$event,\s*row,\s*date.date,\s*index,?\s*\)/);
    assert.match(page, /'Timeline zoom'/);
    assert.match(page, /prefers-reduced-motion: reduce/);
    assert.match(page, /\.timeline-paint-black\s*\{[^}]*--paint-stroke: #aeb4bc;/s);
    assert.doesNotMatch(page, /transform:\s*scale\(/);
    assert.match(info, /PopoverTrigger as-child/);
    assert.match(info, /PopoverClose as-child/);
    assert.match(info, /Details for :vehicle/);
});

test('low zoom retains non-overlapping targets without expanding ordinary rows to full size', () => {
    for (const zoom of [0.5, 0.75, 1, 1.5]) {
        assert.ok(timelineScaledVehicleWidth(120, zoom, true) >= 184);
        const first = timelineOccupancyLayout(zoom, true, 0);
        const second = timelineOccupancyLayout(zoom, true, 1);
        assert.ok(first.height >= 24);
        assert.ok(second.top >= first.top + first.height);
    }

    assert.ok(timelineScaledVehicleWidth(320, 0.5) < timelineScaledVehicleWidth(320, 1));
});
