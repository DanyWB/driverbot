<script setup lang="ts">
import { Head, Link, router, useRemember } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    ChevronDown,
    Expand,
    FilterX,
    Minimize,
    Plus,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    unref,
    watch,
} from 'vue';
import AdminDateInput from '@/components/AdminDateInput.vue';
import AdminSelect from '@/components/AdminSelect.vue';
import TimelineBookingSheet from '@/components/timeline/TimelineBookingSheet.vue';
import TimelineVehicleName from '@/components/timeline/TimelineVehicleName.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getActiveLocale, useLocale } from '@/composables/useLocale';
import { lockBodyScroll } from '@/lib/bodyScrollLock';
import { timelineEscapeAction } from '@/lib/timelineInteraction';
import { timelineStatusClasses, timelineStatusLabels } from '@/lib/timeline';
import {
    occupancyLanes,
    timelineMonthBands,
    vehicleNameParts,
    vehicleRowColor,
} from '@/lib/timelinePresentation';
import {
    shiftTimelineRange,
    timelineQuickRangeDays,
    timelineRangeFromToday,
    timelineTwoMonthRange,
} from '@/lib/timelinePeriod';
import type { TimelineQuickRangeDays } from '@/lib/timelinePeriod';
import type {
    TimelineData,
    TimelineBookingSelection,
    TimelineFilters,
    TimelineOccupancy,
    TimelineOptions,
    TimelineVehicle,
} from '@/types';

const props = defineProps<{
    timeline: TimelineData;
    filters: TimelineFilters;
    options: TimelineOptions;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Timeline', href: '/timeline' }],
    },
});

const smallScreen = useMediaQuery('(max-width: 640px)');
const vehicleColumnWidth = computed(() => (smallScreen.value ? 160 : 255));
const dayWidth = 48;
const display = useRemember(
    { filtersOpen: true, compact: false, customRange: false },
    'Timeline:display',
);
const localizedDates = computed(() =>
    props.timeline.dates.map((day) => ({
        ...day,
        month: new Intl.DateTimeFormat(getActiveLocale(), {
            month: 'long',
            timeZone: 'UTC',
        }).format(new Date(day.date)),
        weekday: new Intl.DateTimeFormat(getActiveLocale(), {
            weekday: 'short',
            timeZone: 'UTC',
        }).format(new Date(day.date)),
    })),
);
const monthBands = computed(() => timelineMonthBands(localizedDates.value));
const timelineHead = ref<HTMLElement | null>(null);
const timelineBody = ref<HTMLElement | null>(null);
function syncHorizontal(event: Event, target: HTMLElement | null): void {
    const source = event.currentTarget as HTMLElement;

    if (target && target.scrollLeft !== source.scrollLeft) {
        target.scrollLeft = source.scrollLeft;
    }
}
const hoverDateIndex = ref<number | null>(null);
const loading = ref(false);
const isFullscreen = ref(false);
const timelineContainer = ref<HTMLElement | null>(null);
const rangeError = ref('');
const selectionError = ref('');
const bookingSheetOpen = ref(false);
const bookingSelection = ref<TimelineBookingSelection | null>(null);
const rangeSelection = ref<{
    vehicleId: number;
    vehicleName: string;
    startsOn: string;
    startIndex: number;
} | null>(null);
const hoveredCell = ref<{
    vehicleId: number;
    index: number;
} | null>(null);
const filterState = reactive<TimelineFilters>({ ...props.filters });
const { t } = useLocale();
let releaseBodyScroll: (() => void) | undefined;

watch(
    () => props.filters,
    (filters) => Object.assign(filterState, filters),
    { deep: true },
);

watch(isFullscreen, (fullscreen) => {
    releaseBodyScroll?.();
    releaseBodyScroll = fullscreen ? lockBodyScroll(document) : undefined;
});

const gridWidth = computed(
    () => vehicleColumnWidth.value + props.timeline.dates.length * dayWidth,
);
const rangeLabel = computed(() =>
    formatRange(props.timeline.range.starts_on, props.timeline.range.ends_on),
);
const activeQuickRangeDays = computed(() => {
    if (filterState.starts_on !== props.timeline.range.today) {
        return null;
    }

    const start = parseIsoDate(filterState.starts_on);
    const end = parseIsoDate(filterState.ends_on);

    return start && end ? inclusiveDays(start, end) : null;
});
const filteredCategories = computed(() =>
    props.options.categories.filter(
        (category) =>
            !filterState.vehicle_type ||
            category.vehicle_type === filterState.vehicle_type,
    ),
);
const filteredVehicles = computed(() =>
    props.options.vehicles.filter(
        (vehicle) =>
            (!filterState.vehicle_type ||
                vehicle.type === filterState.vehicle_type) &&
            (!filterState.category_id ||
                vehicle.category_id === filterState.category_id),
    ),
);
const appliedTimelineHref = computed(() => timelineHref(props.filters));
const newBookingHref = computed(() => {
    const params = new URLSearchParams({
        return_to: appliedTimelineHref.value,
    });

    return `/bookings/create?${params}`;
});
const selectionPreview = computed(() => {
    const selection = rangeSelection.value;

    if (!selection) {
        return null;
    }

    const row = props.timeline.rows.find(
        (candidate) => candidate.id === selection.vehicleId,
    );
    const hovered =
        hoveredCell.value?.vehicleId === selection.vehicleId &&
        hoveredCell.value.index >= selection.startIndex
            ? hoveredCell.value
            : null;
    const endIndex = hovered?.index ?? selection.startIndex;

    return {
        vehicleId: selection.vehicleId,
        startIndex: selection.startIndex,
        endIndex,
        hasEndCandidate: hovered !== null,
        valid: row ? isRangeFree(row, selection.startIndex, endIndex) : false,
    };
});

