import assert from 'node:assert/strict';
import test from 'node:test';

import { lockBodyScroll } from '../../resources/js/lib/bodyScrollLock.ts';

test('body overflow is restored after fullscreen cleanup', () => {
    const targetDocument = {
        body: {
            style: {
                overflow: 'auto',
            },
        },
    };

    const release = lockBodyScroll(targetDocument);

    assert.equal(targetDocument.body.style.overflow, 'hidden');

    release();

    assert.equal(targetDocument.body.style.overflow, 'auto');
});
