import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const [fleetPrices, fleetIndex, locale] = await Promise.all([
    readFile(
        new URL(
            '../../resources/js/components/vehicles/FleetPrices.vue',
            import.meta.url,
        ),
        'utf8',
    ),
    readFile(
        new URL('../../resources/js/pages/vehicles/Index.vue', import.meta.url),
        'utf8',
    ),
    readFile(
        new URL('../../resources/js/composables/useLocale.ts', import.meta.url),
        'utf8',
    ),
]);

test('shared Fleet pricing links to the vehicle pricing editor with return context', () => {
    assert.match(fleetPrices, /vehicleEditHref: string/);
    assert.match(fleetPrices, /v-if="source\.preset"/);
    assert.match(fleetPrices, /<Link :href="vehicleEditHref">/);
    assert.match(fleetPrices, /Individual prices for this vehicle/);

    assert.match(fleetIndex, /function vehiclePricingHref\(id: number\)/);
    assert.match(fleetIndex, /return_to: page\.url/);
    assert.match(fleetIndex, /`\$\{vehicleHref\(id\)\}#pricing`/);
    assert.match(
        fleetIndex,
        /:vehicle-edit-href="vehiclePricingHref\(vehicle\.id\)"/,
    );
});

test('the existing dirty-price navigation guard also protects the edit link', () => {
    assert.match(fleetPrices, /router\.on\('before'/);
    assert.match(fleetPrices, /if \(dirty\.value \|\| saving\.value\)/);
    assert.match(fleetPrices, /event\.preventDefault\(\)/);
    assert.match(
        fleetPrices,
        /Save or close the price editor before leaving this page\./,
    );
});

test('the individual-price action has explicit Russian localization', () => {
    assert.match(
        locale,
        /'Individual prices for this vehicle':\s*'Индивидуальные цены этого байка'/,
    );
});
