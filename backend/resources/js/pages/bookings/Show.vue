<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Banknote,
    CalendarRange,
    Check,
    ClipboardList,
    Download,
    FileText,
    FilePlus2,
    Flag,
    History,
    MessageSquareText,
    Play,
    RefreshCw,
    Save,
    SquareCheckBig,
    Trash2,
    UserRound,
    XCircle,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import BookingStatusBadge from '@/components/bookings/BookingStatusBadge.vue';
import PriceBreakdown from '@/components/bookings/PriceBreakdown.vue';
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
import {
    bookingSourceLabels,
    bookingStatusLabels,
    formatDate,
    formatDateTime,
    formatMoney,
    shortBookingId,
} from '@/lib/bookings';
import type { BookingDetail, PriceQuote } from '@/types';

const props = defineProps<{
    booking: BookingDetail;
    return_to: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Bookings', href: '/bookings' }],
    },
});

const currentPrice = computed(() => props.booking.price_snapshots[0] ?? null);
const backHref = computed(() => props.return_to ?? '/bookings');

const actionForm = useForm({});
const datesOpen = ref(false);
const priceOpen = ref(false);
const cancelOpen = ref(false);
const noShowOpen = ref(false);

const datesForm = useForm({
    starts_on: props.booking.starts_on,
    ends_on: props.booking.ends_on,
    pickup_time: props.booking.pickup_time ?? '',
    return_time: props.booking.return_time ?? '',
});
const priceForm = useForm({
    manual_total: currentPrice.value?.final_total ?? 0,
    reason: '',
});
const cancelForm = useForm({ reason: '' });
const noShowForm = useForm({ reason: '' });
const documentInput = ref<HTMLInputElement | null>(null);
const documentForm = useForm<{ document: File | null; type: string }>({
    document: null,
    type: 'passport',
});

const dateQuote = ref<PriceQuote | null>(null);
const dateAvailable = ref<boolean | null>(null);
const dateQuoteError = ref('');
const dateQuoteLoading = ref(false);
let quoteTimer: ReturnType<typeof setTimeout> | undefined;
let quoteRequest: AbortController | undefined;

function domainError(errors: object, key: string): string | undefined {
    return (errors as Record<string, string | undefined>)[key];
}

watch(
    () => [datesForm.starts_on, datesForm.ends_on, datesOpen.value],
    () => {
        clearTimeout(quoteTimer);
        quoteRequest?.abort();
        dateQuote.value = null;
        dateAvailable.value = null;
        dateQuoteError.value = '';

        if (!datesOpen.value || !datesForm.starts_on || !datesForm.ends_on) {
            return;
        }

        quoteTimer = setTimeout(loadDateQuote, 250);
    },
);

onBeforeUnmount(() => {
    clearTimeout(quoteTimer);
    quoteRequest?.abort();
});

async function loadDateQuote(): Promise<void> {
    quoteRequest?.abort();
    const request = new AbortController();
    quoteRequest = request;
    dateQuoteLoading.value = true;
    dateQuoteError.value = '';

    try {
        const params = new URLSearchParams({
            starts_on: datesForm.starts_on,
            ends_on: datesForm.ends_on,
        });
        const response = await fetch(
            `/bookings/${props.booking.public_id}/quote?${params}`,
            {
                headers: { Accept: 'application/json' },
                signal: request.signal,
            },
        );
        const payload = await response.json();

        if (!response.ok) {
            dateQuoteError.value =
                payload.error?.message || 'Price could not be calculated.';

            return;
        }

        dateQuote.value = payload.quote;
        dateAvailable.value = payload.available;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            dateQuoteError.value = 'Price preview is temporarily unavailable.';
        }
    } finally {
        if (quoteRequest === request) {
            dateQuoteLoading.value = false;
        }
    }
}

function runAction(action: 'approve' | 'activate' | 'complete'): void {
    actionForm.post(
        withReturnTo(`/bookings/${props.booking.public_id}/${action}`),
        {
            preserveScroll: true,
        },
    );
}

function updateDates(): void {
    datesForm.patch(
        withReturnTo(`/bookings/${props.booking.public_id}/dates`),
        {
            preserveScroll: true,
            onSuccess: () => (datesOpen.value = false),
        },
    );
}

