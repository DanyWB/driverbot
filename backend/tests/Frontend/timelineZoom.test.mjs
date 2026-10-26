import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    timelineBookingGeometry,
    timelineGridWidth,
    timelineVehicleWidth,
} from '../../resources/js/lib/timelineGeometry.ts';
import {
    timelineTouchPair,
    timelineZoom,
    timelineZoomDay,
    timelineZoomScroll,
    timelineZoomTop,
} from '../../resources/js/lib/timelineZoom.ts';
import { vehicleNameSegments } from '../../resources/js/lib/timelinePresentation.ts';

test('continuous grid zoom is bounded, finite, and independent of density', () => {
    assert.equal(timelineZoom(0.1), 0.75);
    assert.equal(timelineZoom(4), 1.5);
    assert.equal(timelineZoom(1.234), 1.234);

    for (const value of [NaN, Infinity, -Infinity, undefined]) {
        assert.equal(timelineZoom(value), 1);
    }

    for (const zoom of [0.75, 1.234, 1.5]) {
        const vehicle = timelineVehicleWidth(844, 340) * zoom;
        const day = 48 * zoom;
        assert.equal(
            timelineGridWidth(vehicle, 90, day),
            (452 + 90 * 48) * zoom,
        );
        const booking = timelineBookingGeometry(vehicle, day, 8, 3, zoom);
        assert.ok(Math.abs(booking.left - (452 + 8 * 48 + 2) * zoom) < 1e-9);
        assert.ok(Math.abs(booking.width - (3 * 48 - 4) * zoom) < 1e-9);
    }
});

test('two real, distinct contacts are required; third/replaced/invalid contacts cannot scale', () => {
    const a = { identifier: 1, clientX: 100, clientY: 100 };
    const b = { identifier: 2, clientX: 160, clientY: 180 };
    assert.deepEqual(timelineTouchPair([a, b]), {
        distance: 100,
        x: 130,
        y: 140,
    });
    assert.deepEqual(timelineTouchPair([b, a], [1, 2]), {
        distance: 100,
        x: 130,
        y: 140,
    });

    for (const touches of [
        [],
        [a],
        [a, b, { ...b, identifier: 3 }],
        [a, a],
        [a, { ...b, clientX: NaN }],
        [a, { ...b, clientX: 102, clientY: 102 }],
    ]) {
        assert.equal(timelineTouchPair(touches), null);
    }

    assert.equal(timelineTouchPair([a, { ...b, identifier: 3 }], [1, 2]), null);
});

test('pinch keeps the fractional date under a moving midpoint, not just the left edge', () => {
    const original = { scroll: 17.5 * 48, x: 590, vehicle: 360, day: 48 };
    const date = timelineZoomDay(
        original.scroll,
        original.x,
        original.vehicle,
        original.day,
    );
    assert.ok(date > 17.5);

    for (const zoom of [0.75, 1.05, 1.5]) {
        const vehicle = original.vehicle * zoom;
        const day = original.day * zoom;
        const x = 620;
        const scroll = timelineZoomScroll(
            date,
            x,
            vehicle,
            day,
            vehicle + 180 * day,
            844,
        );
        assert.ok(
            Math.abs(timelineZoomDay(scroll, x, vehicle, day) - date) < 1e-9,
        );
    }
});

test('pinch clamps at content edges and handles a midpoint over the frozen label', () => {
    assert.equal(timelineZoomDay(480, 50, 360, 48), 10);
    assert.equal(timelineZoomDay(-5, 50, 360, 48), 0);
    assert.equal(timelineZoomScroll(0, 600, 360, 48, 9000, 844), 0);
    assert.equal(timelineZoomScroll(180, 360, 360, 48, 9000, 844), 8156);
    assert.equal(timelineZoomScroll(10, 100, 180, 48, 300, 844), 0);
    assert.equal(timelineZoomTop(450, 100, 1.2, 2000, 600), 440);
    assert.equal(timelineZoomTop(20, 100, 0.75, 2000, 600), 0);
    assert.equal(timelineZoomTop(1900, 100, 1.5, 2000, 600), 1400);
    assert.equal(timelineZoomTop(450, 100, 1.2, 300, 600), 0);
});

test('desktop width fits full names while narrow phones keep complete wrapped text', () => {
    assert.equal(timelineVehicleWidth(390, 700), 180);
    assert.equal(timelineVehicleWidth(844, 400.3), 513);
    assert.equal(timelineVehicleWidth(1440, 400.3), 513);
    const name =
        'Honda PCX 160cc ABS, Black / Silver / Red, 2022 special edition';
    const segments = vehicleNameSegments(name);
    assert.equal(segments.map(({ text }) => text).join(''), name);
    assert.deepEqual(
        segments.filter(({ ink }) => ink).map(({ text }) => text),
        ['Black', 'Silver', 'Red'],
    );
    assert.deepEqual(vehicleNameSegments('Redwood Blackbird'), [
        { text: 'Redwood Blackbird', ink: null },
    ]);
});

test('pinch capture stays scoped, rejects cross-surface contacts, and guards every grid click', async () => {
    const page = await readFile(
        new URL('../../resources/js/pages/timeline/Index.vue', import.meta.url),
        'utf8',
    );
    const name = await readFile(
        new URL(
            '../../resources/js/components/timeline/TimelineVehicleName.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(page, /!surface\.contains\(touch\.target\)/);
    assert.match(
        page,
        /if \(!event.cancelable\)\s*\{\s*return;\s*\}\s*event.preventDefault\(\)/,
    );
    assert.match(page, /@touchmove.capture="moveTimelineTouch"/);
    assert.match(page, /@touchcancel.capture="endTimelineTouch"/);
    assert.match(page, /@click.capture="guardGestureClick"/);
    assert.match(
        page,
        /if \(event.touches.length === 0\)\s*\{\s*pinchOwned = false/,
    );
    assert.match(
        page,
        /event.detail !== 0 && \(pinchOwned \|\| suppressPointerClick\)/,
    );
    assert.match(
        page,
        /\.timeline-scroll-area\s*\{\s*touch-action: pan-x pan-y;/,
    );
    assert.doesNotMatch(
        page,
        /(?:document|window)\.addEventListener\(['"](?:touch|gesture)/,
    );
    assert.match(page, /:stacked="viewportWidth <= 640"/);
    assert.match(page, /:title="row.name"/);
    assert.match(name, /class="timeline-paint-outline" aria-hidden="true"/);
    assert.match(
        page,
        /\.timeline-paint-fill\s*\{[^}]*-webkit-text-stroke: 0;/s,
    );
    assert.match(page, /@supports not \(-webkit-text-stroke: 1px white\)/);
});