onMounted(() => document.addEventListener('keydown', onTimelineKeydown));
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onTimelineKeydown);
    releaseBodyScroll?.();
});

function toggleFullscreen(): void {
    if (isFullscreen.value) {
        exitFullscreen();

        return;
    }

    isFullscreen.value = true;
}

function exitFullscreen(restoreFocus = false): void {
    if (!isFullscreen.value) {
        return;
    }

    isFullscreen.value = false;

    if (restoreFocus) {
        void nextTick(() => {
            timelineContainer.value
                ?.querySelector<HTMLButtonElement>(
                    '[data-timeline-fullscreen-toggle]',
                )
                ?.focus();
        });
    }
}

function applyFilters(): void {
    visitTimeline();
}

function visitTimeline(): void {
    rangeError.value = validateRange(
        filterState.starts_on,
        filterState.ends_on,
    );

    if (rangeError.value) {
        return;
    }

    clearTimelineSelection();

    router.get('/timeline', queryFor(filterState), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onStart: () => (loading.value = true),
        onError: (errors) => {
            rangeError.value =
                typeof errors.ends_on === 'string'
                    ? errors.ends_on
                    : t('The timeline could not be loaded.');
        },
        onFinish: () => (loading.value = false),
    });
}

function resetFilters(): void {
    Object.assign(filterState, {
        vehicle_type: '',
        category_id: null,
        vehicle_id: null,
        visibility: 'active',
        status: '',
        available_only: false,
    });
    visitTimeline();
}

function onVehicleTypeChange(): void {
    if (
        filterState.category_id &&
        !filteredCategories.value.some(
            (category) => category.id === filterState.category_id,
        )
    ) {
        filterState.category_id = null;
    }

    if (
        filterState.vehicle_id &&
        !filteredVehicles.value.some(
            (vehicle) => vehicle.id === filterState.vehicle_id,
        )
    ) {
        filterState.vehicle_id = null;
    }
}

function onCategoryChange(): void {
    if (
        filterState.vehicle_id &&
        !filteredVehicles.value.some(
            (vehicle) => vehicle.id === filterState.vehicle_id,
        )
    ) {
        filterState.vehicle_id = null;
    }
}

function onStatusChange(): void {
    if (filterState.status) {
        filterState.available_only = false;
    }
}

function onAvailabilityChange(): void {
    if (filterState.available_only) {
        filterState.status = '';
    }
}

function applyQuickRange(days: TimelineQuickRangeDays): void {
    const range = timelineRangeFromToday(props.timeline.range.today, days);

    if (!range) {
        return;
    }

    filterState.starts_on = range.startsOn;
    filterState.ends_on = range.endsOn;
    visitTimeline();
}

function shiftPeriod(direction: -1 | 1): void {
    const range = shiftTimelineRange(
        filterState.starts_on,
        filterState.ends_on,
        direction,
    );

    if (!range) {
        return;
    }

    filterState.starts_on = range.startsOn;
    filterState.ends_on = range.endsOn;
    visitTimeline();
}

function goToToday(): void {
    const range = timelineTwoMonthRange(props.timeline.range.today);

    if (!range) {
        return;
    }

    filterState.starts_on = range.startsOn;
    filterState.ends_on = range.endsOn;
    visitTimeline();
}

function isCellFree(row: TimelineVehicle, index: number): boolean {
    return !row.blocked_ranges.some(
        (range) =>
            range.start_index <= index &&
            range.start_index + range.span_days > index,
    );
}

function isRangeFree(
    row: TimelineVehicle,
    startIndex: number,
    endIndex: number,
): boolean {
    if (
        startIndex < 0 ||
        endIndex < startIndex ||
        endIndex >= props.timeline.dates.length
    ) {
        return false;
    }

    for (let index = startIndex; index <= endIndex; index += 1) {
        if (!isCellFree(row, index)) {
            return false;
        }
    }

    return true;
}

function selectTimelineDate(
    row: TimelineVehicle,
    date: string,
    index: number,
): void {
    if (!row.is_active || !isCellFree(row, index)) {
        return;
    }

    const current = rangeSelection.value;

    if (
        !current ||
        current.vehicleId !== row.id ||
        index < current.startIndex
    ) {
        rangeSelection.value = {
            vehicleId: row.id,
            vehicleName: row.name,
            startsOn: date,
            startIndex: index,
        };
        hoveredCell.value = null;
        selectionError.value = '';
        bookingSelection.value = null;

        return;
    }

    if (!isRangeFree(row, current.startIndex, index)) {
        selectionError.value = t('The selected range includes occupied dates.');

        return;
    }

    bookingSelection.value = {
        vehicleId: row.id,
        vehicleName: row.name,
        startsOn: current.startsOn,
        endsOn: date,
    };
    selectionError.value = '';
    hoveredCell.value = null;
    bookingSheetOpen.value = true;
}

function previewTimelineDate(row: TimelineVehicle, index: number): void {
    hoverDateIndex.value = index;
    const selection = rangeSelection.value;

    if (selection?.vehicleId === row.id && index >= selection.startIndex) {
        hoveredCell.value = { vehicleId: row.id, index };
    }
}

function clearHoveredCell(vehicleId: number): void {
    hoverDateIndex.value = null;

    if (hoveredCell.value?.vehicleId === vehicleId) {
        hoveredCell.value = null;
    }
}