function updatePrice(): void {
    priceForm.post(
        withReturnTo(`/bookings/${props.booking.public_id}/price-overrides`),
        {
            preserveScroll: true,
            onSuccess: () => {
                priceOpen.value = false;
                priceForm.reason = '';
            },
        },
    );
}

function cancelBooking(): void {
    cancelForm.post(
        withReturnTo(`/bookings/${props.booking.public_id}/cancel`),
        {
            preserveScroll: true,
            onSuccess: () => (cancelOpen.value = false),
        },
    );
}

function markNoShow(): void {
    noShowForm.post(
        withReturnTo(`/bookings/${props.booking.public_id}/no-show`),
        {
            preserveScroll: true,
            onSuccess: () => (noShowOpen.value = false),
        },
    );
}

function withReturnTo(path: string): string {
    if (!props.return_to) {
        return path;
    }

    return `${path}?${new URLSearchParams({ return_to: props.return_to })}`;
}

function selectDocument(event: Event): void {
    documentForm.document =
        (event.target as HTMLInputElement).files?.[0] ?? null;
}

function uploadDocument(): void {
    documentForm.post(`/bookings/${props.booking.public_id}/documents`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            documentForm.reset();

            if (documentInput.value) {
                documentInput.value.value = '';
            }
        },
    });
}

