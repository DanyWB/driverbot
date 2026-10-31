import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {
    vehicleSpecificationFields,
    vehicleSpecificationPayload,
} from '../../resources/js/lib/vehicleSpecifications.ts';

test('new and legacy vehicles keep unknown specifications empty and submit null', () => {
    assert.deepEqual(
        vehicleSpecificationPayload(vehicleSpecificationFields()),
        {
            brand: null,
            model: null,
            engine_cc: null,
            color: null,
            abs: null,
            seats: null,
            transmission: null,
        },
    );
    assert.equal(vehicleSpecificationFields({ abs: false }).abs, 0);
    assert.equal(vehicleSpecificationFields({ abs: true }).abs, 1);
});

test('known specifications round-trip without inferring details from the vehicle name', () => {
    const source = {
        brand: 'Honda',
        model: 'PCX',
        engine_cc: 160,
        color: 'Black',
        abs: false,
        seats: 2,
        transmission: 'automatic',
    };
    assert.deepEqual(
        vehicleSpecificationPayload(vehicleSpecificationFields(source)),
        source,
    );
    assert.deepEqual(
        vehicleSpecificationPayload({
            ...vehicleSpecificationFields(source),
            brand: '  ',
            engine_cc: '',
            seats: ' ',
            abs: null,
            transmission: null,
        }),
        {
            ...source,
            brand: null,
            engine_cc: null,
            seats: null,
            abs: null,
            transmission: null,
        },
    );
});

test('validation keeps invalid nonblank values for the server instead of silently clearing them', () => {
    const fields = {
        ...vehicleSpecificationFields(),
        engine_cc: 160.5,
        seats: 0,
    };
    assert.equal(vehicleSpecificationPayload(fields).engine_cc, 160.5);
    assert.equal(vehicleSpecificationPayload(fields).seats, 0);
});

test('Fleet form reuses custom selects, visible labels and optional numeric bounds', () => {
    const form = fs.readFileSync(
        new URL(
            '../../resources/js/components/vehicles/VehicleForm.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.doesNotMatch(form, /<select\b/);

    for (const field of ['engine_cc', 'abs', 'seats', 'transmission']) {
        assert.match(form, new RegExp(`id="vehicle_${field}"`));
    }

    assert.match(form, /vehicleSpecificationPayload\(data\)/);
    assert.match(form, /max="10000"/);
    assert.match(form, /motion-reduce:transition-none/);
});
