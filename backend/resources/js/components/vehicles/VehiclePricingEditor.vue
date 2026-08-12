<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Calculator, Save, TriangleAlert } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import PriceBreakdown from '@/components/bookings/PriceBreakdown.vue';
import AdminDateInput from '@/components/AdminDateInput.vue';
import AdminSelect from '@/components/AdminSelect.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { formatMoney } from '@/lib/bookings';
import {
    catalogPriceBackendError,
    catalogPriceFieldPath,
    CATALOG_PRICE_STEP,
    isEmptyCatalogPrice,
    validateCatalogPrice,
} from '@/lib/catalogPricing';
import type {
    PriceQuote,
    PricingTemplateOption,
    VehiclePriceCell,
} from '@/types';

const props = defineProps<{
    vehicleId: number;
    pricing: Record<string, Record<string, VehiclePriceCell>>;
    pricingProfile: string | null;
    templates: PricingTemplateOption[];
}>();
const { t } = useLocale();

const seasons = [
    { key: 'high', label: 'High season' },
    { key: 'middle', label: 'Middle season' },
    { key: 'low', label: 'Low season' },
];
const tiers = [
    { key: '1d', label: '1 day' },
    { key: '7d', label: '7 days' },
    { key: '14d', label: '14 days' },
    { key: '21d', label: '21 days' },
    { key: 'month', label: '30+ days' },
];

type Prices = Record<string, Record<string, number | string>>;
type Enabled = Record<string, Record<string, boolean>>;

const prices: Prices = {};
const enabled: Enabled = {};

for (const season of seasons) {
    prices[season.key] = {};
    enabled[season.key] = {};

    for (const tier of tiers) {
        const cell = props.pricing[season.key]?.[tier.key];
        prices[season.key][tier.key] = cell?.package_total ?? '';
        enabled[season.key][tier.key] = cell?.is_active ?? false;
    }
}

const form = useForm<{
    prices: Prices;
    enabled: Enabled;
    template: string | null;
}>({ prices, enabled, template: null });
const templateKey = ref(
    props.templates.some((template) => template.key === props.pricingProfile)
        ? (props.pricingProfile ?? '')
        : (props.templates[0]?.key ?? ''),
);
const basePrices = ref<Record<string, number | string>>({
    high: props.pricing.high?.['1d']?.package_total ?? '',
    middle: props.pricing.middle?.['1d']?.package_total ?? '',
    low: props.pricing.low?.['1d']?.package_total ?? '',
});
const generating = ref(false);
const generatorError = ref('');
const generatorFieldErrors = ref<Record<string, string>>({});
const basePriceTouched = ref<Record<string, boolean>>({});
const generatorValidationAttempted = ref(false);
const priceTouched = ref<Record<string, boolean>>({});
const pricingValidationAttempted = ref(false);
const generatorPanel = ref<HTMLElement | null>(null);
const pricingForm = ref<HTMLFormElement | null>(null);
const completeCount = computed(() => {
    let count = 0;

    for (const season of seasons) {
        for (const tier of tiers) {
            if (
                form.enabled[season.key]?.[tier.key] &&
                Number(form.prices[season.key]?.[tier.key]) > 0
            ) {
                count++;
            }
        }
    }

    return count;
});

const startsOn = ref('');
const endsOn = ref('');
const quote = ref<PriceQuote | null>(null);
const quoteError = ref('');
const quoteLoading = ref(false);
const pricingError = computed(() => {
    const errors = form.errors as Record<string, string>;

    return (
        errors.template ??
        errors.prices ??
        errors.enabled ??
        Object.entries(errors).find(([key]) => key.startsWith('enabled.'))?.[1]
    );
});
const hasGeneratorValidationErrors = computed(() =>
    seasons.some(
        (season) =>
            validateCatalogPrice(basePrices.value[season.key] ?? '', true) !==
            null,
    ),
);
const hasPricingValidationErrors = computed(() =>
    seasons.some((season) =>
        tiers.some(
            (tier) =>
                validateCatalogPrice(
                    form.prices[season.key]?.[tier.key] ?? '',
                    form.enabled[season.key]?.[tier.key] ?? false,
                ) !== null,
        ),
    ),
);

