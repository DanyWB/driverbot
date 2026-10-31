import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import {
    bangkokToday,
    bookingUrlWithDates,
    busyRange,
    isBusy,
    monthDays,
    moveCalendarDay,
    publicEvent,
    quoteParameters,
    selectCalendarDay,
    validDate,
    validPeriod,
} from '../../resources/js/website/domain.ts';

test('booking URL dates replace existing validated dates rather than appending a second query', () => {
    const base =
        'http://127.0.0.1:8091/en/book/28?starts_on=2027-02-01&ends_on=2027-02-08&category_id=4';
    const updated = new URL(
        bookingUrlWithDates(base, '2027-03-01', '2027-03-08'),
    );
    assert.deepEqual(updated.searchParams.getAll('starts_on'), ['2027-03-01']);
    assert.deepEqual(updated.searchParams.getAll('ends_on'), ['2027-03-08']);
    assert.equal(updated.searchParams.get('category_id'), '4');
    assert.equal((updated.href.match(/\?/g) || []).length, 1);
    const cleared = new URL(bookingUrlWithDates(base, '2027-03-01', ''));
    assert.equal(cleared.searchParams.has('starts_on'), false);
    assert.equal(cleared.searchParams.has('ends_on'), false);
});

test('calendar selection rejects Bangkok past dates, busy endpoints and busy crossings', () => {
    const busy = [{ starts_on: '2026-11-05', ends_on: '2026-11-06' }];
    assert.equal(bangkokToday(new Date('2026-11-02T20:00:00Z')), '2026-11-03');
    assert.equal(
        selectCalendarDay(
            { start: '', end: '' },
            '2026-11-02',
            '2026-11-03',
            busy,
        ).error,
        'past',
    );
    assert.equal(
        selectCalendarDay(
            { start: '', end: '' },
            '2026-11-05',
            '2026-11-03',
            busy,
        ).error,
        'busy',
    );
    const start = selectCalendarDay(
        { start: '', end: '' },
        '2026-11-03',
        '2026-11-03',
        busy,
    ).selection;
    assert.deepEqual(start, { start: '2026-11-03', end: '' });
    assert.equal(
        selectCalendarDay(start, '2026-11-08', '2026-11-03', busy).error,
        'busy',
    );
    assert.deepEqual(
        selectCalendarDay(start, '2026-11-04', '2026-11-03', busy).selection,
        { start: '2026-11-03', end: '2026-11-04' },
    );
    assert.equal(busyRange('2026-10-30', '2026-12-01', busy), true);
    assert.equal(moveCalendarDay('2026-11-30', 1), '2026-12-01');
});

test('website date validation rejects rollover, zero-length and backwards rentals', () => {
    assert.equal(validDate('2026-02-30'), false);
    assert.equal(validDate('2028-02-29'), true);
    assert.equal(validPeriod('2026-11-01', '2026-11-01'), false);
    assert.equal(validPeriod('2026-12-01', '2026-11-01'), false);
    assert.equal(validPeriod('2026-12-31', '2027-01-01'), true);
});

test('availability calendar uses inclusive busy ranges without timezone drift', () => {
    assert.equal(monthDays(2028, 1).length, 29);
    assert.equal(monthDays(2026, 11).at(-1), '2026-12-31');
    const periods = [{ starts_on: '2026-11-01', ends_on: '2026-11-03' }];
    assert.equal(isBusy('2026-11-01', periods), true);
    assert.equal(isBusy('2026-11-03', periods), true);
    assert.equal(isBusy('2026-11-04', periods), false);
});

test('quote query contains dates only; events discard customer details', () => {
    assert.equal(
        quoteParameters('2026-11-01', '2026-11-08').toString(),
        'starts_on=2026-11-01&ends_on=2026-11-08',
    );
    assert.deepEqual(
        publicEvent('booking_start', {
            name: 'PRIVATE',
            contact_value: 'PRIVATE',
            vehicle_id: 3,
            reference: 'PRIVATE',
            channel: 'telegram',
        }),
        { event: 'booking_start', vehicle_id: 3, channel: 'telegram' },
    );
    assert.equal(publicEvent('contact_value', {}), null);
    assert.deepEqual(
        publicEvent('channel_click', {
            channel: 'https://unknown',
            vehicle_id: -1,
        }),
        { event: 'channel_click' },
    );
});

const app = await readFile(
    new URL('../../resources/js/website/app.ts', import.meta.url),
    'utf8',
);
test('public selects support keyboard, outside dismissal, Tab and reduced motion', () => {
    for (const key of [
        'Escape',
        'Tab',
        'ArrowDown',
        'ArrowUp',
        'Home',
        'End',
        'Enter',
    ]) {
        assert.ok(app.includes(`'${key}'`), key);
    }

    assert.match(app, /pointerdown/);
    assert.match(app, /prefers-reduced-motion/);
    assert.match(app, /reduceMotion\.matches \? 0 : 350/);
    assert.match(app, /\/\/ Do not trap Tab/);
});

test('pickup UI uses the server minimum separately from the actual today marker', async () => {
    const dates = await readFile(
        new URL(
            '../../resources/views/website/partials/dates.blade.php',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(dates, /\$website\['earliest_pickup_date'\]/);
    assert.match(dates, /min="\{\{ \$minimumPickup \}\}"/);
    assert.match(app, /dataset\.earliestPickupDate/);
    assert.match(app, /dataset\.todayDate/);
    assert.match(app, /day === businessToday/);
    assert.match(app, /beforePickup \|\| unavailable/);
    assert.doesNotMatch(app, /getHours\(\)/);
});

test('quote UI discards stale requests and inserts API values as text', () => {
    assert.match(app, /currentRequest\?\.abort\(\)/);
    assert.match(app, /request !== currentRequest/);
    assert.doesNotMatch(app, /innerHTML/);
    assert.match(app, /credentials: 'same-origin'/);
    assert.match(app, /dataset\.analyticsEnabled === 'true'/);
});

test('catalog previews use backend day/month calculations with date and duration qualifiers', async () => {
    const card = await readFile(
        new URL(
            '../../resources/views/website/partials/card.blade.php',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(card, /\$vehicle\['pricePreview'\]/);
    assert.match(card, /!\$cardQuote && \$pricePreview/);
    assert.match(card, /\$pricePreview\['day'\]\['average_daily'\]/);
    assert.match(card, /\$pricePreview\['month'\]\['final_total'\]/);
    assert.match(card, /preview_minimum/);
    assert.match(card, /preview_month_days/);
    assert.match(card, /\$pricePreview\['starts_on'\]/);
    assert.match(card, /preview_note/);
});
