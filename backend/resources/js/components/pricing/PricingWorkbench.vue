<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import PricingCalendar from '@/components/pricing/PricingCalendar.vue';
import AdminSelect from '@/components/AdminSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import {
    linkedPricing,
    pricingRequest,
    pricingSeasons,
    pricingTiers,
    pricingTierLabels,
    pricingSeasonLabels,
} from '@/lib/pricingTools';
import type { PricingFleetVehicle, PricingPreset } from '@/types';

const props = defineProps<{ presets: PricingPreset[] }>();
const emit = defineEmits<{
    saved: [presets: PricingPreset[]];
    edit: [preset: PricingPreset];
    copy: [preset: PricingPreset];
    removed: [id: number];
}>();
const { t, locale } = useLocale();
const activeId = ref<number | null>(props.presets[0]?.id ?? null);
const selected = computed(() =>
    props.presets.find((p) => p.id === activeId.value),
);
const fleet = ref<PricingFleetVehicle[]>([]);
const error = ref('');
const status = ref('');
const busy = ref(false);
const pending = ref<null | (() => Promise<void>)>(null);
const confirmation = ref('');
const confirmOpen = ref(false);
const editing = ref(false);
const edited = ref<Record<number, PricingPreset>>({});
const start = ref('');
const end = ref('');
const days = ref(10);
const season = ref<'high' | 'middle' | 'low'>('high');
const quoteMode = ref<'calendar' | 'days'>('calendar');
const matrix = ref<Record<string, number[]> | null>(null);
type Quote = {
    total: number;
    days: number;
    season: string;
    season_days: Record<string, number>;
    completed_months: number;
    remainder_days: number;
    monthly_rate: number;
    remainder_total: number;
    mode: string;
    manual_total: boolean;
    tied_seasons: string[];
};
const quote = ref<Quote | null>(null);
const selectedVehicles = ref<number[]>([]);
const fleetTarget = ref<number | null>(null);
const rowTargets = ref<Record<number, number | null>>({});
const fleetSearch = ref('');
const filteredFleet = computed(() =>
    fleet.value.filter((v) =>
        `${v.external_code} ${v.name} ${v.inventory_code ?? ''}`
            .toLocaleLowerCase()
            .includes(fleetSearch.value.toLocaleLowerCase()),
    ),
);
type ImportPreview = {
    token: string;
    presets: { name: string }[];
    assignments: Record<string, string>;
    existing_presets: {
        id: number;
        name: string;
        version: number;
        linked_count: number;
    }[];
    vehicles: { id: number; name: string; external_code: string }[];
};
const importPreview = ref<ImportPreview | null>(null);
const mapping = ref<Record<string, number | null>>({});
const presetTargets = ref<Record<string, number | null>>({});
const fileInput = ref<HTMLInputElement | null>(null);
const hovered = ref<{ row: string; column: string } | null>(null);
let calculationGeneration = 0;
const draggedPreset = ref<number | null>(null);
const seasonOptions = computed(() =>
    pricingSeasons.map((s) => ({ value: s, label: t(pricingSeasonLabels[s]) })),
);