function clearTimelineSelection(): void {
    rangeSelection.value = null;
    hoveredCell.value = null;
    bookingSelection.value = null;
    bookingSheetOpen.value = false;
    selectionError.value = '';
}

function handleBookingSheetOpen(open: boolean): void {
    bookingSheetOpen.value = open;

    if (!open) {
        clearTimelineSelection();
    }
}

function onTimelineKeydown(event: KeyboardEvent): void {
    const action = timelineEscapeAction({
        key: event.key,
        fullscreen: isFullscreen.value,
        bookingSheetOpen: bookingSheetOpen.value,
        hasRangeSelection: Boolean(rangeSelection.value),
    });

    if (!action) {
        return;
    }

    if (action === 'exit_fullscreen') {
        event.preventDefault();
        exitFullscreen(true);

        return;
    }

    if (action === 'clear_selection') {
        clearTimelineSelection();
    }
}

function cellSelectionClasses(row: TimelineVehicle, index: number): string[] {
    const preview = selectionPreview.value;

    if (
        !preview ||
        preview.vehicleId !== row.id ||
        index < preview.startIndex ||
        index > preview.endIndex
    ) {
        return [];
    }

    if (!preview.valid) {
        return index === preview.startIndex
            ? [
                  '!bg-amber-100 ring-2 ring-inset ring-amber-500 dark:!bg-amber-950/60',
              ]
            : ['!bg-destructive/15'];
    }

    if (!preview.hasEndCandidate) {
        return [
            '!bg-amber-100 ring-2 ring-inset ring-amber-500 dark:!bg-amber-950/60',
        ];
    }

    return index === preview.startIndex || index === preview.endIndex
        ? [
              '!bg-emerald-200 ring-2 ring-inset ring-emerald-600 dark:!bg-emerald-900/80',
          ]
        : ['!bg-emerald-100 dark:!bg-emerald-950/70'];
}

function isCellSelected(row: TimelineVehicle, index: number): boolean {
    const preview = selectionPreview.value;

    return Boolean(
        preview &&
        preview.vehicleId === row.id &&
        index >= preview.startIndex &&
        index <= preview.endIndex,
    );
}

function cellSelectionLabel(
    row: TimelineVehicle,
    date: string,
    index: number,
): string {
    const selection = rangeSelection.value;

    return selection?.vehicleId === row.id && index >= selection.startIndex
        ? t('Select :date as rental end for :vehicle', {
              date,
              vehicle: row.name,
          })
        : t('Select :date as rental start for :vehicle', {
              date,
              vehicle: row.name,
          });
}

function bookingHref(publicId: string): string {
    const params = new URLSearchParams({
        return_to: appliedTimelineHref.value,
    });

    return `/bookings/${publicId}?${params}`;
}

function occupancyStyle(
    occupancy: TimelineOccupancy & { lane: number },
): Record<string, string> {
    return {
        top: `${(unref(display).compact ? 5 : 10) + occupancy.lane * 30}px`,
        left: `${vehicleColumnWidth.value + occupancy.start_index * dayWidth + 2}px`,
        width: `${Math.max(dayWidth - 4, occupancy.span_days * dayWidth - 4)}px`,
    };
}

function occupancyClass(occupancy: TimelineOccupancy): string[] {
    return [
        timelineStatusClasses[occupancy.status],
        occupancy.continues_before ? 'rounded-l-none border-l-0' : '',
        occupancy.continues_after ? 'rounded-r-none border-r-0' : '',
    ];
}

function occupancyTitle(occupancy: TimelineOccupancy): string {
    return `${occupancy.label} - ${timelineStatusLabels[occupancy.status]} - ${formatRange(occupancy.starts_on, occupancy.ends_on)}`;
}

function vehicleDetails(row: TimelineVehicle): string {
    return [
        t(row.type),
        row.category?.name,
        row.inventory_code,
        !row.is_active ? t('inactive') : !row.is_visible ? t('internal') : null,
    ]
        .filter((part): part is string => Boolean(part))
        .join(' · ');
}

function rowStyle(row: TimelineVehicle): Record<string, string> {
    return {
        height: `${Math.max(unref(display).compact ? 38 : 48, ...occupancyLanes(row.occupancies).map((item) => item.lane * 30 + (unref(display).compact ? 38 : 48)))}px`,
        width: `${gridWidth.value}px`,
        '--bike-color': vehicleRowColor(row.id),
        '--paint-color':
            vehicleNameParts(row.name).ink ?? vehicleRowColor(row.id),
    };
}

function timelineHref(filters: TimelineFilters): string {
    return `/timeline?${new URLSearchParams(queryFor(filters))}`;
}

function queryFor(filters: TimelineFilters): Record<string, string> {
    const query: Record<string, string> = {
        starts_on: filters.starts_on,
        ends_on: filters.ends_on,
    };

    if (filters.vehicle_type) {
        query.vehicle_type = filters.vehicle_type;
    }

    if (filters.category_id) {
        query.category_id = String(filters.category_id);
    }

    if (filters.vehicle_id) {
        query.vehicle_id = String(filters.vehicle_id);
    }

    if (filters.visibility && filters.visibility !== 'active') {
        query.visibility = filters.visibility;
    }

    if (filters.status) {
        query.status = filters.status;
    }

    if (filters.available_only) {
        query.available_only = '1';
    }

    return query;
}

