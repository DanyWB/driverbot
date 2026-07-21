<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    FilterX,
    Plus,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { timelineStatusClasses, timelineStatusLabels } from '@/lib/timeline';
import type {
    TimelineData,
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

const vehicleColumnWidth = 240;
const dayWidth = 44;
const loading = ref(false);
const rangeError = ref('');
const filterState = reactive<TimelineFilters>({ ...props.filters });

watch(
    () => props.filters,
    (filters) => Object.assign(filterState, filters),
    { deep: true },
);

const gridWidth = computed(
    () => vehicleColumnWidth + props.timeline.dates.length * dayWidth,
);
const rangeLabel = computed(() =>
    formatRange(props.timeline.range.starts_on, props.timeline.range.ends_on),
);
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

    router.get('/timeline', queryFor(filterState), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onStart: () => (loading.value = true),
        onError: (errors) => {
            rangeError.value =
                typeof errors.ends_on === 'string'
                    ? errors.ends_on
                    : 'The timeline could not be loaded.';
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

function shiftPeriod(direction: -1 | 1): void {
    const start = parseIsoDate(filterState.starts_on);
    const end = parseIsoDate(filterState.ends_on);

    if (!start || !end) {
        return;
    }

    if (isFullMonth(start, end)) {
        const nextStart = new Date(
            Date.UTC(
                start.getUTCFullYear(),
                start.getUTCMonth() + direction,
                1,
            ),
        );
        const nextEnd = new Date(
            Date.UTC(
                nextStart.getUTCFullYear(),
                nextStart.getUTCMonth() + 1,
                0,
            ),
        );
        filterState.starts_on = toIsoDate(nextStart);
        filterState.ends_on = toIsoDate(nextEnd);
    } else {
        const days = inclusiveDays(start, end);
        start.setUTCDate(start.getUTCDate() + days * direction);
        end.setUTCDate(end.getUTCDate() + days * direction);
        filterState.starts_on = toIsoDate(start);
        filterState.ends_on = toIsoDate(end);
    }

    visitTimeline();
}

function goToToday(): void {
    const today = parseIsoDate(props.timeline.range.today);

    if (!today) {
        return;
    }

    filterState.starts_on = toIsoDate(
        new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), 1)),
    );
    filterState.ends_on = toIsoDate(
        new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth() + 1, 0)),
    );
    visitTimeline();
}

function isCellFree(row: TimelineVehicle, index: number): boolean {
    return !row.occupancies.some(
        (occupancy) =>
            occupancy.start_index <= index &&
            occupancy.start_index + occupancy.span_days > index,
    );
}

function createBookingHref(row: TimelineVehicle, date: string): string {
    const params = new URLSearchParams({
        vehicle_id: String(row.id),
        starts_on: date,
        ends_on: date,
        return_to: appliedTimelineHref.value,
    });

    return `/bookings/create?${params}`;
}

function bookingHref(publicId: string): string {
    const params = new URLSearchParams({
        return_to: appliedTimelineHref.value,
    });

    return `/bookings/${publicId}?${params}`;
}

function occupancyStyle(occupancy: TimelineOccupancy): Record<string, string> {
    return {
        left: `${vehicleColumnWidth + occupancy.start_index * dayWidth + 2}px`,
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

function monthLabel(index: number): string {
    const date = props.timeline.dates[index];

    return index === 0 || date.day === 1 ? date.month : '';
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
        return 'Select both dates.';
    }

    if (end < start) {
        return 'End date must be on or after the start date.';
    }

    if (inclusiveDays(start, end) > 93) {
        return 'The timeline range cannot exceed 93 days.';
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

function isFullMonth(start: Date, end: Date): boolean {
    const lastDay = new Date(
        Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + 1, 0),
    );

    return (
        start.getUTCDate() === 1 &&
        start.getUTCFullYear() === end.getUTCFullYear() &&
        start.getUTCMonth() === end.getUTCMonth() &&
        end.getUTCDate() === lastDay.getUTCDate()
    );
}

function formatRange(startsOn: string, endsOn: string): string {
    const start = parseIsoDate(startsOn);
    const end = parseIsoDate(endsOn);

    if (!start || !end) {
        return `${startsOn} - ${endsOn}`;
    }

    const formatter = new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });

    return `${formatter.format(start)} - ${formatter.format(end)}`;
}
</script>