watch(
    () => form.isDirty,
    (isDirty) => {
        if (isDirty) {
            quote.value = null;
            quoteError.value = '';
        }
    },
);

async function focusFirstInvalid(container: HTMLElement | null): Promise<void> {
    await nextTick();
    const input = container?.querySelector<HTMLElement>(
        '[data-catalog-price-input][aria-invalid="true"]',
    );

    input?.focus();
    input?.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

function submit(): void {
    pricingValidationAttempted.value = true;

    if (hasPricingValidationErrors.value) {
        void focusFirstInvalid(pricingForm.value);

        return;
    }

    form.patch(`/vehicles/${props.vehicleId}/pricing`, {
        preserveScroll: true,
        onSuccess: () => {
            form.template = null;
            form.defaults();
            quote.value = null;
            pricingValidationAttempted.value = false;
            priceTouched.value = {};
        },
        onError: () => focusFirstInvalid(pricingForm.value),
    });
}

async function generatePrices(): Promise<void> {
    generatorValidationAttempted.value = true;

    if (!templateKey.value || hasGeneratorValidationErrors.value) {
        generatorError.value = t(
            'Select a template and enter all three base prices.',
        );
        void focusFirstInvalid(generatorPanel.value);

        return;
    }

    if (
        completeCount.value > 0 &&
        !window.confirm(
            t('Replace the current table with calculated package prices?'),
        )
    ) {
        return;
    }

    generating.value = true;
    generatorError.value = '';
    generatorFieldErrors.value = {};
    const params = new URLSearchParams({ template: templateKey.value });

    for (const season of seasons) {
        params.set(
            `base_prices[${season.key}]`,
            String(basePrices.value[season.key]),
        );
    }

    try {
        const response = await fetch(
            `/vehicles/${props.vehicleId}/pricing/generate?${params}`,
            { headers: { Accept: 'application/json' } },
        );
        const payload = await response.json();

        if (!response.ok) {
            generatorFieldErrors.value = Object.fromEntries(
                seasons.flatMap((season) => {
                    const error = payload.errors?.[`base_prices.${season.key}`];
                    const message = Array.isArray(error) ? error[0] : error;

                    return typeof message === 'string'
                        ? [[season.key, message]]
                        : [];
                }),
            );
            generatorError.value = t(
                payload.message ?? 'Price generation failed.',
            );
            void focusFirstInvalid(generatorPanel.value);

            return;
        }

        for (const season of seasons) {
            for (const tier of tiers) {
                form.prices[season.key][tier.key] =
                    payload.data.prices[season.key][tier.key];
                form.enabled[season.key][tier.key] = true;
            }
        }

        form.template = templateKey.value;
        quote.value = null;
    } catch {
        generatorError.value = t('Price generation is unavailable.');
    } finally {
        generating.value = false;
    }
}

function catalogPriceValidationMessage(
    value: number | string,
    required: boolean,
): string | undefined {
    const error = validateCatalogPrice(value, required);

    if (error === 'required') {
        return t('Enter a price.');
    }

    if (error === 'positive_integer') {
        return t('Price must be a positive whole number.');
    }

    if (error === 'multiple_of_step') {
        return t('Price must be a multiple of 50 THB.');
    }

    return undefined;
}

function basePriceError(season: string): string | undefined {
    const value = basePrices.value[season] ?? '';
    const shouldShowClientError =
        generatorValidationAttempted.value ||
        basePriceTouched.value[season] ||
        !isEmptyCatalogPrice(value);

    return (
        (shouldShowClientError
            ? catalogPriceValidationMessage(value, true)
            : undefined) ??
        (generatorFieldErrors.value[season]
            ? t(generatorFieldErrors.value[season])
            : undefined)
    );
}

function touchBasePrice(season: string): void {
    basePriceTouched.value[season] = true;
    delete generatorFieldErrors.value[season];
    generatorError.value = '';
}

function pricePath(season: string, tier: string): `prices.${string}` {
    return catalogPriceFieldPath(season, tier);
}

function priceError(season: string, tier: string): string | undefined {
    const path = pricePath(season, tier);
    const value = form.prices[season]?.[tier] ?? '';
    const required = form.enabled[season]?.[tier] ?? false;
    const shouldShowClientError =
        pricingValidationAttempted.value ||
        priceTouched.value[path] ||
        !isEmptyCatalogPrice(value);
    const backendError = catalogPriceBackendError(
        form.errors as Record<string, string>,
        season,
        tier,
    );

    return (
        (shouldShowClientError
            ? catalogPriceValidationMessage(value, required)
            : undefined) ?? (backendError ? t(backendError) : undefined)
    );
}

function touchPrice(season: string, tier: string): void {
    const path = pricePath(season, tier);

    priceTouched.value[path] = true;
    form.clearErrors(path);
}

async function preview(): Promise<void> {
    if (!startsOn.value || !endsOn.value) {
        return;
    }

    quoteLoading.value = true;
    quote.value = null;
    quoteError.value = '';
    const params = new URLSearchParams({
        starts_on: startsOn.value,
        ends_on: endsOn.value,
    });

    try {
        const response = await fetch(
            `/vehicles/${props.vehicleId}/pricing/quote?${params}`,
            { headers: { Accept: 'application/json' } },
        );
        const payload = await response.json();

        if (!response.ok) {
            quoteError.value = t(payload.message ?? 'Price preview failed.');

            return;
        }

        quote.value = payload.data;
    } catch {
        quoteError.value = t('Price preview is unavailable.');
    } finally {
        quoteLoading.value = false;
    }
}
</script>

<template>
    <section id="pricing" class="border-t px-4 py-6 sm:px-6 lg:px-8">
        <div
            class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <p class="text-sm text-muted-foreground">
                    {{ t('THB package totals') }}
                </p>
                <h2 class="text-lg font-semibold">
                    {{ t('Seasonal pricing') }}
                </h2>
            </div>
            <div
                class="flex items-center gap-2 text-sm font-medium"
                :class="
                    completeCount === 15 ? 'text-emerald-700' : 'text-amber-700'
                "
            >
                <TriangleAlert v-if="completeCount !== 15" class="size-4" />{{
                    completeCount
                }}/15 {{ t('active prices') }}
            </div>
        </div>

        <div
            ref="generatorPanel"
            class="mt-5 rounded-md border bg-muted/20 p-4"
        >
            <div class="flex flex-col gap-1">
                <h3 class="font-semibold">{{ t('Price generator') }}</h3>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'The 1-day tariff equals the seasonal base price. Packages from 7 days are calculated by the discount formula and rounded down to 50 THB.',
                        )
                    }}
                </p>
                <p class="text-xs font-medium text-muted-foreground">
                    {{ t('Price must be a multiple of 50 THB.') }}
                </p>
            </div>
            <div
                class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(180px,1.35fr)_repeat(3,minmax(120px,1fr))_auto] lg:items-end"
            >
                <div>
                    <Label for="pricing_template">{{
                        t('Discount template')
                    }}</Label>
                    <AdminSelect
                        id="pricing_template"
                        v-model="templateKey"
                        class="mt-2"
                        :options="
                            templates.map((template) => ({
                                value: template.key,
                                label: t(template.label),
                            }))
                        "
                    />
                </div>
                <div v-for="season in seasons" :key="season.key">
                    <Label :for="`base_price_${season.key}`">{{
                        t(season.label)
                    }}</Label>
                    <Input
                        :id="`base_price_${season.key}`"
                        v-model.number="basePrices[season.key]"
                        class="mt-2 tabular-nums"
                        type="number"
                        :min="CATALOG_PRICE_STEP"
                        :step="CATALOG_PRICE_STEP"
                        :placeholder="t('THB per day')"
                        :aria-invalid="Boolean(basePriceError(season.key))"
                        :aria-describedby="
                            basePriceError(season.key)
                                ? `base_price_${season.key}_error`
                                : undefined
                        "
                        data-catalog-price-input
                        @input="touchBasePrice(season.key)"
                    />
                    <InputError
                        :id="`base_price_${season.key}_error`"
                        class="mt-1"
                        :message="basePriceError(season.key)"
                        role="alert"
                    />
                </div>
                <Button
                    type="button"
                    variant="secondary"
                    :disabled="generating"
                    @click="generatePrices"
                >
                    <Calculator />{{ t('Fill table') }}
                </Button>
            </div>
            <p
                v-if="generatorError"
                class="mt-3 text-sm text-destructive"
                role="alert"
            >
                {{ generatorError }}
            </p>
            <p class="mt-3 text-xs text-muted-foreground">
                {{
                    t(
                        'The generator only fills the draft table. Review the amounts and save them manually.',
                    )
                }}
            </p>
        </div>

        <form
            ref="pricingForm"
            class="mt-6"
            novalidate
            @submit.prevent="submit"
        >
            <p class="mb-3 text-xs text-muted-foreground">
                {{ t('Price must be a multiple of 50 THB.') }}
            </p>
            <div class="overflow-x-auto rounded-md border">
                <table class="w-full min-w-[850px] table-fixed text-sm">
                    <thead
                        class="border-b bg-muted/35 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="w-36 px-3 py-3 font-medium">
                                {{ t('Season') }}
                            </th>
                            <th
                                v-for="tier in tiers"
                                :key="tier.key"
                                class="px-3 py-3 font-medium"
                            >
                                {{ t(tier.label) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="season in seasons" :key="season.key">
                            <th class="px-3 py-3 text-left font-medium">
                                {{ t(season.label) }}
                            </th>
                            <td
                                v-for="tier in tiers"
                                :key="tier.key"
                                class="px-3 py-3 align-top"
                            >
                                <Input
                                    v-model.number="
                                        form.prices[season.key][tier.key]
                                    "
                                    type="number"
                                    :min="CATALOG_PRICE_STEP"
                                    :step="CATALOG_PRICE_STEP"
                                    class="tabular-nums"
                                    :aria-label="`${t(season.label)}, ${t(tier.label)}`"
                                    :aria-invalid="
                                        Boolean(
                                            priceError(season.key, tier.key),
                                        )
                                    "
                                    :aria-describedby="
                                        priceError(season.key, tier.key)
                                            ? `price_${season.key}_${tier.key}_error`
                                            : undefined
                                    "
                                    data-catalog-price-input
                                    @input="touchPrice(season.key, tier.key)"
                                />
                                <InputError
                                    :id="`price_${season.key}_${tier.key}_error`"
                                    class="mt-1"
                                    :message="priceError(season.key, tier.key)"
                                    role="alert"
                                />
                                <label
                                    class="mt-2 flex items-center gap-2 text-xs text-muted-foreground"
                                    ><input
                                        v-model="
                                            form.enabled[season.key][tier.key]
                                        "
                                        type="checkbox"
                                        class="size-4 accent-current"
                                        @change="
                                            touchPrice(season.key, tier.key)
                                        "
                                    />{{ t('Active') }}</label
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <InputError
                class="mt-2"
                :message="pricingError ? t(pricingError) : undefined"
                role="alert"
            />
            <div class="mt-4 flex justify-end">
                <Button type="submit" :disabled="form.processing"
                    ><Save />{{ t('Save prices') }}</Button
                >
            </div>
        </form>

        <div class="mt-8 border-t pt-6">
            <h3 class="font-semibold">{{ t('Saved price preview') }}</h3>
            <form
                class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                @submit.prevent="preview"
            >
                <div>
                    <Label for="preview_starts_on">{{ t('Start date') }}</Label
                    ><AdminDateInput
                        id="preview_starts_on"
                        v-model="startsOn"
                        class="mt-2"
                    />
                </div>
                <div>
                    <Label for="preview_ends_on">{{ t('End date') }}</Label
                    ><AdminDateInput
                        id="preview_ends_on"
                        v-model="endsOn"
                        class="mt-2"
                    />
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="
                        !startsOn || !endsOn || quoteLoading || form.isDirty
                    "
                    ><Calculator />{{ t('Calculate') }}</Button
                >
            </form>
            <p v-if="quoteError" class="mt-3 text-sm text-red-600">
                {{ quoteError }}
            </p>
            <div
                v-if="quote"
                class="mt-5 grid gap-5 border-t pt-5 lg:grid-cols-[220px_minmax(0,1fr)]"
            >
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Rounded total') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ formatMoney(quote.final_total, quote.currency) }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ t('Days count', { count: quote.total_days }) }} ·
                        {{ t(quote.tier_key) }}
                    </p>
                </div>
                <PriceBreakdown
                    :items="quote.breakdown"
                    :currency="quote.currency"
                />
            </div>
        </div>
    </section>
</template>
