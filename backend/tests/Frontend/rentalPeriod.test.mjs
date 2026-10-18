import assert from 'node:assert/strict';
import test from 'node:test';
import {rentalDayCount} from '../../resources/js/lib/rentalPeriod.ts';
test('rental preview matches date-only days, not inclusive calendar cells', () => {
    assert.equal(rentalDayCount('2026-08-20', '2026-08-27'), 7);
    assert.equal(rentalDayCount('2026-10-20', '2026-10-22'), 2);
    assert.equal(rentalDayCount('2026-10-20', '2026-10-20'), 1);
    assert.equal(rentalDayCount('2026-12-31', '2027-01-01'), 1);
    assert.equal(rentalDayCount('2026-02-30', '2026-03-02'), 0);
    assert.equal(rentalDayCount('2026-10-22', '2026-10-20'), 0);
});
