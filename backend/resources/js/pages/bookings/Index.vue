<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowUpDown,
    Bike,
    CalendarClock,
    Check,
    CheckCircle2,
    Clock3,
    Download,
    Eye,
    FileText,
    Plus,
    RotateCcw,
    Search,
    XCircle,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import BookingPagination from '@/components/bookings/BookingPagination.vue';
import BookingStatusBadge from '@/components/bookings/BookingStatusBadge.vue';
import AdminDateInput from '@/components/AdminDateInput.vue';
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
import {
    bookingSourceLabels,
    bookingStatusLabels,
    formatDate,
    formatDateTime,
    formatMoney,
    shortBookingId,
} from '@/lib/bookings';
import type {
    BookingListItem,
    BookingSource,
    BookingStatus,
    PaginatedBookings,
} from '@/types';

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
const { t } = useLocale();
const loading = ref(false);
const quickActionId = ref<string | null>(null);
const quickActionError = ref('');
const cancelTarget = ref<BookingListItem | null>(null);
const cancelForm = useForm({ reason: '' });
const cancelOpen = computed({
    get: () => cancelTarget.value !== null,
    set: (open: boolean) => {
        if (!open) {
            cancelTarget.value = null;
            cancelForm.reset();
            cancelForm.clearErrors();
        }
    },
});
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

const exportHref = computed(
    () =>
        `/bookings/export.csv?${new URLSearchParams(query() as Record<string, string>).toString()}`,
);

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

function listReturnTo(): string {
    return `${window.location.pathname}${window.location.search}`;
}

function approveFromList(booking: BookingListItem): void {
    quickActionError.value = '';
    quickActionId.value = booking.public_id;
    const query = new URLSearchParams({ return_to: listReturnTo() });

    router.post(
        `/bookings/${booking.public_id}/approve?${query}`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                quickActionError.value = t(
                    Object.values(errors)[0] ?? 'Booking could not be updated.',
                );
            },
            onFinish: () => (quickActionId.value = null),
        },
    );
}

function openCancel(booking: BookingListItem): void {
    cancelForm.reset();
    cancelForm.clearErrors();
    cancelTarget.value = booking;
}

function cancelFromList(): void {
    if (!cancelTarget.value) {
        return;
    }

    const query = new URLSearchParams({ return_to: listReturnTo() });
    cancelForm.post(
        `/bookings/${cancelTarget.value.public_id}/cancel?${query}`,
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (cancelOpen.value = false),
        },
    );
}

function canCancel(booking: BookingListItem): boolean {
    return ['pending', 'approved', 'active'].includes(booking.status);
}

function formError(errors: object, key: string): string | undefined {
    return (errors as Record<string, string | undefined>)[key];
}
</script>

