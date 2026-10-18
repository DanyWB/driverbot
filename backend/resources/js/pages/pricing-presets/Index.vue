<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Copy,
    Link2,
    Pencil,
    Plus,
    RefreshCw,
    Save,
    TriangleAlert,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
import AdminSelect from '@/components/AdminSelect.vue';
import InputError from '@/components/InputError.vue';
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
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import type {
    PricingPreset,
    PricingPresetPrices,
    PricingPresetVehicleType,
} from '@/types';

const props = defineProps<{ presets: PricingPreset[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Pricing presets', href: '/pricing-presets' }],
    },
});

const { locale, t } = useLocale();
const seasons = [
    { key: 'high', label: 'High season' },
    { key: 'middle', label: 'Middle season' },
    { key: 'low', label: 'Low season' },
] as const;
const tiers = [
    { key: '1d', label: '1 day' },
    { key: '7d', label: '7 days' },
    { key: '14d', label: '14 days' },
    { key: '21d', label: '21 days' },
    { key: 'month', label: '30+ days' },
] as const;

type DialogMode = 'create' | 'edit' | 'copy';
type DraftPrices = Record<
    (typeof seasons)[number]['key'],
    Record<(typeof tiers)[number]['key'], number | string>
>;
type PresetDraft = {
    name: string;
    vehicle_type: PricingPresetVehicleType;
    prices: DraftPrices;
};

const items = ref<PricingPreset[]>(props.presets.map(clonePreset));
const editorOpen = ref(false);
const confirmOpen = ref(false);
const mode = ref<DialogMode>('create');
const source = ref<PricingPreset | null>(null);
const draft = ref<PresetDraft>(blankDraft());
const fieldErrors = ref<Record<string, string>>({});
const requestError = ref('');
const successMessage = ref('');
const processing = ref(false);
const conflict = ref<{
    code: string;
    currentVersion?: number;
    currentLinkedCount?: number;
} | null>(null);
const latestPreset = ref<PricingPreset | null>(null);
const editorForm = ref<HTMLFormElement | null>(null);

const dialogTitle = computed(() => {
    if (mode.value === 'edit') {
        return t('Edit pricing preset');
    }

    if (mode.value === 'copy') {
        return t('Copy pricing preset');
    }

    return t('Create pricing preset');
});
const dialogDescription = computed(() =>
    mode.value === 'copy'
        ? t('Create a separate preset with the same exact prices.')
        : t('Enter exact package totals for all seasons and rental periods.'),
);

function blankPrices(): DraftPrices {
    return {
        high: { '1d': '', '7d': '', '14d': '', '21d': '', month: '' },
        middle: { '1d': '', '7d': '', '14d': '', '21d': '', month: '' },
        low: { '1d': '', '7d': '', '14d': '', '21d': '', month: '' },
    };
}

function blankDraft(): PresetDraft {
    return { name: '', vehicle_type: null, prices: blankPrices() };
}

function clonePrices(prices: PricingPresetPrices): DraftPrices {
    return Object.fromEntries(
        seasons.map((season) => [
            season.key,
            Object.fromEntries(
                tiers.map((tier) => [tier.key, prices[season.key][tier.key]]),
            ),
        ]),
    ) as DraftPrices;
}

function clonePreset(preset: PricingPreset): PricingPreset {
    return {
        ...preset,
        linked_vehicle_ids: [...preset.linked_vehicle_ids],
        prices: clonePrices(preset.prices) as PricingPresetPrices,
    };
}

function pricePath(season: string, tier: string): string {
    return `prices.${season}.${tier}`;
}

function openCreate(): void {
    mode.value = 'create';
    source.value = null;
    draft.value = blankDraft();
    resetFeedback();
    editorOpen.value = true;
}

function openEdit(preset: PricingPreset): void {
    mode.value = 'edit';
    source.value = preset;
    draft.value = {
        name: preset.name,
        vehicle_type: preset.vehicle_type,
        prices: clonePrices(preset.prices),
    };
    resetFeedback();
    editorOpen.value = true;
}

