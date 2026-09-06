import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const timelinePage = await readFile(
    new URL('../../resources/js/pages/timeline/Index.vue', import.meta.url),
    'utf8',
);
const appStyles = await readFile(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
);
const bookingSheet = await readFile(
    new URL(
        '../../resources/js/components/timeline/TimelineBookingSheet.vue',
        import.meta.url,
    ),
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

test('fullscreen keeps compact accessible range and exit controls above the grid', () => {
    assert.match(
        timelinePage,
        /<Tooltip v-if="isFullscreen">[\s\S]*?data-timeline-fullscreen-toggle[\s\S]*?<Minimize \/>/,
    );
    assert.match(timelinePage, /aria-keyshortcuts="Escape"/);
    assert.match(
        timelinePage,
        /data-timeline-fullscreen-range[\s\S]*?v-for="days in timelineQuickRangeDays"/,
    );
    assert.match(timelinePage, /@click="applyQuickRange\(days\)"/);
});

test('timeline has a taller horizontal scrollbar target', () => {
    assert.match(timelinePage, /class="timeline-scroll-area /);
    assert.match(
        appStyles,
        /\.timeline-scroll-area::-webkit-scrollbar\s*{[^}]*height:\s*18px;/s,
    );
});

test('quick booking keeps modal pointer-event isolation', () => {
    assert.doesNotMatch(
        bookingSheet,
        /:?disable-outside-pointer-events\s*=\s*["'][^"']*false[^"']*["']/,
        'the sheet overlay locks body pointer events, so modal content must retain the Reka default that restores pointer events inside the sheet',
    );
    assert.doesNotMatch(
        bookingSheet,
        /<Sheet\b[^>]*:?modal\s*=\s*["'][^"']*false[^"']*["']/s,
        'quick booking must remain modal while its overlay is mounted',
    );
});