function format(value: number | null | undefined): string {
    return value == null
        ? '—'
        : new Intl.NumberFormat(
              locale.value === 'ru' ? 'ru-RU' : 'en-US',
          ).format(value);
}
function shown(preset: PricingPreset): PricingPreset {
    return edited.value[preset.id] ?? preset;
}
function beginEdit(
    preset: PricingPreset,
    s: (typeof pricingSeasons)[number],
    tier: (typeof pricingTiers)[number],
    event: Event,
): void {
    const draft =
        edited.value[preset.id] ??
        (JSON.parse(JSON.stringify(preset)) as PricingPreset);
    const value = Number((event.target as HTMLInputElement).value);
    draft.prices[s][tier] = value;

    if (draft.calculation_settings) {
        if (s === 'high') {
            draft.prices = linkedPricing(
                draft.prices,
                draft.calculation_settings,
            );
        } else {
            draft.calculation_settings.prefs[
                s === 'middle' ? 'linkMiddle' : 'linkLow'
            ] = false;
        }
    }

    edited.value[preset.id] = draft;
}
function ask(message: string, action: () => Promise<void>): void {
    confirmation.value = message;
    pending.value = action;
    confirmOpen.value = true;
}
async function run(action: () => Promise<void>): Promise<void> {
    busy.value = true;
    error.value = '';
    status.value = '';

    try {
        await action();
    } catch (e) {
        error.value =
            e instanceof Error
                ? e.message
                : t('Could not complete pricing operation.');
    } finally {
        busy.value = false;
    }
}
async function confirm(): Promise<void> {
    confirmOpen.value = false;
    const action = pending.value;
    pending.value = null;

    if (action) {
        await run(action);
    }
}
async function loadFleet(): Promise<void> {
    const result = await pricingRequest('/pricing-presets/fleet');
    fleet.value = result.data as PricingFleetVehicle[];
    fleet.value.forEach((v) => {
        rowTargets.value[v.id] = v.pricing_preset_id;
    });
}
async function calculate(): Promise<void> {
    const generation = ++calculationGeneration;
    const preset = selected.value;

    if (!preset?.calculation_settings) {
        matrix.value = null;
        quote.value = null;

        return;
    }

    const query =
        quoteMode.value === 'calendar' && start.value && end.value
            ? new URLSearchParams({
                  starts_on: start.value,
                  ends_on: end.value,
              })
            : new URLSearchParams({
                  days: String(days.value),
                  season: season.value,
              });
    const result = await pricingRequest(
        `/pricing-presets/${preset.id}/calculate?${query}`,
    );

    if (
        generation !== calculationGeneration ||
        selected.value?.id !== preset.id ||
        selected.value?.version !== preset.version
    ) {
        return;
    }

    if (Number(result.version) !== preset.version) {
        throw new Error(
            t('This preset changed. Refresh the page before calculating.'),
        );
    }

    matrix.value = result.matrix as Record<string, number[]>;
    // A preliminary request to fetch the matrix is not a calendar quote.
    quote.value =
        quoteMode.value === 'calendar' && (!start.value || !end.value)
            ? null
            : (result.data as Quote);
}
function reorder(from: number, to: number): void {
    if (
        editing.value ||
        busy.value ||
        from === to ||
        to < 0 ||
        to >= props.presets.length
    ) {
        return;
    }

    const reordered = [...props.presets];
    const [moved] = reordered.splice(from, 1);
    reordered.splice(to, 0, moved);
    ask(t('Save preset order?'), async () => {
        const result = await pricingRequest(
            '/pricing-presets/reorder',
            'POST',
            {
                confirmed: true,
                presets: reordered.map((p) => ({
                    id: p.id,
                    version: p.version,
                })),
            },
        );
        emit('saved', result.data as PricingPreset[]);
    });
}
function removePreset(preset: PricingPreset): void {
    ask(t('Delete unused preset :name?', { name: preset.name }), async () => {
        await pricingRequest(`/pricing-presets/${preset.id}`, 'DELETE', {
            confirmed: true,
            version: preset.version,
        });
        emit('removed', preset.id);
    });
}
function saveTable(): void {
    const drafts = Object.values(edited.value);
    ask(
        t(
            'Save :count edited presets and update their linked vehicles for future quotes?',
            { count: drafts.length },
        ),
        async () => {
            const result = await pricingRequest(
                '/pricing-presets/bulk',
                'PATCH',
                {
                    confirmed: true,
                    presets: drafts.map((p) => ({
                        id: p.id,
                        version: p.version,
                        expected_linked_vehicles_count: p.linked_vehicles_count,
                        prices: p.prices,
                        calculation_settings: p.calculation_settings ?? null,
                    })),
                },
            );
            emit('saved', result.data as PricingPreset[]);
            edited.value = {};
            editing.value = false;
            await loadFleet();
            status.value = t('Pricing presets saved.');
        },
    );
}
function assign(
    vehicles: PricingFleetVehicle[],
    targetId: number | null,
): void {
    const target = props.presets.find((p) => p.id === targetId);

    if (!target || !vehicles.length) {
        return;
    }

    ask(
        t('Assign :preset to :count vehicles for future quotes?', {
            preset: target.name,
            count: vehicles.length,
        }),
        async () => {
            await pricingRequest('/pricing-presets/assignments', 'POST', {
                confirmed: true,
                preset_id: target.id,
                preset_version: target.version,
                vehicles: vehicles.map((v) => ({
                    id: v.id,
                    expected_preset_id: v.pricing_preset_id,
                    expected_revision: v.pricing_revision,
                })),
            });
            await loadFleet();
            selectedVehicles.value = [];
            const response = await pricingRequest('/pricing-presets');
            emit('saved', response.data as PricingPreset[]);
            status.value = t('Fleet assignments saved.');
        },
    );
}
async function readFile(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    await run(async () => {
        if (file.size > 2000000) {
            throw new Error(t('Settings file must not exceed 2 MB.'));
        }

        const result = await pricingRequest(
            '/pricing-presets/import-preview',
            'POST',
            { payload: JSON.parse(await file.text()) },
        );
        importPreview.value = result as unknown as ImportPreview;
        mapping.value = Object.fromEntries(
            Object.keys(importPreview.value.assignments).map((id) => [
                id,
                null,
            ]),
        );
        presetTargets.value = Object.fromEntries(
            importPreview.value.presets.map((p) => [p.name, null]),
        );
    });
    input.value = '';
}
function commitImport(): void {
    const preview = importPreview.value;

    if (!preview) {
        return;
    }

    ask(
        t(
            'Import :count presets with the mappings below? Existing bookings will not change.',
            { count: preview.presets.length },
        ),
        async () => {
            const result = await pricingRequest(
                '/pricing-presets/import-commit',
                'POST',
                {
                    token: preview.token,
                    confirmed: true,
                    preset_targets: presetTargets.value,
                    mapping: mapping.value,
                },
            );
            importPreview.value = null;
            await loadFleet();
            const response = await pricingRequest('/pricing-presets');
            emit('saved', response.data as PricingPreset[]);
            status.value = t(
                'Import complete. Assigned: :assigned. Skipped: :skipped.',
                {
                    assigned: Number(result.assigned),
                    skipped: Number(result.skipped),
                },
            );
        },
    );
}
function csv(): void {
    if (!matrix.value || !selected.value) {
        return;
    }

    const rows = [
        ['Season', 'Days', 'Total THB', 'THB/day'],
        ...pricingSeasons.flatMap((s) =>
            matrix.value![s].map((v, i) => [
                s,
                String(i + 1),
                String(v),
                (v / (i + 1)).toFixed(2),
            ]),
        ),
    ];
    const url = URL.createObjectURL(
        new Blob(['\uFEFF' + rows.map((r) => r.join(';')).join('\r\n')], {
            type: 'text/csv;charset=utf-8',
        }),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = `preset-${selected.value.id}-daily-prices.csv`;
    link.click();
    URL.revokeObjectURL(url);
}
watch(
    () => props.presets,
    () => {
        if (!props.presets.some((p) => p.id === activeId.value)) {
            activeId.value = props.presets[0]?.id ?? null;
        }
    },
    { deep: true },
);
watch(
    selected,
    () => {
        quote.value = null;
        matrix.value = null;
        void run(calculate);
    },
    { deep: true },
);
watch([start, end, days, season, quoteMode], () => {
    quote.value = null;
    calculationGeneration++;
});
onMounted(() => {
    void run(async () => {
        await loadFleet();
        await calculate();
    });
});
</script>

<template>
    <div class="pricing-workbench mt-6 space-y-5">
        <p
            v-if="error"
            role="alert"
            class="rounded-lg border border-destructive p-3 text-sm text-destructive"
        >
            {{ error }}
            {{
                t(
                    'Your draft is retained. Refresh the page or preview after a conflict before trying again.',
                )
            }}
        </p>
        <p v-if="status" role="status" class="rounded-lg border p-3 text-sm">
            {{ status }}
        </p>
        <details class="pricing-panel" open>
            <summary>{{ t('Bike group price presets') }}</summary>
            <div class="flex flex-wrap items-center justify-between gap-3 py-4">
                <label class="text-sm"
                    ><input
                        v-model="editing"
                        type="checkbox"
                        :disabled="busy"
                    />
                    {{ t('Edit prices directly in the table') }}</label
                >
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="editing"
                        type="button"
                        :disabled="busy || !Object.keys(edited).length"
                        @click="saveTable"
                        >{{ t('Save changes') }}</Button
                    ><Button
                        v-if="Object.keys(edited).length"
                        variant="outline"
                        :disabled="busy"
                        @click="
                            edited = {};
                            editing = false;
                        "
                        >{{ t('Discard table edits') }}</Button
                    ><a class="pricing-link" href="/pricing-presets/export">{{
                        t('Export settings to JSON')
                    }}</a
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="busy"
                        @click="fileInput?.click()"
                        >{{ t('Import settings from JSON') }}</Button
                    ><input
                        ref="fileInput"
                        type="file"
                        accept=".json,application/json"
                        hidden
                        @change="readFile"
                    />
                </div>
            </div>
            <p class="mb-3 text-sm text-muted-foreground">
                {{
                    t(
                        'Select a model to see its daily matrix and rental quote. Hover a package price to see its daily rate.',
                    )
                }}
            </p>
            <div class="pricing-scroll">
                <table
                    class="preset-price-table"
                    @pointerleave="hovered = null"
                >
                    <thead>
                        <tr>
                            <th rowspan="2">№</th>
                            <th rowspan="2">{{ t('Bike model') }}</th>
                            <th
                                v-for="s in pricingSeasons"
                                :key="s"
                                colspan="5"
                                :class="`season-${s}`"
                            >
                                {{ t(pricingSeasonLabels[s]) }}
                            </th>
                            <th rowspan="2">{{ t('Actions') }}</th>
                        </tr>
                        <tr>
                            <template v-for="s in pricingSeasons" :key="s"
                                ><th
                                    v-for="tier in pricingTiers"
                                    :key="tier"
                                    :class="`season-${s}`"
                                >
                                    {{ t(pricingTierLabels[tier]) }}
                                </th></template
                            >
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(preset, index) in presets"
                            :key="preset.id"
                            :draggable="!editing && !busy"
                            @dragstart="draggedPreset = index"
                            @dragover.prevent
                            @drop.prevent="
                                draggedPreset !== null &&
                                    reorder(draggedPreset, index);
                                draggedPreset = null;
                            "
                            :class="{
                                'selected-price-row': preset.id === activeId,
                            }"
                        >
                            <td>{{ index + 1 }}</td>
                            <th>
                                <button
                                    type="button"
                                    @click="activeId = preset.id"
                                >
                                    {{ preset.name }}</button
                                ><small>{{
                                    preset.calculation_settings
                                        ? t('Calculator rules')
                                        : t('Legacy calculation')
                                }}</small>
                            </th>
                            <template v-for="s in pricingSeasons" :key="s"
                                ><td
                                    v-for="(tier, tierIndex) in pricingTiers"
                                    :key="tier"
                                    :class="[
                                        `season-${s}`,
                                        {
                                            'price-hover':
                                                hovered?.row ===
                                                    String(preset.id) &&
                                                hovered?.column ===
                                                    `${s}:${tier}`,
                                        },
                                    ]"
                                    :title="`${format(Math.round(shown(preset).prices[s][tier] / [1, 7, 14, 21, 30][tierIndex]))} THB/${t('day')}`"
                                    @pointerover="
                                        hovered = {
                                            row: String(preset.id),
                                            column: `${s}:${tier}`,
                                        }
                                    "
                                >
                                    <Input
                                        v-if="editing"
                                        type="number"
                                        min="1"
                                        max="100000000"
                                        :model-value="
                                            shown(preset).prices[s][tier]
                                        "
                                        :disabled="busy"
                                        :aria-label="`${preset.name} ${t(pricingSeasonLabels[s])} ${t(pricingTierLabels[tier])}`"
                                        @input="
                                            beginEdit(preset, s, tier, $event)
                                        "
                                    /><span v-else>{{
                                        format(preset.prices[s][tier])
                                    }}</span>
                                </td></template
                            >
                            <td>
                                <div class="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        :disabled="
                                            busy || editing || index === 0
                                        "
                                        :aria-label="`${t('Move up')} ${preset.name}`"
                                        @click="reorder(index, index - 1)"
                                        >↑</Button
                                    ><Button
                                        variant="outline"
                                        size="sm"
                                        :disabled="
                                            busy ||
                                            editing ||
                                            index === presets.length - 1
                                        "
                                        :aria-label="`${t('Move down')} ${preset.name}`"
                                        @click="reorder(index, index + 1)"
                                        >↓</Button
                                    >
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="emit('edit', preset)"
                                        >{{ t('Edit') }}</Button
                                    ><Button
                                        variant="outline"
                                        size="sm"
                                        @click="emit('copy', preset)"
                                        >{{ t('Copy') }}</Button
                                    >
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        :disabled="
                                            busy ||
                                            editing ||
                                            preset.linked_vehicles_count > 0
                                        "
                                        @click="removePreset(preset)"
                                        >{{ t('Delete') }}</Button
                                    >
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>

        <section v-if="importPreview" class="pricing-panel space-y-4">
            <h2 class="font-semibold">
                {{ t('Import preview and explicit mapping') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{
                    t(
                        'No changes have been saved. Source IDs are labels from the file. Choose real vehicles explicitly or leave them skipped. No vehicles are created.',
                    )
                }}
            </p>
            <div class="grid gap-3 lg:grid-cols-2">
                <label
                    v-for="preset in importPreview.presets"
                    :key="preset.name"
                    class="text-sm"
                    >{{ preset.name
                    }}<AdminSelect
                        v-model="presetTargets[preset.name]"
                        :options="[
                            { value: null, label: t('Create new preset') },
                            ...importPreview.existing_presets.map((p) => ({
                                value: p.id,
                                label: `#${p.id} ${p.name} · v${p.version} · ${p.linked_count} ${t('linked vehicles')}`,
                            })),
                        ]"
                /></label>
            </div>
            <div class="grid gap-3 lg:grid-cols-2">
                <label
                    v-for="(group, id) in importPreview.assignments"
                    :key="id"
                    class="text-sm"
                    >{{ id }} · {{ group
                    }}<AdminSelect
                        v-model="mapping[id]"
                        :options="[
                            { value: null, label: t('Skip this vehicle') },
                            ...importPreview.vehicles.map((v) => ({
                                value: v.id,
                                label: `#${v.id} ${v.external_code} · ${v.name}`,
                            })),
                        ]"
                /></label>
            </div>
            <p class="text-sm text-muted-foreground">
                {{
                    t(
                        'Preview expires in 20 minutes. Existing preset targets keep their vehicle-type restriction and update all linked vehicles. Review every target before confirming.',
                    )
                }}
            </p>
            <div class="flex gap-2">
                <Button :disabled="busy" @click="commitImport">{{
                    t('Confirm import')
                }}</Button
                ><Button
                    variant="outline"
                    :disabled="busy"
                    @click="importPreview = null"
                    >{{ t('Cancel') }}</Button
                >
            </div>
        </section>

        <details class="pricing-panel" open>
            <summary>
                {{ t('Daily prices and rental period calculator') }}
            </summary>
            <div class="my-4 max-w-md">
                <AdminSelect
                    v-model="activeId"
                    :options="
                        presets.map((p) => ({ value: p.id, label: p.name }))
                    "
                    :aria-label="t('Price preset')"
                />
                <div v-if="selected" class="mt-3 flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="emit('edit', selected)"
                        >{{ t('Edit pricing preset') }}</Button
                    ><Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="emit('copy', selected)"
                        >{{ t('Copy') }}</Button
                    >
                </div>
            </div>
            <p
                v-if="selected && !selected.calculation_settings"
                class="rounded border p-3 text-sm"
            >
                {{
                    t(
                        'This preset uses legacy calculation. Enable calculator rules in Edit to use the matrix and preview. Existing vehicles are not switched automatically.',
                    )
                }}
            </p>
            <template v-if="selected?.calculation_settings">
                <p class="mb-3 text-sm text-muted-foreground">
                    {{
                        t(
                            'Monthly package applies from day :day, except exact base-package anchors. Calendar months are calculated separately.',
                            {
                                day: selected.calculation_settings.rules.monthly
                                    ? selected.calculation_settings.rules
                                          .monthlyStart
                                    : 30,
                            },
                        )
                    }}
                </p>
                <h3 class="mb-3 font-semibold">
                    {{ t('Daily prices — all seasons') }} · {{ selected.name }}
                </h3>
                <div v-if="matrix" class="pricing-scroll">
                    <table class="daily-price-table">
                        <thead>
                            <tr>
                                <th>{{ t('Season / metric') }}</th>
                                <th
                                    v-for="day in 31"
                                    :key="day"
                                    :class="{
                                        milestone: [
                                            1, 7, 14, 21, 30, 31,
                                        ].includes(day),
                                    }"
                                >
                                    {{ day }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="s in pricingSeasons" :key="s"
                                ><tr :class="`season-${s}`">
                                    <th>{{ t(pricingSeasonLabels[s]) }}</th>
                                    <td
                                        v-for="(amount, index) in matrix[s]"
                                        :key="index"
                                        :title="`${format(amount / (index + 1))} THB/${t('day')}`"
                                    >
                                        {{ format(amount) }}
                                    </td>
                                </tr>
                                <tr :class="`season-${s}`" class="average-row">
                                    <th>{{ t('Per day') }}</th>
                                    <td
                                        v-for="(amount, index) in matrix[s]"
                                        :key="index"
                                    >
                                        {{
                                            format(
                                                Math.round(
                                                    amount / (index + 1),
                                                ),
                                            )
                                        }}
                                    </td>
                                </tr></template
                            >
                        </tbody>
                    </table>
                </div>
                <Button
                    class="mt-3"
                    variant="outline"
                    :disabled="!matrix"
                    @click="csv"
                    >{{ t('Download CSV') }}</Button
                >
                <div
                    class="mt-6 grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <label class="text-sm"
                        >{{ t('Calculation mode')
                        }}<AdminSelect
                            v-model="quoteMode"
                            :options="[
                                {
                                    value: 'calendar',
                                    label: t('Calendar dates'),
                                },
                                {
                                    value: 'days',
                                    label: t('Preliminary: days only'),
                                },
                            ]"
                            @update:model-value="quote = null"
                    /></label>
                    <div v-if="quoteMode === 'calendar'" class="sm:col-span-2">
                        <p class="mb-1 text-sm">{{ t('Rental period') }}</p>
                        <PricingCalendar
                            v-model:start="start"
                            v-model:end="end"
                            :season-calendar="
                                selected.calculation_settings.prefs
                                    .seasonCalendar
                            "
                        />
                    </div>
                    <template v-else
                        ><label class="text-sm"
                            >{{ t('Number of days')
                            }}<Input
                                v-model.number="days"
                                type="number"
                                min="1"
                                max="365" /></label
                        ><label class="text-sm"
                            >{{ t('Season')
                            }}<AdminSelect
                                v-model="season"
                                :options="seasonOptions" /></label
                    ></template>
                    <Button
                        :disabled="
                            busy ||
                            (quoteMode === 'calendar' && (!start || !end))
                        "
                        @click="run(calculate)"
                        >{{ t('Calculate') }}</Button
                    >
                </div>
                <p class="mt-3 text-sm text-muted-foreground">
                    {{
                        quoteMode === 'calendar'
                            ? t(
                                  'Full calendar months plus remaining days. The return day is excluded.',
                              )
                            : t(
                                  'Preliminary only: uses 31-day blocks. Enter calendar dates for an actual booking; the total can differ.',
                              )
                    }}
                </p>
                <div
                    v-if="quote"
                    class="mt-4 rounded-lg border p-4"
                    role="status"
                >
                    <p class="text-2xl font-semibold">
                        {{ format(quote.total) }} THB
                        <small class="text-sm font-normal"
                            >· {{ quote.days }} {{ t('days') }}</small
                        >
                    </p>
                    <p class="mt-2 text-sm">
                        {{ t('Average') }}
                        {{ format(Math.round(quote.total / quote.days)) }}
                        THB/{{ t('day') }} ·
                        {{
                            t(
                                pricingSeasonLabels[
                                    quote.season as keyof typeof pricingSeasonLabels
                                ],
                            )
                        }}
                    </p>
                    <p class="mt-2 text-sm">
                        {{ quote.completed_months }}
                        {{ t('calendar months / blocks') }} ×
                        {{ format(quote.monthly_rate) }} THB +
                        {{ quote.remainder_days }} {{ t('days') }}:
                        {{ format(quote.remainder_total) }} THB
                        <span v-if="quote.manual_total"
                            >· {{ t('Manual total override') }}</span
                        >
                    </p>
                    <p v-if="quote.mode === 'calendar'" class="mt-2 text-sm">
                        {{
                            pricingSeasons
                                .map(
                                    (s) =>
                                        `${t(pricingSeasonLabels[s])}: ${quote!.season_days[s]}`,
                                )
                                .join(' · ')
                        }}<span v-if="quote.tied_seasons.length > 1">
                            ·
                            {{
                                t('Tie: cheaper monthly package selected')
                            }}</span
                        >
                    </p>
                </div>
            </template>
        </details>

        <details class="pricing-panel" open>
            <summary>{{ t('Current fleet and preset assignments') }}</summary>
            <p class="my-3 text-sm text-muted-foreground">
                {{
                    t(
                        'Real fleet records only. Legacy vehicles keep their existing calculation until an explicit calculator preset assignment. Existing bookings are not recalculated.',
                    )
                }}
            </p>
            <div class="mb-4 grid gap-3 sm:grid-cols-3">
                <Input
                    v-model="fleetSearch"
                    :placeholder="t('Search fleet')"
                    :aria-label="t('Search fleet')"
                /><AdminSelect
                    v-model="fleetTarget"
                    :options="[
                        {
                            value: null,
                            label: t('Choose preset for selected vehicles'),
                        },
                        ...presets.map((p) => ({ value: p.id, label: p.name })),
                    ]"
                /><Button
                    :disabled="busy || !fleetTarget || !selectedVehicles.length"
                    @click="
                        assign(
                            fleet.filter((v) =>
                                selectedVehicles.includes(v.id),
                            ),
                            fleetTarget,
                        )
                    "
                    >{{ t('Assign to selected vehicles') }} ({{
                        selectedVehicles.length
                    }})</Button
                >
            </div>
            <div class="pricing-scroll">
                <table class="fleet-assignment-table">
                    <thead>
                        <tr>
                            <th>{{ t('Select') }}</th>
                            <th>{{ t('Code') }}</th>
                            <th>{{ t('Model / description') }}</th>
                            <th>{{ t('Category') }}</th>
                            <th>{{ t('Visibility') }}</th>
                            <th>{{ t('Calculation mode') }}</th>
                            <th>{{ t('Price preset') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="vehicle in filteredFleet" :key="vehicle.id">
                            <td>
                                <input
                                    v-model="selectedVehicles"
                                    :value="vehicle.id"
                                    type="checkbox"
                                    :aria-label="`${t('Select')} ${vehicle.name}`"
                                />
                            </td>
                            <td>
                                #{{ vehicle.id }} · {{ vehicle.external_code }}
                            </td>
                            <th>
                                {{ vehicle.name
                                }}<small
                                    >{{ vehicle.inventory_code }}
                                    {{ vehicle.year }}</small
                                >
                            </th>
                            <td>{{ vehicle.category }}</td>
                            <td>
                                {{
                                    t(
                                        vehicle.is_visible_for_booking
                                            ? 'Published'
                                            : 'Hidden',
                                    )
                                }}
                            </td>
                            <td>
                                {{
                                    t(
                                        vehicle.algorithm === 'legacy'
                                            ? 'Legacy calculation'
                                            : 'Calculator rules',
                                    )
                                }}
                            </td>
                            <td>
                                <div class="flex min-w-80 items-center gap-2">
                                    <AdminSelect
                                        v-model="rowTargets[vehicle.id]"
                                        :options="[
                                            {
                                                value: null,
                                                label: t('Select a preset'),
                                            },
                                            ...presets
                                                .filter(
                                                    (p) =>
                                                        !p.vehicle_type ||
                                                        p.vehicle_type ===
                                                            vehicle.type,
                                                )
                                                .map((p) => ({
                                                    value: p.id,
                                                    label: p.name,
                                                })),
                                        ]"
                                        :aria-label="`${vehicle.name} ${t('Price preset')}`"
                                    /><Button
                                        size="sm"
                                        variant="outline"
                                        :disabled="
                                            busy || !rowTargets[vehicle.id]
                                        "
                                        @click="
                                            assign(
                                                [vehicle],
                                                rowTargets[vehicle.id],
                                            )
                                        "
                                        >{{ t('Apply') }}</Button
                                    >
                                </div>
                                <small>{{
                                    vehicle.preset_name ??
                                    t('No preset assigned')
                                }}</small>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>

        <details class="pricing-panel">
            <summary>{{ t('Fleet price sheet') }}</summary>
            <p class="my-3 text-sm text-muted-foreground">
                {{
                    t(
                        'Package totals from the real fleet. Hover a price for its daily rate. Inactive or missing prices are shown as a dash.',
                    )
                }}
            </p>
            <div class="pricing-scroll">
                <table class="preset-price-table">
                    <thead>
                        <tr>
                            <th rowspan="2">№</th>
                            <th rowspan="2">{{ t('Bike model') }}</th>
                            <th
                                v-for="s in pricingSeasons"
                                :key="s"
                                colspan="5"
                                :class="`season-${s}`"
                            >
                                {{ t(pricingSeasonLabels[s]) }}
                            </th>
                        </tr>
                        <tr>
                            <template v-for="s in pricingSeasons" :key="s"
                                ><th
                                    v-for="tier in pricingTiers"
                                    :key="tier"
                                    :class="`season-${s}`"
                                >
                                    {{ t(pricingTierLabels[tier]) }}
                                </th></template
                            >
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(vehicle, index) in filteredFleet"
                            :key="vehicle.id"
                        >
                            <td>{{ index + 1 }}</td>
                            <th>
                                {{ vehicle.name
                                }}<small
                                    >{{ vehicle.external_code }} ·
                                    {{
                                        vehicle.preset_name ??
                                        t('No preset assigned')
                                    }}</small
                                >
                            </th>
                            <template v-for="s in pricingSeasons" :key="s"
                                ><td
                                    v-for="(tier, i) in pricingTiers"
                                    :key="tier"
                                    :class="`season-${s}`"
                                    :title="`${format(vehicle.prices[s][tier].package_total == null ? null : Math.round(vehicle.prices[s][tier].package_total! / [1, 7, 14, 21, 30][i]))} THB/${t('day')}`"
                                >
                                    {{
                                        vehicle.prices[s][tier].is_active
                                            ? format(
                                                  vehicle.prices[s][tier]
                                                      .package_total,
                                              )
                                            : '—'
                                    }}
                                </td></template
                            >
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
        <Dialog v-model:open="confirmOpen"
            ><DialogContent
                class="duration-[350ms] ease-out motion-reduce:animate-none"
                ><DialogHeader
                    ><DialogTitle>{{
                        t('Confirm pricing changes')
                    }}</DialogTitle
                    ><DialogDescription>{{
                        confirmation
                    }}</DialogDescription></DialogHeader
                >
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Existing bookings are not recalculated. Future quotes use the saved calculation rules.',
                        )
                    }}
                </p>
                <DialogFooter
                    ><Button variant="outline" @click="confirmOpen = false">{{
                        t('Cancel')
                    }}</Button
                    ><Button :disabled="busy" @click="confirm">{{
                        t('Confirm')
                    }}</Button></DialogFooter
                ></DialogContent
            ></Dialog
        >
    </div>