function openCopy(preset: PricingPreset): void {
    mode.value = 'copy';
    source.value = preset;
    draft.value = {
        name: t(':name copy', { name: preset.name }),
        vehicle_type: preset.vehicle_type,
        prices: clonePrices(preset.prices),
    };
    resetFeedback();
    editorOpen.value = true;
}

function resetFeedback(): void {
    fieldErrors.value = {};
    requestError.value = '';
    conflict.value = null;
    latestPreset.value = null;
    confirmOpen.value = false;
}

function validateDraft(): boolean {
    const errors: Record<string, string> = {};

    if (!draft.value.name.trim()) {
        errors.name = t('Enter a preset name.');
    }

    if (mode.value !== 'copy') {
        for (const season of seasons) {
            for (const tier of tiers) {
                const path = pricePath(season.key, tier.key);
                const value = draft.value.prices[season.key][tier.key];

                if (
                    value === '' ||
                    !Number.isInteger(Number(value)) ||
                    Number(value) <= 0
                ) {
                    errors[path] = t('Enter a positive whole THB amount.');
                } else if (Number(value) > 100_000_000) {
                    errors[path] = t('Price must not exceed 100,000,000 THB.');
                }
            }
        }
    }

    fieldErrors.value = errors;

    if (Object.keys(errors).length > 0) {
        void nextTick(() => {
            const invalid = editorForm.value?.querySelector<HTMLElement>(
                '[aria-invalid="true"]',
            );
            invalid?.focus();
            invalid?.scrollIntoView({
                block: 'center',
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                    .matches
                    ? 'auto'
                    : 'smooth',
            });
        });

        return false;
    }

    return true;
}

function submit(): void {
    if (!validateDraft()) {
        return;
    }

    if (
        mode.value === 'edit' &&
        source.value &&
        source.value.linked_vehicles_count > 0
    ) {
        confirmOpen.value = true;

        return;
    }

    void persist();
}

function csrfHeaders(): Record<string, string> {
    const metaToken = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content.trim();

    if (metaToken) {
        return { 'X-CSRF-TOKEN': metaToken };
    }

    const cookieToken = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return cookieToken
        ? { 'X-XSRF-TOKEN': decodeURIComponent(cookieToken) }
        : {};
}

function normalizedPrices(): PricingPresetPrices {
    return Object.fromEntries(
        seasons.map((season) => [
            season.key,
            Object.fromEntries(
                tiers.map((tier) => [
                    tier.key,
                    Number(draft.value.prices[season.key][tier.key]),
                ]),
            ),
        ]),
    ) as PricingPresetPrices;
}

async function persist(): Promise<void> {
    const current = source.value;

    if (mode.value !== 'create' && !current) {
        return;
    }

    processing.value = true;
    confirmOpen.value = false;
    requestError.value = '';
    successMessage.value = '';
    fieldErrors.value = {};
    conflict.value = null;

    let url = '/pricing-presets';
    let method = 'POST';
    let payload: Record<string, unknown> = {
        name: draft.value.name.trim(),
        vehicle_type: draft.value.vehicle_type,
        prices: normalizedPrices(),
    };

    if (mode.value === 'copy' && current) {
        url = `/pricing-presets/${current.id}/copy`;
        payload = {
            name: draft.value.name.trim(),
            source_version: current.version,
        };
    } else if (mode.value === 'edit' && current) {
        url = `/pricing-presets/${current.id}`;
        method = 'PATCH';
        payload = {
            ...payload,
            version: current.version,
            expected_linked_vehicles_count: current.linked_vehicles_count,
        };
    }

    try {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...csrfHeaders(),
            },
            body: JSON.stringify(payload),
        });
        const body = await response.json().catch(() => ({}));

        if (response.status === 409) {
            conflict.value = {
                code: body.error ?? 'pricing_preset_version_conflict',
                currentVersion: body.current_version,
                currentLinkedCount: body.current_linked_vehicles_count,
            };
            const serverMessage =
                typeof body.message === 'string' ? t(body.message) : '';
            requestError.value = `${serverMessage}${serverMessage ? ' ' : ''}${t(
                'This preset changed after you opened it. Your edits are still here. Refresh the preset state, review, and try again.',
            )}`;

            return;
        }

        if (!response.ok) {
            fieldErrors.value = Object.fromEntries(
                Object.entries(body.errors ?? {}).map(([key, value]) => [
                    key,
                    t(Array.isArray(value) ? String(value[0]) : String(value)),
                ]),
            );
            requestError.value = t(
                body.message ?? 'The pricing preset could not be saved.',
            );

            return;
        }

        const saved = body.data as PricingPreset;
        const index = items.value.findIndex((item) => item.id === saved.id);

        if (index === -1) {
            items.value.unshift(saved);
        } else {
            items.value[index] = saved;
        }

        editorOpen.value = false;
        confirmOpen.value = false;
        successMessage.value = t('Pricing preset saved.');
    } catch {
        requestError.value = t(
            'The pricing preset service is unavailable. Try again.',
        );
    } finally {
        processing.value = false;
    }
}

