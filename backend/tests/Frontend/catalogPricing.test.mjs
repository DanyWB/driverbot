import assert from 'node:assert/strict';
import test from 'node:test';

import {
    catalogPriceBackendError,
    catalogPriceFieldPath,
    CATALOG_PRICE_STEP,
    validateCatalogPrice,
} from '../../resources/js/lib/catalogPricing.ts';

test('catalog prices are required positive integers divisible by 50', () => {
    assert.equal(CATALOG_PRICE_STEP, 50);
    assert.equal(validateCatalogPrice('', true), 'required');
    assert.equal(validateCatalogPrice('0', true), 'positive_integer');
    assert.equal(validateCatalogPrice('100.5', true), 'positive_integer');
    assert.equal(validateCatalogPrice('1275', true), 'multiple_of_step');
    assert.equal(validateCatalogPrice(1250, true), null);
});

test('an optional empty catalog price remains valid', () => {
    assert.equal(validateCatalogPrice('   ', false), null);
});

test('a backend validation error is mapped to its exact pricing cell', () => {
    const errors = {
        'prices.low.7d': 'Price must be divisible by 50 THB.',
    };

    assert.equal(catalogPriceFieldPath('low', '7d'), 'prices.low.7d');
    assert.equal(
        catalogPriceBackendError(errors, 'low', '7d'),
        errors['prices.low.7d'],
    );
    assert.equal(catalogPriceBackendError(errors, 'high', '7d'), undefined);
});
