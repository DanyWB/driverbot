import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const adminSelect = await readFile(
    new URL('../../resources/js/components/AdminSelect.vue', import.meta.url),
    'utf8',
);

test('custom admin select closes and advances focus when Tab is pressed', () => {
    assert.match(adminSelect, /v-model:open="open"/);
    assert.match(
        adminSelect,
        /@keydown\.tab\.capture\.stop\.prevent="handleContentTab"/,
    );
    assert.match(
        adminSelect,
        /@close-auto-focus="handleCloseAutoFocus"/,
        'closing after Tab must bypass the library focus restore before advancing',
    );
    assert.match(adminSelect, /const direction = event\.shiftKey \? -1 : 1/);
    assert.match(adminSelect, /await nextTick\(\)/);
    assert.match(
        adminSelect,
        /focusTabTargetIfSafe\(destination, closingPopup\)/,
    );
    assert.match(
        adminSelect,
        /closingPopup\?\.contains\(activeElement\) === true/,
    );
    assert.match(adminSelect, /pendingTabPopup = null/);
});

test('tab target lookup excludes focus guards and unavailable controls', () => {
    assert.match(adminSelect, /data-reka-focus-guard/);
    assert.match(adminSelect, /element\.tabIndex >= 0/);
    assert.match(adminSelect, /element\.getClientRects\(\)\.length > 0/);
    assert.match(adminSelect, /!element\.matches\(':disabled'\)/);
    assert.match(adminSelect, /style\.visibility !== 'hidden'/);
    assert.match(adminSelect, /style\.visibility !== 'collapse'/);
    assert.match(adminSelect, /element\.closest\('\[inert\], \[hidden\]'\)/);
    assert.match(
        adminSelect,
        /triggerElement\.closest<HTMLElement>\(\s*'\[aria-hidden="true"\]',?\s*\)/,
    );
    assert.match(adminSelect, /ariaHiddenAncestor === allowedAriaHiddenRoot/);
    assert.match(adminSelect, /\[role="dialog"\], dialog/);
});
