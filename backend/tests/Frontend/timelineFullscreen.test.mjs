import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const timelinePage = await readFile(
    new URL('../../resources/js/pages/timeline/Index.vue', import.meta.url),
    'utf8',
);

test('fullscreen hides all timeline chrome and gives the grid the viewport', () => {
    assert.match(
        timelinePage,
        /<header\s+v-if="!isFullscreen"/,
        'page header must not render in fullscreen',
    );
    assert.equal(
        (timelinePage.match(/<section\s+v-if="!isFullscreen"/g) ?? []).length,
        2,
        'filters and stats must not render in fullscreen',
    );
    assert.match(
        timelinePage,
        /v-if="!isFullscreen && rangeSelection"/,
        'selection helper must not render above the fullscreen grid',
    );
    assert.match(
        timelinePage,
        /:class="isFullscreen \? 'h-dvh' : ''"/,
        'fullscreen grid must occupy the dynamic viewport height',
    );
    assert.match(
        timelinePage,
        /fixed inset-0 z-40 h-dvh[^']*w-screen/,
        'fullscreen root must cover the complete viewport width',
    );
});

test('fullscreen keeps only a compact accessible exit control above the grid', () => {
    assert.match(
        timelinePage,
        /<Tooltip v-if="isFullscreen">[\s\S]*?data-timeline-fullscreen-toggle[\s\S]*?<Minimize \/>/,
    );
    assert.match(timelinePage, /aria-keyshortcuts="Escape"/);
});