function validateRange(startsOn: string, endsOn: string): string {
    const start = parseIsoDate(startsOn);
    const end = parseIsoDate(endsOn);

    if (!start || !end) {
        return t('Select both dates.');
    }

    if (end < start) {
        return t('End date must be on or after the start date.');
    }

    if (inclusiveDays(start, end) > 186) {
        return t('The timeline range cannot exceed 186 days.');
    }

    return '';
}

function parseIsoDate(value: string): Date | null {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return null;
    }

    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));

    return toIsoDate(date) === value ? date : null;
}

function toIsoDate(value: Date): string {
    return value.toISOString().slice(0, 10);
}

function inclusiveDays(start: Date, end: Date): number {
    return Math.floor((end.getTime() - start.getTime()) / 86_400_000) + 1;
}

function formatRange(startsOn: string, endsOn: string): string {
    const start = parseIsoDate(startsOn);
    const end = parseIsoDate(endsOn);

    if (!start || !end) {
        return `${startsOn} - ${endsOn}`;
    }

    const formatter = new Intl.DateTimeFormat(
        getActiveLocale() === 'ru' ? 'ru-RU' : 'en-GB',
        {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            timeZone: 'UTC',
        },
    );

    return `${formatter.format(start)} - ${formatter.format(end)}`;
}
</script>

