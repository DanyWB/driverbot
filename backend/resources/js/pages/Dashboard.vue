<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Bike,
    CalendarClock,
    CheckCircle2,
    Clock3,
    Plus,
} from '@lucide/vue';
import BookingStatusBadge from '@/components/bookings/BookingStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import { formatDate, formatMoney, shortBookingId } from '@/lib/bookings';
import { dashboard } from '@/routes';
import type { BookingListItem } from '@/types';

defineProps<{
    summary: {
        pending: number;
        pickups_today: number;
        active: number;
        available_vehicles: number;
        active_vehicles: number;
    };
    bookings: BookingListItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const { t } = useLocale();
</script>

<template>
    <Head :title="t('Dashboard')" />

    <div class="flex min-w-0 flex-1 flex-col">
        <header
            class="flex flex-col gap-4 border-b px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
        >
            <div>
                <p class="text-sm text-muted-foreground">Drive Phangan</p>
                <h1 class="text-2xl font-semibold">{{ t('Operations') }}</h1>
            </div>
            <Button as-child>
                <Link href="/bookings/create"
                    ><Plus />{{ t('New booking') }}</Link
                >
            </Button>
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
            <div
                class="flex items-center gap-3 border-b px-4 py-4 sm:border-r sm:border-b-0 lg:px-6"
            >
                <CheckCircle2 class="size-5 text-cyan-600" />
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
                <Bike class="size-5 text-emerald-600" />
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Available today') }}
                    </p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.available_vehicles }}
                        <span class="text-sm font-normal text-muted-foreground"
                            >/ {{ summary.active_vehicles }}</span
                        >
                    </p>
                </div>
            </div>
        </section>

        <section
            class="min-w-0 px-4 py-6 sm:px-6 lg:px-8"
            aria-labelledby="current-bookings-heading"
        >
            <div class="mb-4 flex items-center justify-between gap-4">
                <div>
                    <h2 id="current-bookings-heading" class="font-semibold">
                        {{ t('Current bookings') }}
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ t('Pending requests and upcoming rentals.') }}
                    </p>
                </div>
                <Button as-child variant="ghost" size="sm">
                    <Link href="/bookings"
                        >{{ t('View all') }} <ArrowRight
                    /></Link>
                </Button>
            </div>

            <div v-if="bookings.length" class="divide-y rounded-md border">
                <Link
                    v-for="booking in bookings"
                    :key="booking.public_id"
                    :href="`/bookings/${booking.public_id}`"
                    class="grid gap-3 px-4 py-3 hover:bg-muted/30 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto_auto] sm:items-center"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ booking.customer.name }}
                        </p>
                        <p
                            class="mt-0.5 truncate text-xs text-muted-foreground"
                        >
                            #{{ shortBookingId(booking.public_id) }}
                        </p>
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm">
                            {{ booking.vehicle.name }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ formatDate(booking.starts_on) }} –
                            {{ formatDate(booking.ends_on) }}
                        </p>
                    </div>
                    <BookingStatusBadge :status="booking.status" />
                    <p class="text-right text-sm font-semibold tabular-nums">
                        {{
                            booking.price
                                ? formatMoney(
                                      booking.price.final_total,
                                      booking.price.currency,
                                  )
                                : '—'
                        }}
                    </p>
                </Link>
            </div>
            <div
                v-else
                class="flex min-h-48 flex-col items-center justify-center rounded-md border border-dashed text-center"
            >
                <CheckCircle2 class="mb-3 size-7 text-muted-foreground" />
                <p class="font-medium">{{ t('No current bookings') }}</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ t('New requests will appear here.') }}
                </p>
            </div>
        </section>
    </div>
</template>
