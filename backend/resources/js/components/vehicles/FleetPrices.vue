<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ChevronDown, LoaderCircle } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useLocale } from '@/composables/useLocale';
import { adminRequest } from '@/lib/adminRequest';
import type { PricingPreset, VehiclePriceCell } from '@/types';

const props = defineProps<{
    vehicleId: number;
    vehicleName: string;
    vehicleEditHref: string;
    panelTarget?: string;
}>();
const panelId = useId();
const emit = defineEmits<{ saved: [] }>();
const { t } = useLocale();
const seasons = ['high', 'middle', 'low'] as const;
const tiers = ['1d', '7d', '14d', '21d', 'month'] as const;
type PricingData = {
    revision: number;
    preset: PricingPreset | null;
    matrix: Record<string, Record<string, VehiclePriceCell>>;
};
const source = ref<PricingData | null>(null);
const prices = ref<Record<string, Record<string, string>>>({});
const enabled = ref<Record<string, Record<string, boolean>>>({});
const baseline = ref('');
const open = ref(false);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const success = ref('');
const confirmSave = ref(false);
const confirmDiscard = ref(false);
const draft = computed(() =>
    JSON.stringify({ prices: prices.value, enabled: enabled.value }),
);
const dirty = computed(
    () => source.value !== null && baseline.value !== draft.value,
);
let removeGuard: (() => void) | undefined;

function beforeUnload(event: BeforeUnloadEvent): void {
    if (dirty.value || saving.value) {
        event.preventDefault();
        event.returnValue = '';
    }
}
onMounted(() => {
    window.addEventListener('beforeunload', beforeUnload);
    removeGuard = router.on('before', (event) => {
        if (dirty.value || saving.value) {
            event.preventDefault();
            error.value = t(
                'Save or close the price editor before leaving this page.',
            );
            open.value = true;
        }
    });
});
onBeforeUnmount(() => {
    removeGuard?.();
    window.removeEventListener('beforeunload', beforeUnload);
});

async function load(): Promise<void> {
    loading.value = true;
    error.value = '';

    try {
        const response = await adminRequest(
            `/vehicles/${props.vehicleId}/pricing`,
        );
        const data = response.data as PricingData;
        const nextPrices: typeof prices.value = {};
        const nextEnabled: typeof enabled.value = {};

        for (const season of seasons) {
            nextPrices[season] = {};
            nextEnabled[season] = {};

            for (const tier of tiers) {
                const cell = data.matrix[season][tier];
                nextPrices[season][tier] = String(
                    data.preset?.prices[season][tier] ??
                        cell.package_total ??
                        '',
                );
                nextEnabled[season][tier] =
                    data.preset !== null || cell.is_active;
            }
        }

        source.value = data;
        prices.value = nextPrices;
        enabled.value = nextEnabled;
        baseline.value = draft.value;
    } catch (cause) {
        error.value =
            cause instanceof Error
                ? cause.message
                : t('Unable to load prices.');
    } finally {
        loading.value = false;
    }
}
function toggle(): void {
    if (loading.value || saving.value) {
        return;
    }

    if (open.value && dirty.value) {
        confirmDiscard.value = true;

        return;
    }

    open.value = !open.value;

    if (open.value) {
        void load();
    }
}
function discard(): void {
    source.value = null;
    open.value = false;
    confirmDiscard.value = false;
    error.value = '';
    success.value = '';
}
function submit(): void {
    error.value = '';

    for (const season of seasons) {
        for (const tier of tiers) {
            const value = prices.value[season][tier];

            if (
                (enabled.value[season][tier] && !value.trim()) ||
                (value.trim() &&
                    (!/^\d+$/.test(value) ||
                        Number(value) < 1 ||
                        Number(value) > 100000000))
            ) {
                error.value = t(
                    'Enter positive whole prices for all enabled tariffs.',
                );

                return;
            }
        }
    }

    confirmSave.value = true;
}
async function save(): Promise<void> {
    if (!source.value || saving.value) {
        return;
    }

    saving.value = true;
    error.value = '';
    success.value = '';
    confirmSave.value = false;
    const preset = source.value.preset;
    const matrix = Object.fromEntries(
        seasons.map((season) => [
            season,
            Object.fromEntries(
                tiers.map((tier) => [
                    tier,
                    prices.value[season][tier].trim() === ''
                        ? null
                        : Number(prices.value[season][tier]),
                ]),
            ),
        ]),
    );

    try {
        await adminRequest(
            preset
                ? `/pricing-presets/${preset.id}`
                : `/vehicles/${props.vehicleId}/pricing`,
            'PATCH',
            preset
                ? {
                      name: preset.name,
                      vehicle_type: preset.vehicle_type,
                      version: preset.version,
                      expected_linked_vehicles_count:
                          preset.linked_vehicles_count,
                      prices: matrix,
                  }
                : {
                      prices: matrix,
                      enabled: enabled.value,
                      expected_vehicle_pricing_revision: source.value.revision,
                  },
        );
        // The write succeeded; do not keep a stale version if the refresh fails.
        baseline.value = draft.value;
        source.value = null;
        success.value = t('Prices saved.');
        await load();
    } catch (cause) {
        error.value =
            cause instanceof Error
                ? cause.message
                : t('Unable to save prices.');
    } finally {
        saving.value = false;
    }

    if (success.value) {
        emit('saved');
    }
}
</script>

