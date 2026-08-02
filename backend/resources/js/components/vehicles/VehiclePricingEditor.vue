<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Calculator, Save, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PriceBreakdown from '@/components/bookings/PriceBreakdown.vue';
import AdminDateInput from '@/components/AdminDateInput.vue';
import AdminSelect from '@/components/AdminSelect.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { formatMoney } from '@/lib/bookings';
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
        Object.entries(errors).find(
            ([key]) => key.startsWith('prices.') || key.startsWith('enabled.'),
        )?.[1]
    );
});

watch(
    () => form.isDirty,
    (isDirty) => {
        if (isDirty) {
            quote.value = null;
            quoteError.value = '';
        }
    },
);

function submit(): void {
    form.patch(`/vehicles/${props.vehicleId}/pricing`, {
        preserveScroll: true,
        onSuccess: () => {
            form.template = null;
            form.defaults();
            quote.value = null;
        },
    });
}

async function generatePrices(): Promise<void> {
    if (
        !templateKey.value ||
        seasons.some(
            (season) => Number(basePrices.value[season.key] ?? 0) < 100,
        )
    ) {
        generatorError.value = t(
            'Select a template and enter all three base prices.',
        );

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
            generatorError.value = t(
                payload.message ?? 'Price generation failed.',
            );

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

        <div class="mt-5 rounded-md border bg-muted/20 p-4">
            <div class="flex flex-col gap-1">
                <h3 class="font-semibold">{{ t('Price generator') }}</h3>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'The 1-day tariff equals the seasonal base price. Packages from 7 days are calculated by the discount formula and rounded down to 100 THB.',
                        )
                    }}
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
                        min="100"
                        step="1"
                        :placeholder="t('THB per day')"
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
            <p v-if="generatorError" class="mt-3 text-sm text-destructive">
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

        <form class="mt-6" @submit.prevent="submit">
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
                                    min="1"
                                    step="1"
                                    class="tabular-nums"
                                    :aria-label="`${t(season.label)}, ${t(tier.label)}`"
                                />
                                <label
                                    class="mt-2 flex items-center gap-2 text-xs text-muted-foreground"
                                    ><input
                                        v-model="
                                            form.enabled[season.key][tier.key]
                                        "
                                        type="checkbox"
                                        class="size-4 accent-current"
                                    />{{ t('Active') }}</label
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <InputError class="mt-2" :message="pricingError" />
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