</template>

<style scoped>
.pricing-panel {
    min-width: 0;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--card);
    padding: 16px;
}
.pricing-panel > summary {
    cursor: pointer;
    font-size: 16px;
    font-weight: 650;
    padding: 4px;
}
.pricing-scroll {
    max-width: 100%;
    max-height: 580px;
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: 6px;
}
.pricing-scroll table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    white-space: nowrap;
    font-size: 13px;
}
.pricing-scroll td,
.pricing-scroll th {
    padding: 9px;
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    text-align: center;
    font-variant-numeric: tabular-nums;
}
.pricing-scroll thead {
    position: sticky;
    top: 0;
    z-index: 5;
    background: var(--card);
}
.preset-price-table {
    min-width: 1620px;
}
.preset-price-table thead tr:first-child th:nth-child(2),
.preset-price-table tbody th {
    min-width: 240px;
    text-align: left;
    position: sticky;
    left: 54px;
    z-index: 3;
    background: var(--card);
}
.preset-price-table tbody td:first-child,
.preset-price-table thead tr:first-child th:first-child {
    position: sticky;
    left: 0;
    z-index: 4;
    min-width: 54px;
    max-width: 54px;
    width: 54px;
    background: var(--card);
}
.pricing-scroll tbody th {
    background: var(--card);
    text-align: left;
}
.pricing-scroll small {
    display: block;
    font-weight: 400;
    font-size: 11px;
    color: var(--muted-foreground);
    margin-top: 4px;
}
.season-high {
    background: color-mix(in srgb, #c44b53 18%, var(--card));
}
.season-middle {
    background: color-mix(in srgb, #c29e41 18%, var(--card));
}
.season-low {
    background: color-mix(in srgb, #4ea063 18%, var(--card));
}
.daily-price-table {
    min-width: 2120px;
}
.daily-price-table th:first-child {
    position: sticky;
    left: 0;
    z-index: 3;
    min-width: 155px;
}
.daily-price-table .average-row {
    font-size: 11px;
    color: var(--muted-foreground);
}
.milestone {
    font-weight: 700;
    box-shadow: inset 1px 0 var(--muted-foreground);
}
.selected-price-row {
    outline: 2px solid #c29e41;
    outline-offset: -2px;
}
.pricing-scroll tbody tr:hover > td {
    filter: brightness(0.95);
}
.pricing-scroll .price-hover {
    outline: 2px solid #c29e41;
    outline-offset: -2px;
}
.pricing-link {
    display: inline-flex;
    align-items: center;
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 8px 12px;
    font-size: 14px;
    font-weight: 500;
}
.pricing-workbench :deep(button),
.pricing-workbench :deep(input),
.pricing-workbench td,
.pricing-link {
    transition:
        background-color 350ms ease,
        border-color 350ms ease,
        color 350ms ease,
        box-shadow 350ms ease,
        filter 350ms ease;
}
.fleet-assignment-table {
    min-width: 1200px;
}
@supports (interpolate-size: allow-keywords) {
    .pricing-panel {
        interpolate-size: allow-keywords;
    }
    .pricing-panel::details-content {
        block-size: 0;
        opacity: 0;
        overflow: clip;
        transition:
            block-size 350ms ease,
            opacity 350ms ease,
            content-visibility 350ms allow-discrete;
    }
    .pricing-panel[open]::details-content {
        block-size: auto;
        opacity: 1;
        overflow: visible;
    }
}
@media (prefers-reduced-motion: reduce) {
    .pricing-panel::details-content {
        transition: none;
    }
    .pricing-workbench :deep(*),
    .pricing-workbench td,
    .pricing-link {
        transition: none !important;
        animation: none !important;
    }
}
</style>