<template>
    <Head title="Availability timeline" />

    <div class="flex min-w-0 flex-1 flex-col">
        <header
            class="flex flex-col gap-4 border-b px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
        >
            <div class="min-w-0">
                <p class="text-sm text-muted-foreground">Fleet availability</p>
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h1 class="text-2xl font-semibold">Timeline</h1>
                    <span class="text-sm text-muted-foreground tabular-nums">{{
                        rangeLabel
                    }}</span>
                </div>
            </div>
            <Button as-child>
                <Link :href="newBookingHref"><Plus />New booking</Link>
            </Button>
        </header>

        <section class="border-b px-4 py-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-end">
                <div class="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="icon"
                        title="Previous period"
                        :disabled="loading"
                        @click="shiftPeriod(-1)"
                    >
                        <ChevronLeft />
                        <span class="sr-only">Previous period</span>
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="loading"
                        @click="goToToday"
                    >
                        <CalendarDays />Today
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        title="Next period"
                        :disabled="loading"
                        @click="shiftPeriod(1)"
                    >
                        <ChevronRight />
                        <span class="sr-only">Next period</span>
                    </Button>
                </div>

                <form
                    class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-end"
                    @submit.prevent="applyFilters"
                >
                    <div class="w-full sm:max-w-44">
                        <Label for="timeline_starts_on">From</Label>
                        <Input
                            id="timeline_starts_on"
                            v-model="filterState.starts_on"
                            class="mt-1.5"
                            type="date"
                        />
                    </div>
                    <div class="w-full sm:max-w-44">
                        <Label for="timeline_ends_on">To</Label>
                        <Input
                            id="timeline_ends_on"
                            v-model="filterState.ends_on"
                            class="mt-1.5"
                            type="date"
                        />
                    </div>
                    <Button type="submit" :disabled="loading">Apply</Button>
                    <p
                        v-if="rangeError"
                        class="pb-2 text-sm text-destructive"
                        role="alert"
                    >
                        {{ rangeError }}
                    </p>
                </form>
            </div>

            <form
                class="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-[repeat(5,minmax(0,1fr))_auto_auto]"
                @submit.prevent="applyFilters"
            >
                <div>
                    <Label for="timeline_type">Type</Label>
                    <select
                        id="timeline_type"
                        v-model="filterState.vehicle_type"
                        class="admin-select mt-1.5 w-full"
                        @change="onVehicleTypeChange"
                    >
                        <option value="">All types</option>
                        <option
                            v-for="type in options.vehicle_types"
                            :key="type"
                            :value="type"
                            class="capitalize"
                        >
                            {{ type }}
                        </option>
                    </select>
                </div>
                <div>
                    <Label for="timeline_category">Category</Label>
                    <select
                        id="timeline_category"
                        v-model.number="filterState.category_id"
                        class="admin-select mt-1.5 w-full"
                        @change="onCategoryChange"
                    >
                        <option :value="null">All categories</option>
                        <option
                            v-for="category in filteredCategories"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <Label for="timeline_vehicle">Vehicle</Label>
                    <select
                        id="timeline_vehicle"
                        v-model.number="filterState.vehicle_id"
                        class="admin-select mt-1.5 w-full"
                    >
                        <option :value="null">All vehicles</option>
                        <option
                            v-for="vehicle in filteredVehicles"
                            :key="vehicle.id"
                            :value="vehicle.id"
                        >
                            {{ vehicle.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <Label for="timeline_visibility">Visibility</Label>
                    <select
                        id="timeline_visibility"
                        v-model="filterState.visibility"
                        class="admin-select mt-1.5 w-full"
                    >
                        <option value="active">All active</option>
                        <option value="visible">Client-visible</option>
                        <option value="hidden">Internal only</option>
                        <option value="inactive">Inactive</option>
                        <option value="all">All records</option>
                    </select>
                </div>
                <div>
                    <Label for="timeline_status">Occupancy</Label>
                    <select
                        id="timeline_status"
                        v-model="filterState.status"
                        class="admin-select mt-1.5 w-full"
                        @change="onStatusChange"
                    >
                        <option value="">All blocking</option>
                        <option
                            v-for="status in options.statuses"
                            :key="status"
                            :value="status"
                        >
                            {{ timelineStatusLabels[status] }}
                        </option>
                    </select>
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
                    Available only
                </label>
                <div class="flex items-end gap-1">
                    <Button
                        type="submit"
                        variant="secondary"
                        :disabled="loading"
                        >Filter</Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        title="Reset filters"
                        :disabled="loading"
                        @click="resetFilters"
                    >
                        <FilterX />
                        <span class="sr-only">Reset filters</span>
                    </Button>
                </div>
            </form>
        </section>

        <section
            class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 text-sm sm:px-6 lg:px-8"
        >
            <div class="flex flex-wrap gap-x-5 gap-y-1 tabular-nums">
                <span
                    ><strong>{{ timeline.stats.vehicles }}</strong>
                    vehicles</span
                >
                <span class="text-emerald-700 dark:text-emerald-400"
                    ><strong>{{ timeline.stats.available }}</strong> fully
                    free</span
                >
                <span class="text-muted-foreground"
                    ><strong>{{ timeline.stats.occupancies }}</strong>
                    blocks</span
                >
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
                    {{ timelineStatusLabels[status] }}
                </span>
            </div>
        </section>

        <section
            class="relative min-w-0 flex-1"
            aria-label="Vehicle availability"
        >
            <div
                v-if="timeline.rows.length === 0"
                class="flex min-h-80 flex-col items-center justify-center px-6 text-center"
            >
                <FilterX class="mb-3 size-8 text-muted-foreground" />
                <h2 class="font-medium">No vehicles match these filters</h2>
                <Button variant="outline" class="mt-4" @click="resetFilters">
                    Reset filters
                </Button>
            </div>

            <div
                v-else
                class="max-h-[70vh] min-h-[360px] max-w-full overflow-auto overscroll-contain [contain:paint]"
                :class="loading ? 'opacity-60' : ''"
                :aria-busy="loading"
            >
                <div class="relative" :style="{ width: `${gridWidth}px` }">
                    <div
                        class="sticky top-0 z-30 flex h-14 border-b bg-background shadow-xs"
                        :style="{ width: `${gridWidth}px` }"
                    >
                        <div
                            class="sticky left-0 z-40 flex shrink-0 items-center border-r bg-background px-4 text-xs font-semibold text-muted-foreground"
                            :style="{ width: `${vehicleColumnWidth}px` }"
                        >
                            Vehicle
                        </div>
                        <div
                            v-for="(date, index) in timeline.dates"
                            :key="date.date"
                            class="flex shrink-0 flex-col items-center justify-center border-r text-xs tabular-nums"
                            :class="[
                                date.is_weekend ? 'bg-muted/45' : '',
                                date.is_today
                                    ? 'bg-cyan-50 text-cyan-900 dark:bg-cyan-950 dark:text-cyan-100'
                                    : '',
                            ]"
                            :style="{ width: `${dayWidth}px` }"
                            :title="date.date"
                        >
                            <span
                                class="h-4 text-[10px] font-medium text-muted-foreground uppercase"
                            >
                                {{ monthLabel(index) }}
                            </span>
                            <strong>{{ date.day }}</strong>
                            <span
                                class="text-[10px] text-muted-foreground uppercase"
                                >{{ date.weekday.slice(0, 2) }}</span
                            >
                        </div>
                    </div>

                    <div
                        v-for="row in timeline.rows"
                        :key="row.id"
                        class="group relative flex h-13 border-b"
                        :style="{ width: `${gridWidth}px` }"
                    >
                        <div
                            class="sticky left-0 z-20 flex shrink-0 items-center border-r bg-background px-4 group-hover:bg-muted/30"
                            :style="{ width: `${vehicleColumnWidth}px` }"
                        >
                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-medium"
                                    :title="row.name"
                                >
                                    {{ row.name }}
                                </p>
                                <p
                                    class="truncate text-[11px] text-muted-foreground"
                                >
                                    <span class="capitalize">{{
                                        row.type
                                    }}</span>
                                    <span v-if="row.category">
                                        · {{ row.category.name }}</span
                                    >
                                    <span v-if="row.inventory_code">
                                        · {{ row.inventory_code }}</span
                                    >
                                    <span v-if="!row.is_active">
                                        · inactive</span
                                    >
                                    <span v-else-if="!row.is_visible">
                                        · internal</span
                                    >
                                </p>
                            </div>
                        </div>

                        <template
                            v-for="(date, index) in timeline.dates"
                            :key="date.date"
                        >
                            <Link
                                v-if="isCellFree(row, index)"
                                :href="createBookingHref(row, date.date)"
                                class="relative shrink-0 border-r hover:bg-emerald-50 focus-visible:z-20 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-emerald-600 dark:hover:bg-emerald-950/40"
                                :class="[
                                    date.is_weekend ? 'bg-muted/25' : '',
                                    date.is_today
                                        ? 'bg-cyan-50/50 dark:bg-cyan-950/30'
                                        : '',
                                ]"
                                :style="{ width: `${dayWidth}px` }"
                                :title="`Create booking for ${row.name} on ${date.date}`"
                            >
                                <span class="sr-only"
                                    >Create booking for {{ row.name }} on
                                    {{ date.date }}</span
                                >
                            </Link>
                            <div
                                v-else
                                class="shrink-0 border-r"
                                :class="[
                                    date.is_weekend ? 'bg-muted/25' : '',
                                    date.is_today
                                        ? 'bg-cyan-50/50 dark:bg-cyan-950/30'
                                        : '',
                                ]"
                                :style="{ width: `${dayWidth}px` }"
                            />
                        </template>

                        <template
                            v-for="occupancy in row.occupancies"
                            :key="occupancy.id"
                        >
                            <Link
                                v-if="occupancy.booking_public_id"
                                :href="bookingHref(occupancy.booking_public_id)"
                                class="absolute top-1.5 z-10 flex h-10 items-center overflow-hidden rounded-sm border px-2 text-xs font-semibold shadow-xs focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
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
                                class="absolute top-1.5 z-10 flex h-10 items-center overflow-hidden rounded-sm border px-2 text-xs font-semibold shadow-xs"
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
</template>
