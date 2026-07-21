<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowUpDown,
    Bike,
    CalendarClock,
    CheckCircle2,
    Clock3,
    FileText,
    Plus,
    RotateCcw,
    Search,
} from '@lucide/vue';
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import BookingPagination from '@/components/bookings/BookingPagination.vue';
import BookingStatusBadge from '@/components/bookings/BookingStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    bookingSourceLabels,
    bookingStatusLabels,
    formatDate,
    formatDateTime,
    formatMoney,
    shortBookingId,
} from '@/lib/bookings';
import type { BookingSource, BookingStatus, PaginatedBookings } from '@/types';

type Filters = {
    search: string;
    scope: string;
    status: string;
    source: string;
    vehicle_id: number | null;
    vehicle_type: string;
    starts_from: string;
    starts_to: string;
    documents: string;
    sort: string;
    direction: string;
    per_page: number;
};

const props = defineProps<{
    bookings: PaginatedBookings;
    filters: Filters;
    summary: {
        pending: number;
        approved: number;
        active: number;
        pickups_today: number;
    };
    options: {
        statuses: BookingStatus[];
        sources: BookingSource[];
        vehicle_types: string[];
        vehicles: Array<{ id: number; name: string; type: string }>;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Bookings', href: '/bookings' }],
    },
});

const filters = reactive<Filters>({ ...props.filters });
const loading = ref(false);
let stopStart: (() => void) | undefined;
let stopFinish: (() => void) | undefined;

onMounted(() => {
    stopStart = router.on('start', () => (loading.value = true));
    stopFinish = router.on('finish', () => (loading.value = false));
});

onBeforeUnmount(() => {
    stopStart?.();
    stopFinish?.();
});

function query(): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) =>
                value !== '' && value !== null && value !== undefined,
        ),
    ) as Record<string, string | number>;
}

