import type { PricingCalculationSettings, PricingPresetPrices } from '@/types';

export const pricingSeasons = ['high', 'middle', 'low'] as const;
export const pricingTiers = ['1d', '7d', '14d', '21d', 'month'] as const;
export const pricingTierLabels = {
    '1d': '1 day',
    '7d': '7 days',
    '14d': '14 days',
    '21d': '21 days',
    month: 'Month',
} as const;
export const pricingSeasonLabels = {
    high: 'High season',
    middle: 'Middle season',
    low: 'Low season',
} as const;
export function defaultPricingSettings(): PricingCalculationSettings {
    return {
        algorithm: 'calculator-v1',
        rules: {
            step: 50,
            mode: 'linear',
            holdDays: 2,
            cap: true,
            monthly: true,
            monthlyStart: 28,
            avg: true,
        },
        prefs: {
            linkMiddle: false,
            linkLow: false,
            roundSteps: {
                high: [50, 50, 50, 50, 50],
                middle: [50, 50, 50, 50, 50],
                low: [50, 50, 50, 50, 50],
            },
            discounts: {
                middle: [10, 10, 10, 10, 10],
                low: [21, 21, 21, 21, 21],
            },
            seasonCalendar: [
                'high',
                'high',
                'high',
                'middle',
                'middle',
                'low',
                'low',
                'low',
                'low',
                'middle',
                'middle',
                'high',
            ],
        },
        dayOverrides: {},
    };
}
export function linkedPricing(
    prices: PricingPresetPrices,
    settings: PricingCalculationSettings,
): PricingPresetPrices {
    const result = {
        high: { ...prices.high },
        middle: { ...prices.middle },
        low: { ...prices.low },
    };

    for (const season of ['middle', 'low'] as const) {
        if (!settings.prefs[season === 'middle' ? 'linkMiddle' : 'linkLow']) {
            continue;
        }

        pricingTiers.forEach((tier, index) => {
            const step = settings.prefs.roundSteps[season][index];
            result[season][tier] =
                Math.round(
                    (prices.high[tier] *
                        (1 - settings.prefs.discounts[season][index] / 100)) /
                        step,
                ) * step;
        });
    }

    return result;
}
export function pricingCsrfHeaders(): Record<string, string> {
    const meta = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    const cookie = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.slice(11);

    return meta
        ? { 'X-CSRF-TOKEN': meta }
        : cookie
          ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie) }
          : {};
}
export async function pricingRequest(
    url: string,
    method = 'GET',
    data?: unknown,
): Promise<Record<string, unknown>> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...pricingCsrfHeaders(),
        },
        ...(data === undefined ? {} : { body: JSON.stringify(data) }),
    });
    const result = await response.json();

    if (!response.ok) {
        const details = Object.values(result.errors ?? {})
            .flat()
            .join(' ');

        throw new Error(
            details ||
                result.message ||
                'Could not complete pricing operation.',
        );
    }

    return result;
}
