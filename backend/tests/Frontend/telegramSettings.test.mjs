import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    createServerCountdown,
    serverCountdownSeconds,
} from '../../resources/js/lib/serverCountdown.ts';

const settingsPage = await readFile(
    new URL('../../resources/js/pages/settings/Telegram.vue', import.meta.url),
    'utf8',
);

test('Telegram settings exposes the complete per-administrator connection flow', () => {
    for (const testId of [
        'telegram-generate-code',
        'telegram-copy-command',
        'telegram-test',
        'telegram-replace',
        'telegram-disconnect',
        'telegram-confirm-disconnect',
    ]) {
        assert.match(settingsPage, new RegExp(`data-test="${testId}"`));
    }

    assert.match(settingsPage, /TelegramController\.issueCode\.form\(\)/);
    assert.match(settingsPage, /TelegramController\.test\.form\(\)/);
    assert.match(settingsPage, /TelegramController\.destroy\.form\(\)/);
    assert.match(
        settingsPage,
        /only: \['binding', 'activeRecipientCount', 'legacyFallbackActive'\]/,
    );
    assert.match(settingsPage, /bindingGenerationAtIssue/);
    assert.match(settingsPage, /finishExpiredConnectionAttempt/);
    assert.match(settingsPage, /router\.reload/);
});

test('one-time code is copied separately and is never embedded in the bot URL', () => {
    assert.match(
        settingsPage,
        /navigator\.clipboard\.writeText\(visibleCode\.value\.command\)/,
    );
    assert.match(settingsPage, /`https:\/\/t\.me\/\$\{props\.botUsername\}`/);
    assert.doesNotMatch(settingsPage, /t\.me\/[^`]*visibleCode/);
});

test('binding code TTL follows server time and a monotonic clock', () => {
    const countdown = createServerCountdown(
        '2026-09-06T10:10:00.000Z',
        '2026-09-06T10:00:00.000Z',
        500,
    );

    assert.equal(serverCountdownSeconds(countdown, 500), 600);
    assert.equal(serverCountdownSeconds(countdown, 60_500), 540);
    assert.equal(serverCountdownSeconds(countdown, 700_000), 0);
    assert.equal(
        serverCountdownSeconds(
            createServerCountdown(
                '2026-09-06T09:59:59.000Z',
                '2026-09-06T10:00:00.000Z',
                10,
            ),
            10,
        ),
        0,
    );
});