<template>
    <Head :title="t('Availability timeline')" />

    <div
        ref="timelineContainer"
        class="timeline-page flex min-w-0 flex-1 flex-col bg-background transition-colors duration-[350ms] motion-reduce:transition-none"
        :class="
            isFullscreen
                ? 'fixed inset-0 z-40 h-dvh min-h-0 w-screen overflow-hidden'
                : ''
        "
        :data-fullscreen="isFullscreen ? 'true' : undefined"
    >
        <header
            v-if="!isFullscreen"
            class="flex flex-col gap-4 border-b px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
        >
            <div class="min-w-0">
                <p class="text-sm text-muted-foreground">
                    {{ t('Fleet availability') }}
                </p>
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h1 class="text-2xl font-semibold">
                        {{ t('Timeline') }}
                    </h1>
                    <span class="text-sm text-muted-foreground tabular-nums">{{
                        rangeLabel
                    }}</span>
                </div>
            </div>
            <div class="flex items-center gap-2 self-end sm:self-auto">
                <Button as-child>
                    <Link :href="newBookingHref"
                        ><Plus />{{ t('New booking') }}</Link
                    >
                </Button>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            data-timeline-fullscreen-toggle
                            aria-keyshortcuts="Escape"
                            :aria-label="
                                t(
                                    isFullscreen
                                        ? 'Collapse calendar'
                                        : 'Expand calendar',
                                )
                            "
                            :aria-pressed="isFullscreen"
                            :title="
                                t(
                                    isFullscreen
                                        ? 'Collapse calendar'
                                        : 'Expand calendar',
                                )
                            "
                            @click="toggleFullscreen"
                        >
                            <Expand />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        {{
                            t(
                                isFullscreen
                                    ? 'Collapse calendar'
                                    : 'Expand calendar',
                            )
                        }}
                    </TooltipContent>
                </Tooltip>
            </div>
        </header>

        <section
            v-if="!isFullscreen"
            class="border-b px-4 py-4 sm:px-6 lg:px-8"
        >
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold">
                    {{ t('Schedule settings') }}
                </h2>
                <Button
                    variant="outline"
                    size="sm"
                    :aria-expanded="display.filtersOpen"
                    aria-controls="timeline-filter-panel"
                    @click="display.filtersOpen = !display.filtersOpen"
                >
                    {{
                        t(
                            display.filtersOpen
                                ? 'Collapse filters'
                                : 'Expand filters',
                        )
                    }}
                    <ChevronDown
                        class="transition-transform duration-[350ms] motion-reduce:transition-none"
                        :class="display.filtersOpen ? 'rotate-180' : ''"
                    />
                </Button>
            </div>
            <div
                class="timeline-collapse"
                :class="{ 'is-collapsed': !display.filtersOpen }"
                :inert="!display.filtersOpen"
            >
                <div id="timeline-filter-panel" class="min-h-0 overflow-hidden">
                    <div class="flex flex-col gap-3 xl:flex-row xl:items-end">
                        <div class="flex items-center gap-1">
                            <Button
                                variant="outline"
                                size="icon"
                                :title="t('Previous period')"
                                :disabled="loading"
                                @click="shiftPeriod(-1)"
                            >
                                <ChevronLeft />
                                <span class="sr-only">{{
                                    t('Previous period')
                                }}</span>
                            </Button>
                            <Button
                                variant="outline"
                                :disabled="loading"
                                @click="goToToday"
                            >
                                <CalendarDays />{{ t('Today') }}
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                :title="t('Next period')"
                                :disabled="loading"
                                @click="shiftPeriod(1)"
                            >
                                <ChevronRight />
                                <span class="sr-only">{{
                                    t('Next period')
                                }}</span>
                            </Button>
                        </div>

                        <div
                            class="flex flex-wrap items-center gap-1.5"
                            role="group"
                            :aria-label="t('Calendar range')"
                        >
                            <Button
                                v-for="days in timelineQuickRangeDays"
                                :key="days"
                                variant="outline"
                                :class="
                                    activeQuickRangeDays === days
                                        ? 'timeline-range-active'
                                        : ''
                                "
                                :aria-pressed="activeQuickRangeDays === days"
                                :disabled="loading"
                                @click="applyQuickRange(days)"
                                >{{ t(':count days', { count: days }) }}</Button
                            >
                            <Button
                                variant="ghost"
                                :aria-expanded="display.customRange"
                                @click="
                                    display.customRange = !display.customRange
                                "
                                >{{ t('Other period') }}</Button
                            >
                        </div>
                    </div>
                    <form
                        v-if="display.customRange"
                        class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-end"
                        @submit.prevent="applyFilters"
                    >
                        <div class="w-full sm:max-w-44">
                            <Label for="timeline_starts_on">{{
                                t('From')
                            }}</Label>
                            <AdminDateInput
                                id="timeline_starts_on"
                                v-model="filterState.starts_on"
                                class="mt-1.5"
                            />
                        </div>
                        <div class="w-full sm:max-w-44">
                            <Label for="timeline_ends_on">{{ t('To') }}</Label>
                            <AdminDateInput
                                id="timeline_ends_on"
                                v-model="filterState.ends_on"
                                class="mt-1.5"
                            />
                        </div>
                        <Button type="submit" :disabled="loading">{{
                            t('Apply')
                        }}</Button>
                        <p
                            v-if="rangeError"
                            class="pb-2 text-sm text-destructive"
                            role="alert"
                        >
                            {{ rangeError }}
                        </p>
                    </form>

                    <form
                        class="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-[repeat(5,minmax(0,1fr))_auto_auto]"
                        @submit.prevent="applyFilters"
                    >
                        <div>
                            <Label for="timeline_type">{{ t('Type') }}</Label>
                            <AdminSelect
                                id="timeline_type"
                                v-model="filterState.vehicle_type"
                                class="mt-1.5"
                                :options="[
                                    { value: '', label: t('All types') },
                                    ...options.vehicle_types.map((type) => ({
                                        value: type,
                                        label: t(type),
                                    })),
                                ]"
                                @change="onVehicleTypeChange"
                            />
                        </div>
                        <div>
                            <Label for="timeline_category">{{
                                t('Category')
                            }}</Label>
                            <AdminSelect
                                id="timeline_category"
                                v-model="filterState.category_id"
                                class="mt-1.5"
                                :options="[
                                    { value: null, label: t('All categories') },
                                    ...filteredCategories.map((category) => ({
                                        value: category.id,
                                        label: category.name,
                                    })),
                                ]"
                                @change="onCategoryChange"
                            />
                        </div>
                        <div>
                            <Label for="timeline_vehicle">{{
                                t('Vehicle')
                            }}</Label>
                            <AdminSelect
                                id="timeline_vehicle"
                                v-model="filterState.vehicle_id"
                                class="mt-1.5"
                                :options="[
                                    { value: null, label: t('All vehicles') },
                                    ...filteredVehicles.map((vehicle) => ({
                                        value: vehicle.id,
                                        label: vehicle.name,
                                    })),
                                ]"
                            />
                        </div>
                        <div>
                            <Label for="timeline_visibility">{{
                                t('Visibility')
                            }}</Label>
                            <AdminSelect
                                id="timeline_visibility"
                                v-model="filterState.visibility"
                                class="mt-1.5"
                                :options="[
                                    { value: 'active', label: t('All active') },
                                    {
                                        value: 'visible',
                                        label: t('Client-visible'),
                                    },
                                    {
                                        value: 'hidden',
                                        label: t('Internal only'),
                                    },
                                    { value: 'inactive', label: t('Inactive') },
                                    { value: 'all', label: t('All records') },
                                ]"
                            />
                        </div>
                        <div>
                            <Label for="timeline_status">{{
                                t('Occupancy')
                            }}</Label>
                            <AdminSelect
                                id="timeline_status"
                                v-model="filterState.status"
                                class="mt-1.5"
                                :options="[
                                    {
                                        value: '',
                                        label: t('All requests and blocks'),
                                    },
                                    ...options.statuses.map((status) => ({
                                        value: status,
                                        label: t(timelineStatusLabels[status]),
                                    })),
                                ]"
                                @change="onStatusChange"
                            />
                        </div>
                        <label
                            class="flex h-9 cursor-pointer items-center gap-2 self-end rounded-md border px-3 text-sm whitespace-nowrap"
                        >
                            <input
                                v-model="filterState.available_only"
                                type="checkbox"
                                class="size-4 accent-foreground"
                                @change="onAvailabilityChange"
                            />
                            {{ t('Available only') }}
                        </label>
                        <div class="flex items-end gap-1">
                            <Button
                                type="submit"
                                variant="secondary"
                                :disabled="loading"
                                >{{ t('Filter') }}</Button
                            >
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :title="t('Reset filters')"
                                :disabled="loading"
                                @click="resetFilters"
                            >
                                <FilterX />
                                <span class="sr-only">{{
                                    t('Reset filters')
                                }}</span>
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <section
            v-if="!isFullscreen"
            class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 text-sm sm:px-6 lg:px-8"
        >
            <div class="flex flex-wrap gap-x-5 gap-y-1 tabular-nums">
                <span>{{
                    t('Vehicles count', { count: timeline.stats.vehicles })
                }}</span>
                <span class="text-emerald-700 dark:text-emerald-400">{{
                    t('Fully free count', { count: timeline.stats.available })
                }}</span>
                <span class="text-muted-foreground">{{
                    t('Blocks count', { count: timeline.stats.occupancies })
                }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                <span
                    v-for="status in options.statuses"
                    :key="status"
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <span
                        class="size-2.5 rounded-full border"
                        :class="timelineStatusClasses[status]"
                    />
                    {{ t(timelineStatusLabels[status]) }}
                </span>
            </div>
        </section>

        <section
            v-if="!isFullscreen && rangeSelection"
            class="border-b border-amber-300 bg-amber-50 px-4 py-3 text-amber-950 sm:px-6 lg:px-8 dark:border-amber-800 dark:bg-amber-950/35 dark:text-amber-100"
            aria-live="polite"
        >
            <div class="flex items-start justify-between gap-4">
                <div class="flex min-w-0 gap-3">
                    <CalendarDays class="mt-0.5 size-4 shrink-0" />
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">
                            {{
                                t('Start selected: :vehicle, :date', {
                                    vehicle: rangeSelection.vehicleName,
                                    date: rangeSelection.startsOn,
                                })
                            }}
                        </p>
                        <p
                            class="mt-0.5 text-xs text-amber-800 dark:text-amber-200"
                        >
                            {{
                                t('Choose the rental end date in the same row.')
                            }}
                        </p>
                        <p
                            v-if="selectionError"
                            class="mt-1 text-sm font-medium text-destructive"
                            role="alert"
                        >
                            {{ selectionError }}
                        </p>
                    </div>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="shrink-0"
                    @click="clearTimelineSelection"
                >
                    <X />{{ t('Cancel selection') }}
                </Button>
            </div>
        </section>

        <section
            class="relative min-h-0 min-w-0 flex-1"
            :style="
                isFullscreen
                    ? { display: 'flex', flexDirection: 'column' }
                    : undefined
            "
            :class="isFullscreen ? 'h-dvh' : ''"
            :aria-label="t('Vehicle availability')"
        >
            <Tooltip v-if="isFullscreen">
                <TooltipTrigger as-child>
                    <Button
                        type="button"
                        variant="secondary"
                        size="icon"
                        class="absolute top-2 right-2 z-50 border bg-background/90 shadow-md backdrop-blur-sm"
                        data-timeline-fullscreen-toggle
                        aria-keyshortcuts="Escape"
                        :aria-label="t('Collapse calendar')"
                        :aria-pressed="true"
                        :title="t('Collapse calendar')"
                        @click="exitFullscreen(true)"
                    >
                        <Minimize />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{{ t('Collapse calendar') }}</TooltipContent>
            </Tooltip>

            <div
                v-if="isFullscreen"
                class="absolute top-2 left-1/2 z-50 flex -translate-x-1/2 items-center gap-1 rounded-lg border bg-background/90 p-1 shadow-md backdrop-blur-sm"
                data-timeline-fullscreen-range
                role="group"
                :aria-label="t('Calendar range')"
            >
                <Button
                    v-for="days in timelineQuickRangeDays"
                    :key="days"
                    type="button"
                    size="sm"
                    class="h-8 min-w-9 px-2 text-xs tabular-nums"
                    :variant="
                        activeQuickRangeDays === days ? 'default' : 'outline'
                    "
                    :disabled="loading"
                    :aria-pressed="activeQuickRangeDays === days"
                    :aria-label="
                        t('Show :count days from today', { count: days })
                    "
                    :title="t('Show :count days from today', { count: days })"
                    @click="applyQuickRange(days)"
                >
                    {{ days }}
                </Button>
            </div>

            <div
                v-if="timeline.rows.length === 0"
                class="flex h-full min-h-80 flex-col items-center justify-center px-6 text-center"
            >
                <FilterX class="mb-3 size-8 text-muted-foreground" />
                <h2 class="font-medium">
                    {{ t('No vehicles match these filters') }}
                </h2>
                <Button
                    v-if="!isFullscreen"
                    variant="outline"
                    class="mt-4"
                    @click="resetFilters"
                >
                    {{ t('Reset filters') }}
                </Button>
            </div>

            <div
                v-if="timeline.rows.length > 0"
                ref="timelineHead"
                class="sticky top-0 z-30 shrink-0 overflow-x-auto bg-background"
                :class="isFullscreen ? 'pt-12' : ''"
                @scroll="syncHorizontal($event, timelineBody)"
            >
                <div
                    class="timeline-grid-head flex h-24 border-b bg-background shadow-xs"
                    :style="{ width: `${gridWidth}px` }"
                >
                    <div
                        class="timeline-vehicle-head sticky left-0 z-40 flex shrink-0 items-center justify-between gap-2 border-r bg-background px-3 text-xs font-semibold text-muted-foreground"
                        :style="{ width: `${vehicleColumnWidth}px` }"
                    >
                        {{ t('Vehicle') }}
                        <Button
                            variant="outline"
                            size="icon"
                            class="size-7"
                            :aria-expanded="!display.compact"
                            :aria-label="
                                t(
                                    display.compact
                                        ? 'Show vehicle details'
                                        : 'Hide vehicle details',
                                )
                            "
                            @click="display.compact = !display.compact"
                            ><Plus
                                v-if="display.compact"
                                class="size-3" /><Minimize
                                v-else
                                class="size-3"
                        /></Button>
                    </div>
                    <div class="relative flex pt-12">
                        <div class="absolute inset-x-0 top-0 flex h-12">
                            <div
                                v-for="band in monthBands"
                                :key="band.start"
                                class="timeline-month flex shrink-0 items-center justify-center border-r text-xs font-extrabold tracking-widest uppercase"
                                :style="{
                                    width: `${band.span * dayWidth}px`,
                                }"
                            >
                                {{ band.label }}
                            </div>
                        </div>
                        <div
                            v-for="(date, index) in localizedDates"
                            :key="date.date"
                            class="timeline-date-head flex shrink-0 flex-col items-center justify-center border-r text-xs tabular-nums"
                            :class="[
                                date.is_weekend ? 'bg-muted/45' : '',
                                hoverDateIndex === index
                                    ? 'timeline-date-hover'
                                    : '',
                                date.is_today
                                    ? 'bg-cyan-50 text-cyan-900 dark:bg-cyan-950 dark:text-cyan-100'
                                    : '',
                            ]"
                            :style="{ width: `${dayWidth}px` }"
                            :title="date.date"
                        >
                            <strong>{{ date.day }}</strong>
                            <span
                                class="text-[10px] text-muted-foreground uppercase"
                                >{{ date.weekday.slice(0, 2) }}</span
                            >
                        </div>
                    </div>
                </div>
            </div>
            <div
                v-if="timeline.rows.length > 0"
                ref="timelineBody"
                @scroll="syncHorizontal($event, timelineHead)"
                class="timeline-scroll-area max-w-full overflow-auto overscroll-x-contain overscroll-y-auto [contain:paint]"
                :class="[
                    isFullscreen
                        ? 'max-h-none min-h-0 flex-1'
                        : 'min-h-[360px]',
                    loading ? 'opacity-60' : '',
                ]"
                :aria-busy="loading"
            >
                <div class="relative" :style="{ width: `${gridWidth}px` }">
                    <div
                        v-for="row in timeline.rows"
                        :key="row.id"
                        class="timeline-vehicle-row group relative flex border-b"
                        :style="rowStyle(row)"
                        @mouseleave="clearHoveredCell(row.id)"
                    >
                        <div
                            class="timeline-vehicle-label sticky left-0 z-20 flex shrink-0 items-center gap-2 border-r px-3"
                            :style="{ width: `${vehicleColumnWidth}px` }"
                        >
                            <span
                                class="h-7 w-1 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor: vehicleRowColor(row.id),
                                }"
                            />
                            <div class="flex min-w-0 flex-1 items-center gap-2">
                                <div class="min-w-0 flex-1">
                                    <Tooltip :delay-duration="1000"
                                        ><TooltipTrigger as-child
                                            ><Link
                                                :href="`/vehicles/${row.id}/edit`"
                                                class="timeline-name block w-fit max-w-full truncate text-xs font-semibold"
                                                :aria-label="row.name"
                                                ><TimelineVehicleName
                                                    :name="
                                                        row.name
                                                    " /></Link></TooltipTrigger
                                        ><TooltipContent
                                            ><TimelineVehicleName
                                                :name="row.name"
                                            />
                                            <p class="mt-1 text-xs">
                                                {{ vehicleDetails(row) }}
                                            </p></TooltipContent
                                        ></Tooltip
                                    >
                                    <p
                                        v-if="!display.compact"
                                        class="truncate text-[11px] text-muted-foreground"
                                        :title="vehicleDetails(row)"
                                    >
                                        <span>{{ t(row.type) }}</span>
                                        <span v-if="row.category">
                                            · {{ row.category.name }}</span
                                        >
                                        <span v-if="row.inventory_code">
                                            · {{ row.inventory_code }}</span
                                        >
                                    </p>
                                </div>
                                <span
                                    v-if="!row.is_active || !row.is_visible"
                                    class="shrink-0 rounded border px-1 py-0.5 text-[9px] leading-none font-medium text-muted-foreground"
                                    :title="vehicleDetails(row)"
                                >
                                    {{
                                        t(
                                            !row.is_active
                                                ? 'inactive'
                                                : 'internal',
                                        )
                                    }}
                                </span>
                            </div>
                        </div>

                        <template
                            v-for="(date, index) in timeline.dates"
                            :key="date.date"
                        >
                            <button
                                v-if="row.is_active && isCellFree(row, index)"
                                type="button"
                                class="timeline-day group/cell relative h-full shrink-0 border-r p-0 focus-visible:z-20 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-emerald-600"
                                :class="[
                                    date.is_weekend ? 'bg-muted/25' : '',
                                    date.is_today
                                        ? 'bg-cyan-50/50 dark:bg-cyan-950/30'
                                        : '',
                                    ...cellSelectionClasses(row, index),
                                ]"
                                :style="{ width: `${dayWidth}px` }"
                                :title="
                                    cellSelectionLabel(row, date.date, index)
                                "
                                :aria-label="
                                    cellSelectionLabel(row, date.date, index)
                                "
                                :aria-pressed="isCellSelected(row, index)"
                                @mouseenter="previewTimelineDate(row, index)"
                                @focus="previewTimelineDate(row, index)"
                                @blur="clearHoveredCell(row.id)"
                                @click="
                                    selectTimelineDate(row, date.date, index)
                                "
                            >
                                <Plus
                                    class="pointer-events-none absolute top-1/2 left-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 opacity-0 transition-opacity group-hover/cell:opacity-50"
                                />
                            </button>
                            <div
                                v-else
                                class="timeline-day h-full shrink-0 border-r"
                                @mouseenter="hoverDateIndex = index"
                                :class="[
                                    date.is_weekend ? 'bg-muted/25' : '',
                                    date.is_today
                                        ? 'bg-cyan-50/50 dark:bg-cyan-950/30'
                                        : '',
                                ]"
                                :style="{ width: `${dayWidth}px` }"
                                :title="
                                    !row.is_active && isCellFree(row, index)
                                        ? t(
                                              'Inactive vehicles cannot be booked.',
                                          )
                                        : undefined
                                "
                            />
                        </template>

                        <template
                            v-for="occupancy in occupancyLanes(row.occupancies)"
                            :key="occupancy.id"
                        >
                            <Link
                                v-if="occupancy.booking_public_id"
                                :href="bookingHref(occupancy.booking_public_id)"
                                class="absolute z-10 flex h-[26px] items-center overflow-hidden rounded-sm border px-2 text-xs font-semibold shadow-xs focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                                :class="occupancyClass(occupancy)"
                                :style="occupancyStyle(occupancy)"
                                :title="occupancyTitle(occupancy)"
                                :aria-label="occupancyTitle(occupancy)"
                            >
                                <span class="truncate">{{
                                    occupancy.label
                                }}</span>
                            </Link>
                            <div
                                v-else
                                class="absolute z-10 flex h-[26px] items-center overflow-hidden rounded-sm border px-2 text-xs font-semibold shadow-xs"
                                :class="occupancyClass(occupancy)"
                                :style="occupancyStyle(occupancy)"
                                :title="occupancyTitle(occupancy)"
                            >
                                <span class="truncate">{{
                                    occupancy.label
                                }}</span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <TimelineBookingSheet
        :open="bookingSheetOpen"
        :selection="bookingSelection"
        :return-to="appliedTimelineHref"
        @update:open="handleBookingSheetOpen"
    />