function applyFilters(): void {
    router.get('/bookings', query(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function setScope(scope: string): void {
    filters.scope = scope;
    filters.status = '';
    applyFilters();
}

function resetFilters(): void {
    Object.assign(filters, {
        search: '',
        scope: 'active',
        status: '',
        source: '',
        vehicle_id: null,
        vehicle_type: '',
        starts_from: '',
        starts_to: '',
        documents: '',
        sort: 'created_at',
        direction: 'desc',
        per_page: 25,
    });
    applyFilters();
}

function sortBy(column: string): void {
    filters.direction =
        filters.sort === column && filters.direction === 'desc'
            ? 'asc'
            : 'desc';
    filters.sort = column;
    applyFilters();
}
</script>

<template>
    <Head title="Bookings" />

    <div class="flex min-w-0 flex-1 flex-col">
        <header
            class="flex flex-col gap-4 border-b px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
        >
            <div>
                <p class="text-sm text-muted-foreground">Rental operations</p>
                <h1 class="text-2xl font-semibold">Bookings</h1>
            </div>
            <Button as-child>
                <Link href="/bookings/create">
                    <Plus />
                    New booking
                </Link>
            </Button>
        </header>

        <section class="grid border-b sm:grid-cols-2 xl:grid-cols-4">
            <div
                class="flex items-center gap-3 border-b px-4 py-4 sm:border-r lg:px-6 xl:border-b-0"
            >
                <Clock3 class="size-5 text-amber-600" />
                <div>
                    <p class="text-xs text-muted-foreground">Pending review</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.pending }}
                    </p>
                </div>
            </div>
            <div
                class="flex items-center gap-3 border-b px-4 py-4 lg:px-6 xl:border-r xl:border-b-0"
            >
                <CheckCircle2 class="size-5 text-emerald-600" />
                <div>
                    <p class="text-xs text-muted-foreground">Approved</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.approved }}
                    </p>
                </div>
            </div>
            <div
                class="flex items-center gap-3 border-b px-4 py-4 sm:border-r sm:border-b-0 lg:px-6"
            >
                <Bike class="size-5 text-cyan-600" />
                <div>
                    <p class="text-xs text-muted-foreground">Active rentals</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.active }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3 px-4 py-4 lg:px-6">
                <CalendarClock class="size-5 text-violet-600" />
                <div>
                    <p class="text-xs text-muted-foreground">Pickups today</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.pickups_today }}
                    </p>
                </div>
            </div>
        </section>

        <div class="border-b px-4 py-4 sm:px-6 lg:px-8">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div
                    class="inline-flex rounded-md border bg-muted/30 p-0.5"
                    role="group"
                >
                    <button
                        v-for="scope in ['active', 'all', 'archive']"
                        :key="scope"
                        type="button"
                        class="h-8 rounded px-3 text-sm font-medium capitalize transition-colors"
                        :class="
                            filters.scope === scope
                                ? 'bg-background shadow-xs'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="setScope(scope)"
                    >
                        {{ scope }}
                    </button>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ bookings.total }} result{{
                        bookings.total === 1 ? '' : 's'
                    }}
                </p>
            </div>

            <form
                class="grid gap-3 md:grid-cols-2 xl:grid-cols-6"
                @submit.prevent="applyFilters"
            >
                <label class="relative md:col-span-2">
                    <span class="sr-only">Search bookings</span>
                    <Search
                        class="absolute top-2.5 left-3 size-4 text-muted-foreground"
                    />
                    <Input
                        v-model="filters.search"
                        class="pl-9"
                        placeholder="ID, customer, phone or vehicle"
                    />
                </label>
                <select
                    v-model="filters.status"
                    class="admin-select"
                    aria-label="Status"
                >
                    <option value="">All statuses</option>
                    <option
                        v-for="status in options.statuses"
                        :key="status"
                        :value="status"
                    >
                        {{ bookingStatusLabels[status] }}
                    </option>
                </select>
                <select
                    v-model.number="filters.vehicle_id"
                    class="admin-select"
                    aria-label="Vehicle"
                >
                    <option :value="null">All vehicles</option>
                    <option
                        v-for="vehicle in options.vehicles"
                        :key="vehicle.id"
                        :value="vehicle.id"
                    >
                        {{ vehicle.name }}
                    </option>
                </select>
                <select
                    v-model="filters.source"
                    class="admin-select"
                    aria-label="Source"
                >
                    <option value="">All sources</option>
                    <option
                        v-for="source in options.sources"
                        :key="source"
                        :value="source"
                    >
                        {{ bookingSourceLabels[source] }}
                    </option>
                </select>
                <select
                    v-model="filters.documents"
                    class="admin-select"
                    aria-label="Documents"
                >
                    <option value="">Any documents</option>
                    <option value="yes">Has documents</option>
                    <option value="no">No documents</option>
                </select>
                <label>
                    <span class="mb-1 block text-xs text-muted-foreground"
                        >From</span
                    >
                    <Input v-model="filters.starts_from" type="date" />
                </label>
                <label>
                    <span class="mb-1 block text-xs text-muted-foreground"
                        >To</span
                    >
                    <Input v-model="filters.starts_to" type="date" />
                </label>
                <select
                    v-model="filters.vehicle_type"
                    class="admin-select self-end"
                    aria-label="Vehicle type"
                >
                    <option value="">All vehicle types</option>
                    <option
                        v-for="type in options.vehicle_types"
                        :key="type"
                        :value="type"
                    >
                        {{ type }}
                    </option>
                </select>
                <select
                    v-model.number="filters.per_page"
                    class="admin-select self-end"
                    aria-label="Rows per page"
                >
                    <option :value="15">15 rows</option>
                    <option :value="25">25 rows</option>
                    <option :value="50">50 rows</option>
                </select>
                <div class="flex items-end gap-2 xl:col-span-2 xl:justify-end">
                    <Button
                        type="submit"
                        :disabled="loading"
                        class="flex-1 xl:flex-none"
                    >
                        <Search />
                        Apply
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        title="Reset filters"
                        @click="resetFilters"
                    >
                        <RotateCcw />
                        <span class="sr-only">Reset filters</span>
                    </Button>
                </div>
            </form>
        </div>

        <section
            class="min-w-0 flex-1"
            :class="loading ? 'opacity-60' : ''"
            aria-live="polite"
        >
            <div
                v-if="bookings.data.length === 0"
                class="flex min-h-72 flex-col items-center justify-center px-6 text-center"
            >
                <CalendarClock class="mb-3 size-8 text-muted-foreground" />
                <h2 class="font-medium">No bookings found</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Change the filters or create a manual booking.
                </p>
                <Button as-child variant="outline" class="mt-4">
                    <Link href="/bookings/create"><Plus />New booking</Link>
                </Button>
            </div>

            <template v-else>
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[1080px] text-sm">
                        <thead
                            class="border-b bg-muted/35 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium lg:px-6">
                                    Booking
                                </th>
                                <th class="px-4 py-3 font-medium">Customer</th>
                                <th class="px-4 py-3 font-medium">Vehicle</th>
                                <th class="px-4 py-3 font-medium">
                                    <button
                                        class="flex items-center gap-1 hover:text-foreground"
                                        @click="sortBy('starts_on')"
                                    >
                                        Rental dates
                                        <ArrowUpDown class="size-3" />
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Final price
                                </th>
                                <th class="px-4 py-3 font-medium">Source</th>
                                <th class="px-4 py-3 font-medium">
                                    <button
                                        class="flex items-center gap-1 hover:text-foreground"
                                        @click="sortBy('updated_at')"
                                    >
                                        Updated <ArrowUpDown class="size-3" />
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="booking in bookings.data"
                                :key="booking.public_id"
                                class="hover:bg-muted/25"
                            >
                                <td class="px-4 py-3 lg:px-6">
                                    <Link
                                        :href="`/bookings/${booking.public_id}`"
                                        class="font-mono text-xs font-semibold hover:underline"
                                    >
                                        #{{ shortBookingId(booking.public_id) }}
                                    </Link>
                                    <div class="mt-1.5">
                                        <BookingStatusBadge
                                            :status="booking.status"
                                        />
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium">
                                        {{ booking.customer.name }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{
                                            booking.customer.phone ||
                                            booking.customer.telegram ||
                                            'No contact'
                                        }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium">
                                        {{ booking.vehicle.name }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs text-muted-foreground capitalize"
                                    >
                                        {{ booking.vehicle.type }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    <p>
                                        {{ formatDate(booking.starts_on) }} –
                                        {{ formatDate(booking.ends_on) }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{ booking.total_days }} days<span
                                            v-if="booking.pickup_time"
                                        >
                                            · {{ booking.pickup_time }}</span
                                        >
                                    </p>
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-medium tabular-nums"
                                >
                                    <template v-if="booking.price">
                                        {{
                                            formatMoney(
                                                booking.price.final_total,
                                                booking.price.currency,
                                            )
                                        }}
                                        <span
                                            v-if="
                                                booking.price.pricing_source ===
                                                'manual_override'
                                            "
                                            class="block text-xs font-normal text-amber-700"
                                            >Adjusted</span
                                        >
                                    </template>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ bookingSourceLabels[booking.source] }}
                                </td>
                                <td
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    {{ formatDateTime(booking.updated_at) }}
                                    <span
                                        v-if="booking.documents_count"
                                        class="mt-1 flex items-center gap-1"
                                        ><FileText class="size-3" />{{
                                            booking.documents_count
                                        }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="divide-y md:hidden">
                    <Link
                        v-for="booking in bookings.data"
                        :key="booking.public_id"
                        :href="`/bookings/${booking.public_id}`"
                        class="block px-4 py-4 hover:bg-muted/30"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ booking.customer.name }}
                                </p>
                                <p
                                    class="mt-0.5 truncate text-sm text-muted-foreground"
                                >
                                    {{ booking.vehicle.name }}
                                </p>
                            </div>
                            <BookingStatusBadge :status="booking.status" />
                        </div>
                        <div
                            class="mt-3 flex items-end justify-between gap-3 text-sm"
                        >
                            <div>
                                <p>
                                    {{ formatDate(booking.starts_on) }} –
                                    {{ formatDate(booking.ends_on) }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    #{{ shortBookingId(booking.public_id) }} ·
                                    {{ booking.total_days }} days
                                </p>
                            </div>
                            <p class="shrink-0 font-semibold tabular-nums">
                                {{
                                    booking.price
                                        ? formatMoney(
                                              booking.price.final_total,
                                              booking.price.currency,
                                          )
                                        : '—'
                                }}
                            </p>
                        </div>
                    </Link>
                </div>
                <BookingPagination :paginator="bookings" />
            </template>
        </section>
    </div>
</template>