<template>
    <div class="fleet-prices">
        <Button
            type="button"
            variant="outline"
            size="sm"
            :aria-expanded="open"
            :aria-controls="panelId"
            :disabled="loading || saving"
            @click="toggle"
        >
            {{ t('Prices')
            }}<LoaderCircle
                v-if="loading"
                class="size-4 animate-spin"
            /><ChevronDown
                v-else
                class="size-4 transition-transform duration-[350ms] motion-reduce:transition-none"
                :class="{ 'rotate-180': open }"
            />
        </Button>
        <Teleport :to="panelTarget ?? 'body'" :disabled="!panelTarget" defer>
            <div
                :id="panelId"
                class="grid transition-[grid-template-rows,opacity] duration-[350ms] ease-in-out motion-reduce:transition-none"
                :class="
                    open
                        ? 'grid-rows-[1fr] opacity-100'
                        : 'grid-rows-[0fr] opacity-0'
                "
                :inert="!open"
                :aria-hidden="!open"
            >
                <div class="min-h-0 overflow-hidden">
                    <form
                        class="mt-3 space-y-4 rounded-lg border bg-muted/20 p-4"
                        @submit.prevent="submit"
                    >
                        <div v-if="source" class="space-y-1">
                            <p class="font-semibold">
                                {{
                                    source.preset
                                        ? t('Shared preset') +
                                          ': ' +
                                          source.preset.name
                                        : t('Individual vehicle prices')
                                }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    source.preset
                                        ? t(
                                              'Saving will update :count linked vehicles.',
                                              {
                                                  count: source.preset
                                                      .linked_vehicles_count,
                                              },
                                          )
                                        : vehicleName
                                }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        'Package totals in THB. No automatic rounding.',
                                    )
                                }}
                            </p>
                            <Button
                                v-if="source.preset"
                                as-child
                                type="button"
                                variant="outline"
                                size="sm"
                                class="mt-2"
                            >
                                <Link :href="vehicleEditHref">{{
                                    t('Individual prices for this vehicle')
                                }}</Link>
                            </Button>
                        </div>
                        <div v-if="source" class="overflow-x-auto">
                            <table class="w-full min-w-[570px] text-sm">
                                <thead>
                                    <tr>
                                        <th class="p-2 text-left">
                                            {{ t('Season') }}
                                        </th>
                                        <th
                                            v-for="tier in tiers"
                                            :key="tier"
                                            class="p-2"
                                        >
                                            {{ t(tier) }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="season in seasons" :key="season">
                                        <th class="p-2 text-left">
                                            {{ t(season) }}
                                        </th>
                                        <td
                                            v-for="tier in tiers"
                                            :key="tier"
                                            class="p-2 align-top"
                                        >
                                            <Input
                                                v-model="prices[season][tier]"
                                                inputmode="numeric"
                                                class="min-w-20"
                                                :aria-label="`${vehicleName}: ${t(season)} / ${t(tier)}, THB`"
                                                :disabled="saving"
                                            />
                                            <label
                                                v-if="!source.preset"
                                                class="mt-2 flex items-center gap-2 text-xs"
                                                ><input
                                                    v-model="
                                                        enabled[season][tier]
                                                    "
                                                    type="checkbox"
                                                    :disabled="saving"
                                                />{{ t('Active') }}</label
                                            >
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p
                            v-if="error"
                            role="alert"
                            class="text-sm whitespace-pre-line text-destructive"
                        >
                            {{ error }}
                        </p>
                        <p
                            v-if="success"
                            role="status"
                            class="text-sm text-emerald-600"
                        >
                            {{ success }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="source"
                                type="submit"
                                :disabled="!dirty || saving || loading"
                                >{{
                                    saving ? t('Saving…') : t('Save prices')
                                }}</Button
                            >
                            <Button
                                v-if="!source && !loading"
                                type="button"
                                variant="outline"
                                @click="load"
                                >{{ t('Retry') }}</Button
                            >
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="saving || loading"
                                @click="toggle"
                                >{{ t('Close') }}</Button
                            >
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
        <Dialog v-model:open="confirmSave"
            ><DialogContent
                ><DialogHeader
                    ><DialogTitle>{{ t('Save prices') }}</DialogTitle
                    ><DialogDescription
                        >{{
                            source?.preset
                                ? t(
                                      'Saving will update :count linked vehicles.',
                                      {
                                          count: source.preset
                                              .linked_vehicles_count,
                                      },
                                  )
                                : t('Only this vehicle will be changed.')
                        }}
                        {{
                            t(
                                'Existing booking price snapshots will not change.',
                            )
                        }}</DialogDescription
                    ></DialogHeader
                ><DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="confirmSave = false"
                        >{{ t('Cancel') }}</Button
                    ><Button type="button" @click="save">{{
                        t('Save prices')
                    }}</Button></DialogFooter
                ></DialogContent
            ></Dialog
        >
        <Dialog v-model:open="confirmDiscard"
            ><DialogContent
                ><DialogHeader
                    ><DialogTitle>{{
                        t('Discard unsaved prices?')
                    }}</DialogTitle
                    ><DialogDescription>{{
                        t('Your unsaved price changes will be lost.')
                    }}</DialogDescription></DialogHeader
                ><DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="confirmDiscard = false"
                        >{{ t('Continue editing') }}</Button
                    ><Button
                        type="button"
                        variant="destructive"
                        @click="discard"
                        >{{ t('Discard changes') }}</Button
                    ></DialogFooter
                ></DialogContent
            ></Dialog
        >
    </div>
</template>