<template>
    <Head :title="t('Bookings')" />

    <div class="flex min-w-0 flex-1 flex-col">
        <header
            class="flex flex-col gap-4 border-b px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
        >
            <div>
                <p class="text-sm text-muted-foreground">
                    {{ t('Rental operations') }}
                </p>
                <h1 class="text-2xl font-semibold">{{ t('Bookings') }}</h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline">
                    <a :href="exportHref"><Download />{{ t('Export CSV') }}</a>
                </Button>
                <Button as-child>
                    <Link href="/bookings/create">
                        <Plus />
                        {{ t('New booking') }}
                    </Link>
                </Button>
            </div>
        </header>

        <section class="grid border-b sm:grid-cols-2 xl:grid-cols-4">
            <div
                class="flex items-center gap-3 border-b px-4 py-4 sm:border-r lg:px-6 xl:border-b-0"
            >
                <Clock3 class="size-5 text-amber-600" />
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Pending review') }}
                    </p>
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
                    <p class="text-xs text-muted-foreground">
                        {{ t('Approved') }}
                    </p>
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
                    <p class="text-xs text-muted-foreground">
                        {{ t('Active rentals') }}
                    </p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.active }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3 px-4 py-4 lg:px-6">
                <CalendarClock class="size-5 text-violet-600" />
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Pickups today') }}
                    </p>
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
                        {{ t(scope) }}
                    </button>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ t('Results count', { count: bookings.total }) }}
                </p>
            </div>

            <form
                class="grid gap-3 md:grid-cols-2 xl:grid-cols-6"
                @submit.prevent="applyFilters"
            >
                <label class="relative md:col-span-2">
                    <span class="sr-only">{{ t('Search bookings') }}</span>
                    <Search
                        class="absolute top-2.5 left-3 size-4 text-muted-foreground"
                    />
                    <Input
                        v-model="filters.search"
                        class="pl-9"
                        :placeholder="t('ID, customer, phone or vehicle')"
                    />
                </label>
                <AdminSelect
                    v-model="filters.status"
                    :aria-label="t('Status')"
                    :options="[
                        { value: '', label: t('All statuses') },
                        ...options.statuses.map((status) => ({
                            value: status,
                            label: t(bookingStatusLabels[status]),
                        })),
                    ]"
                />
                <AdminSelect
                    v-model="filters.vehicle_id"
                    :aria-label="t('Vehicle')"
                    :options="[
                        { value: null, label: t('All vehicles') },
                        ...options.vehicles.map((vehicle) => ({
                            value: vehicle.id,
                            label: vehicle.name,
                        })),
                    ]"
                />
                <AdminSelect
                    v-model="filters.source"
                    :aria-label="t('Source')"
                    :options="[
                        { value: '', label: t('All sources') },
                        ...options.sources.map((source) => ({
                            value: source,
                            label: t(bookingSourceLabels[source]),
                        })),
                    ]"
                />
                <AdminSelect
                    v-model="filters.documents"
                    :aria-label="t('Documents')"
                    :options="[
                        { value: '', label: t('Any documents') },
                        { value: 'yes', label: t('Has documents') },
                        { value: 'no', label: t('No documents') },
                    ]"
                />
                <label>
                    <span class="mb-1 block text-xs text-muted-foreground">{{
                        t('From')
                    }}</span>
                    <AdminDateInput v-model="filters.starts_from" />
                </label>
                <label>
                    <span class="mb-1 block text-xs text-muted-foreground">{{
                        t('To')
                    }}</span>
                    <AdminDateInput v-model="filters.starts_to" />
                </label>
                <AdminSelect
                    v-model="filters.vehicle_type"
                    class="self-end"
                    :aria-label="t('Vehicle type')"
                    :options="[
                        { value: '', label: t('All vehicle types') },
                        ...options.vehicle_types.map((type) => ({
                            value: type,
                            label: t(type),
                        })),
                    ]"
                />
                <AdminSelect
                    v-model="filters.per_page"
                    class="self-end"
                    :aria-label="t('Rows per page')"
                    :options="[
                        { value: 15, label: t('Rows count', { count: 15 }) },
                        { value: 25, label: t('Rows count', { count: 25 }) },
                        { value: 50, label: t('Rows count', { count: 50 }) },
                    ]"
                />
                <div class="flex items-end gap-2 xl:col-span-2 xl:justify-end">
                    <Button
                        type="submit"
                        :disabled="loading"
                        class="flex-1 xl:flex-none"
                    >
                        <Search />
                        {{ t('Apply') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        :title="t('Reset filters')"
                        @click="resetFilters"
                    >
                        <RotateCcw />
                        <span class="sr-only">{{ t('Reset filters') }}</span>
                    </Button>
                </div>
            </form>
        </div>

        <section
            class="min-w-0 flex-1"
            :class="loading ? 'opacity-60' : ''"
            aria-live="polite"
        >
            <p
                v-if="quickActionError"
                class="border-b bg-destructive/10 px-4 py-3 text-sm text-destructive sm:px-6 lg:px-8"
            >
                {{ quickActionError }}
            </p>
            <div
                v-if="bookings.data.length === 0"
                class="flex min-h-72 flex-col items-center justify-center px-6 text-center"
            >
                <CalendarClock class="mb-3 size-8 text-muted-foreground" />
                <h2 class="font-medium">{{ t('No bookings found') }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('Change the filters or create a manual booking.') }}
                </p>
                <Button as-child variant="outline" class="mt-4">
                    <Link href="/bookings/create"
                        ><Plus />{{ t('New booking') }}</Link
                    >
                </Button>
            </div>

            <template v-else>
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[1480px] text-sm">
                        <thead
                            class="border-b bg-muted/35 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium lg:px-6">
                                    {{ t('Booking') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    <button
                                        class="flex items-center gap-1 hover:text-foreground"
                                        @click="sortBy('status')"
                                    >
                                        {{ t('Status') }}
                                        <ArrowUpDown class="size-3" />
                                    </button>
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ t('Customer') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    <button
                                        class="flex items-center gap-1 hover:text-foreground"
                                        @click="sortBy('vehicle')"
                                    >
                                        {{ t('Vehicle') }}
                                        <ArrowUpDown class="size-3" />
                                    </button>
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    <button
                                        class="flex items-center gap-1 hover:text-foreground"
                                        @click="sortBy('starts_on')"
                                    >
                                        {{ t('Rental dates') }}
                                        <ArrowUpDown class="size-3" />
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    {{ t('Final price') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ t('Notes') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ t('Source') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    <button
                                        class="flex items-center gap-1 hover:text-foreground"
                                        @click="sortBy('created_at')"
                                    >
                                        {{ t('Records') }}
                                        <ArrowUpDown class="size-3" />
                                    </button>
                                </th>
                                <th
                                    class="sticky right-0 z-10 border-l bg-muted px-4 py-3 text-right font-medium"
                                >
                                    {{ t('Actions') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="booking in bookings.data"
                                :key="booking.public_id"
                                class="group hover:bg-muted/25"
                            >
                                <td class="px-4 py-3 lg:px-6">
                                    <Link
                                        :href="`/bookings/${booking.public_id}`"
                                        class="font-mono text-xs font-semibold hover:underline"
                                    >
                                        #{{ shortBookingId(booking.public_id) }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <BookingStatusBadge
                                        :status="booking.status"
                                    />
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
                                            t('No phone')
                                        }}
                                    </p>
                                    <p
                                        v-if="booking.customer.telegram"
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{ booking.customer.telegram }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium">
                                        {{ booking.vehicle.name }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs text-muted-foreground capitalize"
                                    >
                                        {{ t(booking.vehicle.type) }}
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
                                        {{
                                            t('Days count', {
                                                count: booking.total_days,
                                            })
                                        }}<span v-if="booking.pickup_time">
                                            · {{ booking.pickup_time }}</span
                                        ><span v-if="booking.return_time">
                                            → {{ booking.return_time }}</span
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
                                            >{{ t('Adjusted') }}</span
                                        >
                                        <span
                                            v-if="
                                                Number(
                                                    booking.price
                                                        .calculated_total,
                                                ) !== booking.price.final_total
                                            "
                                            class="block text-xs font-normal text-muted-foreground"
                                        >
                                            {{ t('Calculated') }}
                                            {{
                                                formatMoney(
                                                    booking.price
                                                        .calculated_total,
                                                    booking.price.currency,
                                                )
                                            }}
                                        </span>
                                    </template>
                                    <span v-else>—</span>
                                </td>
                                <td class="max-w-56 px-4 py-3 text-xs">
                                    <p
                                        v-if="booking.client_comment"
                                        class="truncate"
                                        :title="booking.client_comment"
                                    >
                                        {{ t('Client') }}:
                                        {{ booking.client_comment }}
                                    </p>
                                    <p
                                        v-if="booking.admin_note"
                                        class="mt-0.5 truncate text-muted-foreground"
                                        :title="booking.admin_note"
                                    >
                                        {{ t('Internal') }}:
                                        {{ booking.admin_note }}
                                    </p>
                                    <p
                                        v-if="booking.deposit_note"
                                        class="mt-0.5 truncate text-muted-foreground"
                                        :title="booking.deposit_note"
                                    >
                                        {{ t('Payment') }}:
                                        {{ booking.deposit_note }}
                                    </p>
                                    <span
                                        v-if="
                                            !booking.client_comment &&
                                            !booking.admin_note &&
                                            !booking.deposit_note
                                        "
                                        class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ t(bookingSourceLabels[booking.source]) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    <span class="block"
                                        >{{ t('Created') }}
                                        {{
                                            formatDateTime(booking.created_at)
                                        }}</span
                                    >
                                    <span class="mt-0.5 block"
                                        >{{ t('Updated') }}
                                        {{
                                            formatDateTime(booking.updated_at)
                                        }}</span
                                    >
                                    <span class="mt-1 flex items-center gap-1"
                                        ><FileText class="size-3" />{{
                                            t('Documents')
                                        }}:
                                        {{
                                            booking.documents_count
                                                ? t('yes')
                                                : t('no')
                                        }}</span
                                    >
                                </td>
                                <td
                                    class="sticky right-0 border-l bg-background px-4 py-3 group-hover:bg-muted"
                                >
                                    <div class="flex justify-end gap-1">
                                        <Button
                                            as-child
                                            variant="ghost"
                                            size="icon-sm"
                                            :title="t('Open booking')"
                                        >
                                            <Link
                                                :href="`/bookings/${booking.public_id}`"
                                            >
                                                <Eye />
                                                <span class="sr-only">{{
                                                    t('Open')
                                                }}</span>
                                            </Link>
                                        </Button>
                                        <Button
                                            v-if="booking.status === 'pending'"
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            :title="t('Approve booking')"
                                            :disabled="
                                                quickActionId ===
                                                booking.public_id
                                            "
                                            @click="approveFromList(booking)"
                                        >
                                            <Check />
                                            <span class="sr-only">{{
                                                t('Approve')
                                            }}</span>
                                        </Button>
                                        <Button
                                            v-if="canCancel(booking)"
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            :title="t('Cancel booking')"
                                            class="text-destructive"
                                            @click="openCancel(booking)"
                                        >
                                            <XCircle />
                                            <span class="sr-only">{{
                                                t('Cancel')
                                            }}</span>
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="divide-y md:hidden">
                    <article
                        v-for="booking in bookings.data"
                        :key="booking.public_id"
                    >
                        <Link
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
                                    <p
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        #{{ shortBookingId(booking.public_id) }}
                                        ·
                                        {{
                                            t('Days count', {
                                                count: booking.total_days,
                                            })
                                        }}
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
                        <div
                            v-if="
                                booking.status === 'pending' ||
                                canCancel(booking)
                            "
                            class="flex gap-2 px-4 pb-4"
                        >
                            <Button
                                v-if="booking.status === 'pending'"
                                type="button"
                                size="sm"
                                :disabled="quickActionId === booking.public_id"
                                @click="approveFromList(booking)"
                            >
                                <Check />{{ t('Approve') }}
                            </Button>
                            <Button
                                v-if="canCancel(booking)"
                                type="button"
                                size="sm"
                                variant="outline"
                                class="text-destructive"
                                @click="openCancel(booking)"
                            >
                                <XCircle />{{ t('Cancel') }}
                            </Button>
                        </div>
                    </article>
                </div>
                <BookingPagination :paginator="bookings" />
            </template>
        </section>
    </div>

    <Dialog v-model:open="cancelOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ t('Cancel booking') }}</DialogTitle>
                <DialogDescription>
                    {{ cancelTarget?.customer.name }} ·
                    {{ cancelTarget?.vehicle.name }}
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="cancelFromList">
                <div>
                    <Label for="list_cancel_reason">{{ t('Reason') }}</Label>
                    <textarea
                        id="list_cancel_reason"
                        v-model="cancelForm.reason"
                        class="admin-textarea mt-2"
                        rows="4"
                        required
                    />
                    <InputError
                        class="mt-1"
                        :message="
                            cancelForm.errors.reason ||
                            formError(cancelForm.errors, 'action')
                        "
                    />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="cancelOpen = false"
                        >{{ t('Keep booking') }}</Button
                    >
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="cancelForm.processing"
                    >
                        <XCircle />{{ t('Cancel booking') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
