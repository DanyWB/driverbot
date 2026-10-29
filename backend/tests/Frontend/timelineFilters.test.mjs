import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { filterTimelineRows, timelineVehicleModel, timelineVehicleModels, timelineVisibleStats, vehicleNameSegments, vehicleNameLines } from '../../resources/js/lib/timelinePresentation.ts';

test('model filter separates manufacturers from family, engine, paint and year', () => {
    const cases = [
        ['Honda HR-V, White, 2026', 'HR-V'], ['Honda PCX 160cc ABS, Black, 2022', 'PCX'],
        ['ADV 160cc, ABS, Beige, 2027', 'ADV'], ['Yamaha Grand Filano 125cc, Blue, 2026', 'Grand Filano'],
        ['Honda Super Cub 125, Green', 'Super Cub'], ['Unknown multi word model, Pearl, 2025', 'Unknown multi word model'],
        ['Honda HR-V 2026', 'HR-V'], ['', ''],
        ['PCX160cc, Black, 2026', 'PCX'], ['Yamaha NMAX155cc ABS, Blue, 2027', 'NMAX'],
    ];

    for (const [name, model] of cases) {
assert.equal(timelineVehicleModel(name), model);
}

    assert.deepEqual(timelineVehicleModels([{name: 'Honda PCX 160cc'}, {name: 'pcx 150cc'}, {name: 'Honda HR-V'}]), ['HR-V', 'PCX']);
});

test('local name/model search is case-insensitive, word-order independent and never mutates rows', () => {
    const rows = [{name: 'Honda PCX 160cc, Black, 2022'}, {name: 'PCX 150cc, Blue, 2018'}, {name: 'Honda HR-V, Black, 2026'}];
    assert.deepEqual(filterTimelineRows(rows, '  black HONDA ', 'pcx'), [rows[0]]);
    assert.deepEqual(filterTimelineRows(rows, '', 'HR-V'), [rows[2]]);
    assert.deepEqual(filterTimelineRows(rows, '<script>', ''), []);
    assert.deepEqual(filterTimelineRows(rows, '', ''), rows);
    assert.equal(rows.length, 3);
});

test('visible statistics preserve server meaning: blocking ranges, not pending requests', () => {
    const rows = [{name: 'PCX', blocked_ranges: [], occupancies: [{status:'pending'}]}, {name: 'ADV', blocked_ranges: [{}, {}], occupancies: [{}, {}, {}]}];
    assert.deepEqual(timelineVisibleStats(rows), {vehicles: 2, available: 1, occupancies: 2});
    assert.deepEqual(timelineVisibleStats(filterTimelineRows(rows, 'PCX', '')), {vehicles: 1, available: 1, occupancies: 0});
    assert.deepEqual(timelineVisibleStats([]), {vehicles: 0, available: 0, occupancies: 0});
});

test('Beige is a paint, complete names survive both display modes', () => {
    const name = 'ADV 160cc, ABS, Beige, 2027';
    assert.equal(vehicleNameSegments(name).map((item) => item.text).join(''), name);
    assert.deepEqual(vehicleNameSegments('Beige'), [{text:'Beige', ink:'#d8c2a5'}]);
    assert.deepEqual(vehicleNameLines(name), {model:'ADV 160cc', details:'ABS, Beige, 2027'});
});

test('reset clears local filters, model changes clear stale selection, controls remain custom', async () => {
    const page = await readFile(new URL('../../resources/js/pages/timeline/Index.vue', import.meta.url), 'utf8');
    assert.match(page, /function resetFilters\(\): void \{\s*vehicleNameSearch.value = '';\s*vehicleModelFilter.value = '';/);
    assert.match(page, /watch\(\[vehicleNameSearch, vehicleModelFilter\], \(\) => \{\s*clearTimelineSelection\(\)/);
    assert.match(page, /vehicleModelOptions, \(options\) =>/);
    assert.match(page, /count: visibleStats.vehicles/);
    assert.match(page, /count: visibleStats.available/);
    assert.match(page, /count: visibleStats.occupancies/);
    assert.match(page, /id="timeline_model_filter_mobile"[\s\S]*?:aria-label="t\('Model'\)"/);
    assert.doesNotMatch(page, /<select\b/i);
    assert.match(page, /surface.setPointerCapture\(event.pointerId\)/);
    assert.match(page, /surface.releasePointerCapture\(event.pointerId\)/);
    assert.match(page, /min-width: 32px !important/);
});

test('model filter has an explicit Russian accessible name', async () => {
    const locale = await readFile(new URL('../../resources/js/composables/useLocale.ts', import.meta.url), 'utf8');
    assert.match(locale, /Model: 'Модель'/);
});