function deleteDocument(id: number): void {
    if (!window.confirm('Delete this private document?')) {
        return;
    }

    router.delete(`/documents/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Booking ${shortBookingId(booking.public_id)}`" />

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="border-b px-4 py-5 sm:px-6 lg:px-8">
            <div
                class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <Button
                        as-child
                        variant="ghost"
                        size="icon"
                        :title="
                            return_to ? 'Back to timeline' : 'Back to bookings'
                        "
                    >
                        <Link :href="backHref"
                            ><ArrowLeft /><span class="sr-only"
                                >Back</span
                            ></Link
                        >
                    </Button>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="font-mono text-xl font-semibold">
                                #{{ shortBookingId(booking.public_id) }}
                            </h1>
                            <BookingStatusBadge :status="booking.status" />
                        </div>
                        <p class="mt-1 truncate text-sm text-muted-foreground">
                            {{ booking.customer.name }} ·
                            {{ booking.vehicle.name }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="booking.actions.approve"
                        :disabled="actionForm.processing"
                        @click="runAction('approve')"
                    >
                        <Check />Approve
                    </Button>
                    <Button
                        v-if="booking.actions.activate"
                        :disabled="actionForm.processing"
                        @click="runAction('activate')"
                    >
                        <Play />Start rental
                    </Button>
                    <Button
                        v-if="booking.actions.complete"
                        :disabled="actionForm.processing"
                        @click="runAction('complete')"
                    >
                        <SquareCheckBig />Complete
                    </Button>
                    <Button
                        v-if="booking.actions.change_dates"
                        variant="outline"
                        @click="datesOpen = true"
                    >
                        <CalendarRange />Dates
                    </Button>
                    <Button
                        v-if="booking.actions.override_price"
                        variant="outline"
                        @click="priceOpen = true"
                    >
                        <Banknote />Price
                    </Button>
                    <Button
                        v-if="booking.actions.no_show"
                        variant="outline"
                        @click="noShowOpen = true"
                    >
                        <Flag />No-show
                    </Button>
                    <Button
                        v-if="booking.actions.cancel"
                        variant="destructive"
                        @click="cancelOpen = true"
                    >
                        <XCircle />Cancel
                    </Button>
                </div>
            </div>
            <p
                v-if="domainError(actionForm.errors, 'action')"
                class="mt-4 text-sm text-destructive"
            >
                {{ domainError(actionForm.errors, 'action') }}
            </p>
        </header>

        <div class="grid min-w-0 xl:grid-cols-[minmax(0,1fr)_340px]">
            <main class="min-w-0 divide-y">
                <section
                    class="px-4 py-6 sm:px-6 lg:px-8"
                    aria-labelledby="rental-details-heading"
                >
                    <div class="mb-5 flex items-center gap-2">
                        <ClipboardList class="size-5 text-muted-foreground" />
                        <h2 id="rental-details-heading" class="font-semibold">
                            Rental details
                        </h2>
                    </div>
                    <dl
                        class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Vehicle
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ booking.vehicle.name }}
                            </dd>
                            <dd
                                class="text-sm text-muted-foreground capitalize"
                            >
                                {{ booking.vehicle.type
                                }}<span v-if="booking.vehicle.inventory_code">
                                    · {{ booking.vehicle.inventory_code }}</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Rental period
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ formatDate(booking.starts_on) }} –
                                {{ formatDate(booking.ends_on) }}
                            </dd>
                            <dd class="text-sm text-muted-foreground">
                                {{ booking.total_days }} calendar days
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Time</dt>
                            <dd class="mt-1 font-medium">
                                {{ booking.pickup_time || 'Not set'
                                }}<span v-if="booking.return_time">
                                    – {{ booking.return_time }}</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Source
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ bookingSourceLabels[booking.source] }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Created
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ formatDateTime(booking.created_at) }}
                            </dd>
                            <dd
                                v-if="booking.created_by_admin"
                                class="text-sm text-muted-foreground"
                            >
                                by {{ booking.created_by_admin.name }}
                            </dd>
                        </div>
                        <div v-if="booking.pending_expires_at">
                            <dt class="text-xs text-muted-foreground">
                                Pending expires
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ formatDateTime(booking.pending_expires_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section
                    class="px-4 py-6 sm:px-6 lg:px-8"
                    aria-labelledby="price-details-heading"
                >
                    <div class="mb-5 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-2">
                            <Banknote class="size-5 text-muted-foreground" />
                            <h2
                                id="price-details-heading"
                                class="font-semibold"
                            >
                                Price
                            </h2>
                        </div>
                        <span
                            v-if="currentPrice"
                            class="text-xs text-muted-foreground"
                            >Version {{ currentPrice.version }}</span
                        >
                    </div>
                    <div v-if="currentPrice" class="space-y-5">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <p class="text-xs text-muted-foreground">
                                    Calculated
                                </p>
                                <p
                                    class="mt-1 text-lg font-medium tabular-nums"
                                >
                                    {{
                                        formatMoney(
                                            currentPrice.calculated_total,
                                            currentPrice.currency,
                                        )
                                    }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">
                                    Final total
                                </p>
                                <p
                                    class="mt-1 text-2xl font-semibold tabular-nums"
                                >
                                    {{
                                        formatMoney(
                                            currentPrice.final_total,
                                            currentPrice.currency,
                                        )
                                    }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">
                                    Rate tier
                                </p>
                                <p class="mt-1 font-medium">
                                    {{ currentPrice.tier_key }} ·
                                    {{ currentPrice.total_days }} days
                                </p>
                                <p
                                    v-if="
                                        currentPrice.pricing_source ===
                                        'manual_override'
                                    "
                                    class="text-sm text-amber-700"
                                >
                                    Manual adjustment
                                </p>
                            </div>
                        </div>
                        <PriceBreakdown
                            :items="currentPrice.breakdown"
                            :currency="currentPrice.currency"
                        />
                        <div
                            v-if="currentPrice.override_reason"
                            class="border-l-2 border-amber-400 pl-3 text-sm"
                        >
                            <p class="font-medium">Adjustment reason</p>
                            <p class="mt-1 text-muted-foreground">
                                {{ currentPrice.override_reason }}
                            </p>
                        </div>
                        <details
                            v-if="booking.price_snapshots.length > 1"
                            class="text-sm"
                        >
                            <summary class="cursor-pointer font-medium">
                                Previous price versions ({{
                                    booking.price_snapshots.length - 1
                                }})
                            </summary>
                            <div class="mt-3 divide-y rounded-md border">
                                <div
                                    v-for="snapshot in booking.price_snapshots.slice(
                                        1,
                                    )"
                                    :key="snapshot.version"
                                    class="flex items-center justify-between gap-3 px-3 py-2"
                                >
                                    <span
                                        >Version {{ snapshot.version }} ·
                                        {{
                                            formatDateTime(
                                                snapshot.calculated_at,
                                            )
                                        }}</span
                                    >
                                    <span class="font-medium tabular-nums">{{
                                        formatMoney(
                                            snapshot.final_total,
                                            snapshot.currency,
                                        )
                                    }}</span>
                                </div>
                            </div>
                        </details>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        No price snapshot.
                    </p>
                </section>

                <section
                    class="px-4 py-6 sm:px-6 lg:px-8"
                    aria-labelledby="history-heading"
                >
                    <div class="mb-5 flex items-center gap-2">
                        <History class="size-5 text-muted-foreground" />
                        <h2 id="history-heading" class="font-semibold">
                            Status history
                        </h2>
                    </div>
                    <ol class="space-y-4">
                        <li
                            v-for="item in booking.status_history"
                            :key="item.id"
                            class="grid grid-cols-[12px_minmax(0,1fr)] gap-3"
                        >
                            <span
                                class="mt-1.5 size-2 rounded-full bg-foreground"
                            />
                            <div class="min-w-0 border-b pb-4 last:border-b-0">
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <p class="font-medium">
                                        {{
                                            item.from_status
                                                ? `${bookingStatusLabels[item.from_status]} → `
                                                : ''
                                        }}{{
                                            bookingStatusLabels[item.to_status]
                                        }}
                                    </p>
                                    <time
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            formatDateTime(item.created_at)
                                        }}</time
                                    >
                                </div>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ item.actor_name || item.actor_type }}
                                </p>
                                <p v-if="item.reason" class="mt-2 text-sm">
                                    {{ item.reason }}
                                </p>
                            </div>
                        </li>
                    </ol>
                </section>
            </main>

            <aside
                class="divide-y border-t bg-muted/15 xl:border-t-0 xl:border-l"
            >
                <section
                    class="px-4 py-6 sm:px-6"
                    aria-labelledby="customer-heading"
                >
                    <div class="mb-4 flex items-center gap-2">
                        <UserRound class="size-5 text-muted-foreground" />
                        <h2 id="customer-heading" class="font-semibold">
                            Customer
                        </h2>
                    </div>
                    <Link
                        :href="`/customers/${booking.customer.id}`"
                        class="font-medium hover:underline"
                        >{{ booking.customer.name }}</Link
                    >
                    <a
                        v-if="booking.customer.phone"
                        :href="`tel:${booking.customer.phone}`"
                        class="mt-2 block text-sm hover:underline"
                        >{{ booking.customer.phone }}</a
                    >
                    <p
                        v-if="booking.customer.telegram"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        {{ booking.customer.telegram }}
                    </p>
                </section>

                <section
                    class="px-4 py-6 sm:px-6"
                    aria-labelledby="notes-heading"
                >
                    <div class="mb-4 flex items-center gap-2">
                        <MessageSquareText
                            class="size-5 text-muted-foreground"
                        />
                        <h2 id="notes-heading" class="font-semibold">Notes</h2>
                    </div>
                    <dl class="space-y-4 text-sm">
                        <div v-if="booking.client_comment">
                            <dt class="text-xs text-muted-foreground">
                                Customer
                            </dt>
                            <dd class="mt-1 whitespace-pre-wrap">
                                {{ booking.client_comment }}
                            </dd>
                        </div>
                        <div v-if="booking.admin_note">
                            <dt class="text-xs text-muted-foreground">
                                Internal
                            </dt>
                            <dd class="mt-1 whitespace-pre-wrap">
                                {{ booking.admin_note }}
                            </dd>
                        </div>
                        <div v-if="booking.deposit_note">
                            <dt class="text-xs text-muted-foreground">
                                Payment / deposit
                            </dt>
                            <dd class="mt-1 whitespace-pre-wrap">
                                {{ booking.deposit_note }}
                            </dd>
                        </div>
                        <div
                            v-if="
                                booking.cancellation_reason ||
                                booking.no_show_reason
                            "
                        >
                            <dt class="text-xs text-muted-foreground">
                                Closure reason
                            </dt>
                            <dd class="mt-1 whitespace-pre-wrap">
                                {{
                                    booking.cancellation_reason ||
                                    booking.no_show_reason
                                }}
                            </dd>
                        </div>
                        <p
                            v-if="
                                !booking.client_comment &&
                                !booking.admin_note &&
                                !booking.deposit_note
                            "
                            class="text-muted-foreground"
                        >
                            No notes.
                        </p>
                    </dl>
                </section>

                <section
                    class="px-4 py-6 sm:px-6"
                    aria-labelledby="documents-heading"
                >
                    <div class="mb-4 flex items-center gap-2">
                        <FileText class="size-5 text-muted-foreground" />
                        <h2 id="documents-heading" class="font-semibold">
                            Documents
                        </h2>
                    </div>
                    <form class="space-y-3" @submit.prevent="uploadDocument">
                        <select
                            v-model="documentForm.type"
                            class="admin-select w-full"
                            aria-label="Document type"
                        >
                            <option value="passport">Passport</option>
                            <option value="driver_license">
                                Driver license
                            </option>
                            <option value="photo">Photo</option>
                            <option value="other">Other</option>
                        </select>
                        <Input
                            ref="documentInput"
                            type="file"
                            accept="application/pdf,image/jpeg,image/png,image/webp"
                            @change="selectDocument"
                        />
                        <InputError :message="documentForm.errors.document" />
                        <Button
                            type="submit"
                            variant="outline"
                            class="w-full"
                            :disabled="
                                !documentForm.document ||
                                documentForm.processing
                            "
                            ><FilePlus2 />Upload document</Button
                        >
                    </form>
                    <ul
                        v-if="booking.documents.length"
                        class="mt-4 divide-y border-t text-sm"
                    >
                        <li
                            v-for="document in booking.documents"
                            :key="document.id"
                            class="flex items-center justify-between gap-2 py-2"
                        >
                            <span class="min-w-0">
                                <span class="block truncate">{{
                                    document.filename
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground capitalize"
                                    >{{ document.type.replace('_', ' ') }}</span
                                >
                            </span>
                            <span class="flex shrink-0">
                                <Button
                                    as-child
                                    variant="ghost"
                                    size="icon-sm"
                                    title="Download document"
                                    ><a :href="document.download_url"
                                        ><Download /><span class="sr-only"
                                            >Download</span
                                        ></a
                                    ></Button
                                >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    title="Delete document"
                                    class="text-destructive"
                                    @click="deleteDocument(document.id)"
                                    ><Trash2 /><span class="sr-only"
                                        >Delete</span
                                    ></Button
                                >
                            </span>
                        </li>
                    </ul>
                    <p v-else class="mt-4 text-sm text-muted-foreground">
                        No documents.
                    </p>
                </section>
            </aside>
        </div>

        <Dialog v-model:open="datesOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Change rental dates</DialogTitle>
                    <DialogDescription
                        >Availability and price will be checked again before
                        saving.</DialogDescription
                    >
                </DialogHeader>
                <form class="space-y-5" @submit.prevent="updateDates">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label for="edit_starts_on">Start date</Label
                            ><Input
                                id="edit_starts_on"
                                v-model="datesForm.starts_on"
                                type="date"
                                class="mt-2"
                            />
                        </div>
                        <div>
                            <Label for="edit_ends_on">End date</Label
                            ><Input
                                id="edit_ends_on"
                                v-model="datesForm.ends_on"
                                type="date"
                                class="mt-2"
                            />
                        </div>
                        <div>
                            <Label for="edit_pickup_time">Pickup time</Label
                            ><Input
                                id="edit_pickup_time"
                                v-model="datesForm.pickup_time"
                                type="time"
                                class="mt-2"
                            />
                        </div>
                        <div>
                            <Label for="edit_return_time">Return time</Label
                            ><Input
                                id="edit_return_time"
                                v-model="datesForm.return_time"
                                type="time"
                                class="mt-2"
                            />
                        </div>
                    </div>
                    <InputError
                        :message="domainError(datesForm.errors, 'dates')"
                    />
                    <div
                        v-if="dateQuoteLoading"
                        class="text-sm text-muted-foreground"
                    >
                        Calculating…
                    </div>
                    <div
                        v-else-if="dateQuoteError"
                        class="flex items-center justify-between gap-3 rounded-md border border-destructive/30 p-3 text-sm text-destructive"
                    >
                        <span class="flex gap-2">
                            <AlertTriangle class="size-4 shrink-0" />{{
                                dateQuoteError
                            }}
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="loadDateQuote"
                        >
                            <RefreshCw />Retry
                        </Button>
                    </div>
                    <div v-else-if="dateQuote" class="space-y-3">
                        <div
                            class="flex items-center justify-between border-y py-3"
                        >
                            <span
                                :class="
                                    dateAvailable
                                        ? 'text-emerald-700'
                                        : 'text-destructive'
                                "
                                >{{
                                    dateAvailable
                                        ? 'Vehicle available'
                                        : 'Dates occupied'
                                }}</span
                            >
                            <strong class="tabular-nums">{{
                                formatMoney(
                                    dateQuote.final_total,
                                    dateQuote.currency,
                                )
                            }}</strong>
                        </div>
                        <PriceBreakdown
                            :items="dateQuote.breakdown"
                            :currency="dateQuote.currency"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="datesOpen = false"
                            >Close</Button
                        >
                        <Button
                            type="submit"
                            :disabled="
                                datesForm.processing || dateAvailable === false
                            "
                            ><Save />Save dates</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="priceOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Set final price</DialogTitle>
                    <DialogDescription
                        >The automatic calculation remains in
                        history.</DialogDescription
                    >
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="updatePrice">
                    <div>
                        <Label for="manual_total">Final total, THB</Label
                        ><Input
                            id="manual_total"
                            v-model="priceForm.manual_total"
                            type="number"
                            min="0"
                            step="100"
                            class="mt-2"
                        /><InputError
                            class="mt-1"
                            :message="priceForm.errors.manual_total"
                        />
                    </div>
                    <div>
                        <Label for="price_reason">Reason</Label
                        ><textarea
                            id="price_reason"
                            v-model="priceForm.reason"
                            class="admin-textarea mt-2"
                            rows="4"
                        /><InputError
                            class="mt-1"
                            :message="
                                priceForm.errors.reason ||
                                domainError(priceForm.errors, 'price')
                            "
                        />
                    </div>
                    <DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            @click="priceOpen = false"
                            >Close</Button
                        ><Button type="submit" :disabled="priceForm.processing"
                            ><Save />Save price</Button
                        ></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="cancelOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>Cancel booking</DialogTitle
                    ><DialogDescription
                        >The vehicle will be released immediately. This action
                        is recorded in audit history.</DialogDescription
                    ></DialogHeader
                >
                <form class="space-y-4" @submit.prevent="cancelBooking">
                    <div>
                        <Label for="cancel_reason">Reason</Label
                        ><textarea
                            id="cancel_reason"
                            v-model="cancelForm.reason"
                            class="admin-textarea mt-2"
                            rows="4"
                        /><InputError
                            class="mt-1"
                            :message="
                                cancelForm.errors.reason ||
                                domainError(cancelForm.errors, 'action')
                            "
                        />
                    </div>
                    <DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            @click="cancelOpen = false"
                            >Keep booking</Button
                        ><Button
                            type="submit"
                            variant="destructive"
                            :disabled="cancelForm.processing"
                            ><XCircle />Cancel booking</Button
                        ></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="noShowOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>Mark as no-show</DialogTitle
                    ><DialogDescription
                        >The booking must be approved and its pickup time must
                        have passed.</DialogDescription
                    ></DialogHeader
                >
                <form class="space-y-4" @submit.prevent="markNoShow">
                    <div>
                        <Label for="no_show_reason">Reason (optional)</Label
                        ><textarea
                            id="no_show_reason"
                            v-model="noShowForm.reason"
                            class="admin-textarea mt-2"
                            rows="3"
                        /><InputError
                            class="mt-1"
                            :message="
                                noShowForm.errors.reason ||
                                domainError(noShowForm.errors, 'action')
                            "
                        />
                    </div>
                    <DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            @click="noShowOpen = false"
                            >Close</Button
                        ><Button type="submit" :disabled="noShowForm.processing"
                            ><Flag />Confirm no-show</Button
                        ></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