</template>

<style>
/* A non-scrolling overflow:hidden layout ancestor traps position:sticky.
   Clip horizontal overflow without creating a second vertical scroll container. */
main:has(> .timeline-page) {
    min-width: 0;
    overflow-x: clip;
    overflow-y: visible;
}
.timeline-page {
    --timeline-base: var(--background);
}
.dark .timeline-page {
    --timeline-base: #111313;
}
.timeline-collapse {
    display: grid;
    grid-template-rows: 1fr;
    opacity: 1;
    transition:
        grid-template-rows 350ms ease,
        opacity 350ms ease;
}
.timeline-collapse.is-collapsed {
    grid-template-rows: 0fr;
    opacity: 0;
}
.timeline-range-active {
    background: #173d79 !important;
    border-color: #3988ff !important;
    color: white !important;
    box-shadow: 0 0 0 1px #3988ff44;
}
.timeline-month {
    background: color-mix(in srgb, #55c5a8 10%, var(--background));
}
.dark .timeline-month {
    background: #222626;
    color: #d9e6e2;
    border-color: #414b48;
}
.dark .timeline-date-head,
.dark .timeline-vehicle-head {
    background: #171919;
}
.timeline-vehicle-row {
    background: color-mix(in srgb, var(--bike-color) 19%, var(--timeline-base));
}
.timeline-vehicle-label {
    background: color-mix(in srgb, var(--bike-color) 24%, var(--background));
}
.dark .timeline-vehicle-label {
    background: color-mix(in srgb, var(--bike-color) 24%, #151717);
}
.timeline-vehicle-row,
.timeline-vehicle-label,
.timeline-day,
.timeline-date-head,
.timeline-name {
    transition:
        background-color 350ms ease,
        box-shadow 350ms ease,
        border-color 350ms ease,
        color 350ms ease;
}
.timeline-vehicle-row:hover,
.timeline-vehicle-row:focus-within {
    background: color-mix(in srgb, var(--bike-color) 48%, var(--timeline-base));
}
.timeline-vehicle-row:hover .timeline-vehicle-label,
.timeline-vehicle-row:focus-within .timeline-vehicle-label {
    background: color-mix(in srgb, var(--bike-color) 54%, var(--background));
}
.dark .timeline-vehicle-row:hover .timeline-vehicle-label,
.dark .timeline-vehicle-row:focus-within .timeline-vehicle-label {
    background: color-mix(in srgb, var(--bike-color) 54%, #151717);
}
.timeline-day:hover,
.timeline-day:focus-visible {
    background: color-mix(in srgb, var(--bike-color) 92%, white);
    box-shadow:
        inset 0 0 0 1px color-mix(in srgb, var(--bike-color) 45%, white),
        0 0 10px #3988ff88;
}
.timeline-date-hover,
.timeline-date-head:hover {
    outline: 2px solid #69b7ff;
    outline-offset: -2px;
    box-shadow:
        inset 0 0 6px #4da3ff55,
        0 0 7px 2px #3988ff,
        0 0 15px 4px #3988ff88;
    color: #2878ff;
    text-shadow:
        0 0 2px #b9faff,
        0 0 6px #3988ff;
}
.timeline-name {
    padding: 3px 7px;
    border: 1px solid color-mix(in srgb, var(--paint-color) 48%, #777e7b);
    border-radius: 6px;
    background: var(--background);
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--paint-color) 22%, #303534);
}
.dark .timeline-name {
    background: #131515;
}
.timeline-vehicle-row:hover .timeline-name,
.timeline-name:focus-visible {
    border-color: var(--paint-color);
    box-shadow:
        0 0 0 2px color-mix(in srgb, var(--paint-color) 72%, #e7eeeb),
        0 0 16px color-mix(in srgb, var(--paint-color) 85%, transparent),
        0 0 34px color-mix(in srgb, var(--paint-color) 58%, transparent);
}
.timeline-paint-word {
    color: var(--paint-ink);
    font-weight: 800;
    -webkit-text-stroke: 0.35px #6b7471;
    paint-order: stroke fill;
}
.dark .timeline-paint-word {
    -webkit-text-stroke: 0.35px #eef2f0;
}
.dark .timeline-paint-black {
    -webkit-text-stroke: 0.45px #f4f6f5;
    text-shadow: 0 0 1px #f4f6f5;
}
@media (prefers-reduced-motion: reduce) {
    .timeline-page *,
    .timeline-collapse {
        transition: none !important;
    }
}
</style>
