<script setup lang="ts">
import { computed, ref } from 'vue';
import AdminSelect from '@/components/AdminSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import {
    pricingSeasons,
    pricingTiers,
    pricingTierLabels,
    pricingSeasonLabels,
} from '@/lib/pricingTools';
import type { PricingCalculationSettings } from '@/types';

const settings = defineModel<PricingCalculationSettings>({ required: true });
defineProps<{ disabled?: boolean }>();
const emit = defineEmits<{ recalculate: [] }>();
const { t, locale } = useLocale();
const overrideSeason = ref<'high' | 'middle' | 'low'>('high');
const overrideDay = ref(32);
const months = computed(() =>
    Array.from({ length: 12 }, (_, index) =>
        new Date(2026, index, 1).toLocaleDateString(
            locale.value === 'ru' ? 'ru-RU' : 'en-US',
            { month: 'long' },
        ),
    ),
);
function override(day: number, event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    const key = `${overrideSeason.value}:${day}`;

    if (value === '') {
        delete settings.value.dayOverrides[key];
    } else {
        settings.value.dayOverrides[key] = Number(value);
    }
}
</script>

<template>
    <section class="space-y-5 rounded-lg border p-4">
        <h3 class="font-semibold">{{ t('Calculation rules') }}</h3>
        <div class="grid gap-4 sm:grid-cols-3">
            <label
                >{{ t('Interpolation mode')
                }}<AdminSelect
                    v-model="settings.rules.mode"
                    :disabled="disabled"
                    :options="[
                        {
                            value: 'linear',
                            label: t('Linear between packages'),
                        },
                        { value: 'delayed', label: t('Delayed discount') },
                    ]"
            /></label>
            <label
                >{{ t('Rounding increment, THB')
                }}<Input
                    v-model.number="settings.rules.step"
                    type="number"
                    min="1"
                    max="100000"
                    :disabled="disabled"
            /></label>
            <label
                >{{ t('Days before discount starts')
                }}<Input
                    v-model.number="settings.rules.holdDays"
                    type="number"
                    min="0"
                    max="30"
                    :disabled="disabled"
            /></label>
            <label
                ><input
                    v-model="settings.rules.cap"
                    type="checkbox"
                    :disabled="disabled"
                />
                {{ t('Cap at next package') }}</label
            >
            <label
                ><input
                    v-model="settings.rules.avg"
                    type="checkbox"
                    :disabled="disabled"
                />
                {{ t('Check average daily rate') }}</label
            >
            <label
                ><input
                    v-model="settings.rules.monthly"
                    type="checkbox"
                    :disabled="disabled"
                />
                {{ t('Apply monthly rate early') }}</label
            >
            <label
                >{{ t('Monthly rate start day')
                }}<Input
                    v-model.number="settings.rules.monthlyStart"
                    type="number"
                    min="1"
                    max="30"
                    :disabled="disabled"
            /></label>
        </div>
        <p class="text-sm text-muted-foreground">
            {{
                t(
                    'Package totals remain exact. Intermediate days use interpolation and the selected rounding increment. A full calendar month uses the monthly rate.',
                )
            }}
        </p>
        <h3 class="font-semibold">
            {{ t('Seasonal discounts and package rounding') }}
        </h3>
        <div class="flex flex-wrap gap-4">
            <label
                ><input
                    v-model="settings.prefs.linkMiddle"
                    type="checkbox"
                    :disabled="disabled"
                    @change="emit('recalculate')"
                />
                {{ t('Calculate middle season from high season') }}</label
            >
            <label
                ><input
                    v-model="settings.prefs.linkLow"
                    type="checkbox"
                    :disabled="disabled"
                    @change="emit('recalculate')"
                />
                {{ t('Calculate low season from high season') }}</label
            >
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[700px] text-sm">
                <thead>
                    <tr>
                        <th>{{ t('Season') }}</th>
                        <th v-for="tier in pricingTiers" :key="tier">
                            {{ t(pricingTierLabels[tier]) }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="season in pricingSeasons" :key="season">
                        <th>{{ t(pricingSeasonLabels[season]) }}</th>
                        <td
                            v-for="(tier, index) in pricingTiers"
                            :key="tier"
                            class="p-2"
                        >
                            <label class="text-xs"
                                >{{ t('Rounding, THB')
                                }}<Input
                                    v-model.number="
                                        settings.prefs.roundSteps[season][index]
                                    "
                                    type="number"
                                    min="1"
                                    max="100000"
                                    :disabled="disabled"
                                    :aria-label="`${t(pricingSeasonLabels[season])} ${t(pricingTierLabels[tier])} ${t('Rounding, THB')}`"
                                    @change="emit('recalculate')"
                            /></label>
                            <label v-if="season !== 'high'" class="text-xs"
                                >{{ t('Discount, %')
                                }}<Input
                                    v-model.number="
                                        settings.prefs.discounts[season][index]
                                    "
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.1"
                                    :disabled="
                                        disabled ||
                                        !settings.prefs[
                                            season === 'middle'
                                                ? 'linkMiddle'
                                                : 'linkLow'
                                        ]
                                    "
                                    :aria-label="`${t(pricingSeasonLabels[season])} ${t(pricingTierLabels[tier])} ${t('Discount, %')}`"
                                    @change="emit('recalculate')"
                            /></label>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <h3 class="font-semibold">{{ t('Season calendar') }}</h3>
        <p class="text-sm text-muted-foreground">
            {{
                t(
                    'The season with most rental days applies to the whole rental. Ties use the cheaper monthly package. The return day is excluded.',
                )
            }}
        </p>
        <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <label v-for="(month, index) in months" :key="month" class="text-sm"
                >{{ month
                }}<AdminSelect
                    v-model="settings.prefs.seasonCalendar[index]"
                    :disabled="disabled"
                    :options="
                        pricingSeasons.map((s) => ({
                            value: s,
                            label: t(pricingSeasonLabels[s]),
                        }))
                    "
            /></label>
        </div>
        <h3 class="font-semibold">{{ t('Daily price overrides') }}</h3>
        <div class="flex items-center gap-3">
            <AdminSelect
                v-model="overrideSeason"
                :options="
                    pricingSeasons.map((s) => ({
                        value: s,
                        label: t(pricingSeasonLabels[s]),
                    }))
                "
                :aria-label="t('Season')"
            /><Button
                type="button"
                variant="outline"
                :disabled="disabled"
                @click="
                    Object.keys(settings.dayOverrides)
                        .filter((k) => k.startsWith(overrideSeason + ':'))
                        .forEach((k) => delete settings.dayOverrides[k])
                "
                >{{ t('Reset season overrides') }}</Button
            >
        </div>
        <p class="text-sm text-muted-foreground">
            {{
                t(
                    'Leave an override empty to use the formula. Overrides for days 30 and 31 also set calendar-month and preliminary-month rates respectively.',
                )
            }}
        </p>
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
            <label
                v-for="day in 31"
                :key="`${overrideSeason}:${day}`"
                class="text-xs"
                >{{ day }} {{ t('days')
                }}<Input
                    :model-value="
                        settings.dayOverrides[`${overrideSeason}:${day}`] ?? ''
                    "
                    type="number"
                    min="0"
                    max="100000000"
                    :disabled="disabled"
                    :placeholder="t('Auto')"
                    @input="override(day, $event)"
            /></label>
        </div>
        <p
            v-if="
                Object.keys(settings.dayOverrides).some(
                    (k) => Number(k.split(':')[1]) > 31,
                )
            "
            class="text-sm text-muted-foreground"
        >
            {{
                t(
                    'Long-period overrides are retained in this preset and its JSON export.',
                )
            }}
        </p>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="text-sm"
                >{{ t('Long-period override day')
                }}<Input
                    v-model.number="overrideDay"
                    type="number"
                    min="1"
                    max="365"
                    :disabled="disabled" /></label
            ><label class="text-sm"
                >{{ t('Long-period override, THB')
                }}<Input
                    :model-value="
                        settings.dayOverrides[
                            `${overrideSeason}:${overrideDay}`
                        ] ?? ''
                    "
                    type="number"
                    min="0"
                    max="100000000"
                    :disabled="
                        disabled ||
                        !Number.isInteger(overrideDay) ||
                        overrideDay < 1 ||
                        overrideDay > 365
                    "
                    :placeholder="t('Auto')"
                    @input="override(overrideDay, $event)"
            /></label>
        </div>
    </section>
</template>