async function refreshPresetState(): Promise<void> {
    if (!source.value) {
        return;
    }

    processing.value = true;

    try {
        const response = await fetch('/pricing-presets', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const body = await response.json();

        if (!response.ok || !Array.isArray(body.data)) {
            throw new Error('invalid response');
        }

        items.value = (body.data as PricingPreset[]).map(clonePreset);
        const refreshed = items.value.find(
            (preset) => preset.id === source.value?.id,
        );

        if (refreshed) {
            latestPreset.value = clonePreset(refreshed);
            requestError.value = t(
                'Latest preset state is ready. Your edited fields are still here. Load the latest version only if you want to discard this draft.',
            );
        }
    } catch {
        requestError.value = t('Could not refresh the preset state.');
    } finally {
        processing.value = false;
    }
}

function loadLatestPreset(): void {
    if (!latestPreset.value) {
        return;
    }

    source.value = clonePreset(latestPreset.value);
    draft.value = {
        name:
            mode.value === 'copy'
                ? t(':name copy', { name: latestPreset.value.name })
                : latestPreset.value.name,
        vehicle_type: latestPreset.value.vehicle_type,
        prices: clonePrices(latestPreset.value.prices),
    };
    conflict.value = null;
    latestPreset.value = null;
    requestError.value = '';
    fieldErrors.value = {};
}

function formatPrice(value: number): string {
    return `${new Intl.NumberFormat(locale.value === 'ru' ? 'ru-RU' : 'en-US').format(value)} THB`;
}

onMounted(() => {
    const requestedId = Number(
        new URLSearchParams(window.location.search).get('preset'),
    );
    const requested = items.value.find((preset) => preset.id === requestedId);

    if (requested) {
        openEdit(requested);
    }
});
</script>

<template>
    <Head :title="t('Pricing presets')" />
    <main class="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8">
        <header
            class="flex flex-col gap-4 border-b pb-6 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="max-w-3xl">
                <p class="text-sm font-medium text-muted-foreground">
                    {{ t('Catalog and pricing') }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold">
                    {{ t('Pricing presets') }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        t(
                            'Reuse one exact 3 × 5 price matrix across multiple vehicles. Preset totals are stored as whole THB without rounding or a 50 THB step.',
                        )
                    }}
                </p>
            </div>
            <Button type="button" @click="openCreate">
                <Plus />{{ t('Create preset') }}
            </Button>
        </header>

        <div
            class="mt-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
        >
            <div class="flex gap-3">
                <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                <div class="space-y-1">
                    <p class="font-semibold">
                        {{ t('Booking rounding is unchanged') }}
                    </p>
                    <p>
                        {{
                            t(
                                'Preset package totals stay exact. The final booking quote still follows the existing 100 THB rounding rule.',
                            )
                        }}
                    </p>
                    <p>
                        {{
                            t(
                                'Changing a preset updates linked vehicles for future quotes. Existing bookings are never recalculated.',
                            )
                        }}
                    </p>
                </div>
            </div>
        </div>

        <div
            v-if="successMessage"
            class="mt-5 rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
            role="status"
        >
            {{ successMessage }}
        </div>

        <section
            v-if="items.length"
            class="mt-6 grid min-w-0 gap-4 min-[1900px]:grid-cols-2"
        >
            <article
                v-for="preset in items"
                :key="preset.id"
                class="max-w-full min-w-0 rounded-xl border bg-card p-4 shadow-sm transition-[border-color,box-shadow] duration-[350ms] ease-out hover:border-foreground/20 hover:shadow-md motion-reduce:transition-none sm:p-5"
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0">
                        <h2 class="truncate text-lg font-semibold">
                            {{ preset.name }}
                        </h2>
                        <div
                            class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                        >
                            <span>{{
                                t(preset.vehicle_type ?? 'All vehicle types')
                            }}</span>
                            <span>{{
                                t('Version :version', {
                                    version: preset.version,
                                })
                            }}</span>
                            <span class="inline-flex items-center gap-1">
                                <Link2 class="size-3" />
                                {{
                                    t(':count linked vehicles', {
                                        count: preset.linked_vehicles_count,
                                    })
                                }}
                            </span>
                        </div>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="openCopy(preset)"
                        >
                            <Copy />{{ t('Copy') }}
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            @click="openEdit(preset)"
                        >
                            <Pencil />{{ t('Edit') }}
                        </Button>
                    </div>
                </div>

                <div
                    class="mt-4 max-w-full min-w-0 overflow-x-auto rounded-lg border"
                >
                    <table class="w-full min-w-[620px] text-sm">
                        <thead
                            class="border-b bg-muted/35 text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-3 py-2 text-left font-medium">
                                    {{ t('Season') }}
                                </th>
                                <th
                                    v-for="tier in tiers"
                                    :key="tier.key"
                                    class="px-3 py-2 text-right font-medium"
                                >
                                    {{ t(tier.label) }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="season in seasons" :key="season.key">
                                <th class="px-3 py-2 text-left font-medium">
                                    {{ t(season.label) }}
                                </th>
                                <td
                                    v-for="tier in tiers"
                                    :key="tier.key"
                                    class="px-3 py-2 text-right whitespace-nowrap tabular-nums"
                                >
                                    {{
                                        formatPrice(
                                            preset.prices[season.key][tier.key],
                                        )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="preset.linked_vehicle_ids.length"
                    class="mt-4 flex flex-wrap items-center gap-2 text-xs"
                >
                    <span class="text-muted-foreground"
                        >{{ t('Linked vehicles') }}:</span
                    >
                    <Link
                        v-for="vehicleId in preset.linked_vehicle_ids"
                        :key="vehicleId"
                        :href="`/vehicles/${vehicleId}/edit#pricing`"
                        class="inline-flex items-center gap-1 rounded-md border px-2 py-1 font-medium transition-colors duration-[350ms] hover:bg-muted motion-reduce:transition-none"
                    >
                        #{{ vehicleId }}<ArrowRight class="size-3" />
                    </Link>
                </div>
            </article>
        </section>

        <section
            v-else
            class="mt-8 rounded-xl border border-dashed p-10 text-center"
        >
            <h2 class="font-semibold">{{ t('No pricing presets yet') }}</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    t(
                        'Create a preset manually, then assign it from a vehicle price section.',
                    )
                }}
            </p>
            <Button class="mt-5" type="button" @click="openCreate">
                <Plus />{{ t('Create preset') }}
            </Button>
        </section>
    </main>

    <Dialog v-model:open="editorOpen">
        <DialogContent
            class="max-h-[94vh] min-w-0 grid-cols-[minmax(0,1fr)] overflow-x-hidden overflow-y-auto duration-[350ms] ease-out motion-reduce:animate-none sm:max-w-6xl"
        >
            <DialogHeader class="max-w-full min-w-0">
                <DialogTitle>{{ dialogTitle }}</DialogTitle>
                <DialogDescription class="min-w-0 break-words">{{
                    dialogDescription
                }}</DialogDescription>
            </DialogHeader>

            <form
                ref="editorForm"
                class="max-w-full min-w-0 space-y-5"
                novalidate
                @submit.prevent="submit"
            >
                <div
                    class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-4 sm:grid-cols-2"
                >
                    <div class="min-w-0">
                        <Label for="preset_name">{{ t('Preset name') }}</Label>
                        <Input
                            id="preset_name"
                            v-model="draft.name"
                            class="mt-2"
                            maxlength="255"
                            :disabled="processing"
                            :aria-invalid="Boolean(fieldErrors.name)"
                            aria-describedby="preset_name_error"
                        />
                        <InputError
                            id="preset_name_error"
                            class="mt-1"
                            :message="fieldErrors.name"
                            role="alert"
                        />
                    </div>
                    <div v-if="mode !== 'copy'" class="min-w-0">
                        <Label for="preset_vehicle_type">{{
                            t('Vehicle type')
                        }}</Label>
                        <AdminSelect
                            id="preset_vehicle_type"
                            v-model="draft.vehicle_type"
                            class="mt-2"
                            :disabled="processing"
                            :aria-invalid="Boolean(fieldErrors.vehicle_type)"
                            aria-describedby="preset_vehicle_type_error"
                            :options="[
                                { value: null, label: t('All vehicle types') },
                                { value: 'scooter', label: t('scooter') },
                                { value: 'car', label: t('car') },
                            ]"
                        />
                        <InputError
                            id="preset_vehicle_type_error"
                            class="mt-1"
                            :message="fieldErrors.vehicle_type"
                            role="alert"
                        />
                    </div>
                </div>

                <div
                    class="min-w-0 rounded-lg border bg-muted/20 p-3 text-sm break-words text-muted-foreground"
                >
                    {{
                        t(
                            'Enter exact positive whole THB totals. Presets do not round and do not require multiples of 50.',
                        )
                    }}
                </div>

                <div
                    class="max-w-full min-w-0 overflow-x-auto rounded-lg border"
                >
                    <table class="w-full min-w-[820px] table-fixed text-sm">
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
                                        v-model="
                                            draft.prices[season.key][tier.key]
                                        "
                                        type="number"
                                        min="1"
                                        max="100000000"
                                        step="1"
                                        inputmode="numeric"
                                        class="tabular-nums"
                                        :disabled="
                                            processing || mode === 'copy'
                                        "
                                        :aria-label="`${t(season.label)}, ${t(tier.label)}, THB`"
                                        :aria-invalid="
                                            Boolean(
                                                fieldErrors[
                                                    pricePath(
                                                        season.key,
                                                        tier.key,
                                                    )
                                                ],
                                            )
                                        "
                                        :aria-describedby="`preset_${season.key}_${tier.key}_error`"
                                    />
                                    <InputError
                                        :id="`preset_${season.key}_${tier.key}_error`"
                                        class="mt-1"
                                        :message="
                                            fieldErrors[
                                                pricePath(season.key, tier.key)
                                            ]
                                        "
                                        role="alert"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="requestError"
                    class="rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm"
                    role="alert"
                >
                    <p>{{ requestError }}</p>
                    <Button
                        v-if="conflict"
                        class="mt-3"
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="processing"
                        @click="refreshPresetState"
                    >
                        <RefreshCw />{{ t('Refresh preset state') }}
                    </Button>
                    <Button
                        v-if="latestPreset"
                        class="mt-3 sm:ml-2"
                        type="button"
                        variant="destructive"
                        size="sm"
                        :disabled="processing"
                        @click="loadLatestPreset"
                    >
                        {{ t('Load latest and discard my draft') }}
                    </Button>
                </div>

                <DialogFooter class="max-w-full min-w-0">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="editorOpen = false"
                    >
                        {{ t('Cancel') }}
                    </Button>
                    <Button type="submit" :disabled="processing">
                        <Copy v-if="mode === 'copy'" />
                        <Save v-else />
                        {{ t(mode === 'copy' ? 'Create copy' : 'Save preset') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="confirmOpen">
        <DialogContent
            class="duration-[350ms] ease-out motion-reduce:animate-none"
        >
            <DialogHeader>
                <DialogTitle>{{ t('Update linked vehicles?') }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'This preset is linked to :count vehicles. Saving will replace their current matrices for future quotes.',
                            { count: source?.linked_vehicles_count ?? 0 },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <div
                class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
            >
                {{
                    t(
                        'Existing bookings are not recalculated. Final booking rounding remains unchanged.',
                    )
                }}
            </div>
            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    @click="confirmOpen = false"
                >
                    {{ t('Review') }}
                </Button>
                <Button type="button" :disabled="processing" @click="persist">
                    <Save />{{
                        t('Update :count vehicles', {
                            count: source?.linked_vehicles_count ?? 0,
                        })
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
