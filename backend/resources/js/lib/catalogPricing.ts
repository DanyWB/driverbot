export const CATALOG_PRICE_STEP = 50;

export type CatalogPriceValidationError =
    'required' | 'positive_integer' | 'multiple_of_step';

export function catalogPriceFieldPath(
    season: string,
    tier: string,
): `prices.${string}.${string}` {
    return `prices.${season}.${tier}`;
}

export function catalogPriceBackendError(
    errors: Record<string, string>,
    season: string,
    tier: string,
): string | undefined {
    return errors[catalogPriceFieldPath(season, tier)];
}

export function isEmptyCatalogPrice(value: number | string): boolean {
    return typeof value === 'string' && value.trim() === '';
}

export function validateCatalogPrice(
    value: number | string,
    required: boolean,
): CatalogPriceValidationError | null {
    if (isEmptyCatalogPrice(value)) {
        return required ? 'required' : null;
    }

    const numericValue =
        typeof value === 'number' ? value : Number(value.trim());

    if (
        !Number.isFinite(numericValue) ||
        !Number.isInteger(numericValue) ||
        numericValue <= 0
    ) {
        return 'positive_integer';
    }

    return numericValue % CATALOG_PRICE_STEP === 0 ? null : 'multiple_of_step';
}
