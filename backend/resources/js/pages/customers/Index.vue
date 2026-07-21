<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CalendarDays,
    FileCheck2,
    RotateCcw,
    Search,
    UserRound,
    Users,
} from '@lucide/vue';
import { reactive } from 'vue';
import AdminPagination from '@/components/AdminPagination.vue';
import BookingStatusBadge from '@/components/bookings/BookingStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime } from '@/lib/bookings';
import type { CustomerContact, PaginatedCustomers } from '@/types';

type Filters = {
    search: string;
    documents: string;
    sort: string;
    direction: string;
    per_page: number;
};

const props = defineProps<{
    customers: PaginatedCustomers;
    filters: Filters;
    summary: { total: number; with_bookings: number; with_documents: number };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Customers', href: '/customers' }] },
});

const filters = reactive<Filters>({ ...props.filters });

function primaryContact(contacts: CustomerContact[]): string {
    return (
        contacts.find((contact) => contact.is_primary)?.value ??
        contacts[0]?.value ??
        'No contact'
    );
}

function applyFilters(): void {
    router.get(
        '/customers',
        { ...filters },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function resetFilters(): void {
    Object.assign(filters, {
        search: '',
        documents: 'all',
        sort: 'updated_at',
        direction: 'desc',
        per_page: 25,
    });
    applyFilters();
}
</script>

<template>
    <Head title="Customers" />
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="border-b px-4 py-5 sm:px-6 lg:px-8">
            <p class="text-sm text-muted-foreground">
                Contacts and rental history
            </p>
            <h1 class="text-2xl font-semibold">Customers</h1>
        </header>

        <section class="grid border-b sm:grid-cols-3">
            <div
                class="flex items-center gap-3 border-b px-4 py-4 sm:border-r sm:border-b-0 lg:px-6"
            >
                <Users class="size-5 text-cyan-700" />
                <div>
                    <p class="text-xs text-muted-foreground">Total</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.total }}
                    </p>
                </div>
            </div>
            <div
                class="flex items-center gap-3 border-b px-4 py-4 sm:border-r sm:border-b-0 lg:px-6"
            >
                <CalendarDays class="size-5 text-emerald-700" />
                <div>
                    <p class="text-xs text-muted-foreground">With bookings</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.with_bookings }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3 px-4 py-4 lg:px-6">
                <FileCheck2 class="size-5 text-violet-700" />
                <div>
                    <p class="text-xs text-muted-foreground">With documents</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.with_documents }}
                    </p>
                </div>
            </div>
        </section>

        <form
            class="grid gap-3 border-b px-4 py-4 sm:grid-cols-[minmax(0,1fr)_180px_160px_auto] sm:px-6 lg:px-8"
            @submit.prevent="applyFilters"
        >
            <label class="relative"
                ><span class="sr-only">Search customers</span
                ><Search
                    class="absolute top-2.5 left-3 size-4 text-muted-foreground" /><Input
                    v-model="filters.search"
                    class="pl-9"
                    placeholder="Name, phone, Telegram or email"
            /></label>
            <select
                v-model="filters.documents"
                class="admin-select"
                aria-label="Documents"
            >
                <option value="all">Any documents</option>
                <option value="yes">Has documents</option>
                <option value="no">No documents</option>
            </select>
            <select
                v-model="filters.sort"
                class="admin-select"
                aria-label="Sort customers"
            >
                <option value="updated_at">Recently updated</option>
                <option value="created_at">Recently added</option>
                <option value="name">Name</option>
            </select>
            <div class="flex gap-2">
                <Button type="submit" class="flex-1"><Search />Apply</Button
                ><Button
                    type="button"
                    variant="outline"
                    size="icon"
                    title="Reset filters"
                    @click="resetFilters"
                    ><RotateCcw /><span class="sr-only">Reset</span></Button
                >
            </div>
        </form>

        <section class="min-w-0 flex-1">
            <div
                v-if="customers.data.length === 0"
                class="flex min-h-72 flex-col items-center justify-center px-6 text-center"
            >
                <UserRound class="mb-3 size-8 text-muted-foreground" />
                <h2 class="font-medium">No customers found</h2>
            </div>
            <template v-else>
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead
                            class="border-b bg-muted/35 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium lg:px-6">
                                    Customer
                                </th>
                                <th class="px-4 py-3 font-medium">Contacts</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Bookings
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    Latest booking
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Documents
                                </th>
                                <th class="px-4 py-3 font-medium">Updated</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="customer in customers.data"
                                :key="customer.id"
                                class="hover:bg-muted/25"
                            >
                                <td class="px-4 py-3 lg:px-6">
                                    <Link
                                        :href="`/customers/${customer.id}`"
                                        class="font-medium hover:underline"
                                        >{{ customer.name }}</Link
                                    >
                                    <p class="text-xs text-muted-foreground">
                                        #{{ customer.id }} ·
                                        {{ customer.locale.toUpperCase() }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <p>
                                        {{ primaryContact(customer.contacts) }}
                                    </p>
                                    <p
                                        v-if="customer.contacts.length > 1"
                                        class="text-xs text-muted-foreground"
                                    >
                                        +{{ customer.contacts.length - 1 }} more
                                    </p>
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-medium tabular-nums"
                                >
                                    {{ customer.bookings_count }}
                                </td>
                                <td class="px-4 py-3">
                                    <template v-if="customer.latest_booking"
                                        ><div class="flex items-center gap-2">
                                            <BookingStatusBadge
                                                :status="
                                                    customer.latest_booking
                                                        .status
                                                "
                                            /><span
                                                class="text-xs text-muted-foreground"
                                                >{{
                                                    customer.latest_booking
                                                        .starts_on
                                                        ? formatDate(
                                                              customer
                                                                  .latest_booking
                                                                  .starts_on,
                                                          )
                                                        : '—'
                                                }}</span
                                            >
                                        </div>
                                        <p
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            {{
                                                customer.latest_booking
                                                    .vehicle_name
                                            }}
                                        </p></template
                                    ><span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ customer.documents_count }}
                                </td>
                                <td
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    {{ formatDateTime(customer.updated_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="divide-y md:hidden">
                    <Link
                        v-for="customer in customers.data"
                        :key="customer.id"
                        :href="`/customers/${customer.id}`"
                        class="block px-4 py-4 hover:bg-muted/30"
                        ><div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ customer.name }}
                                </p>
                                <p
                                    class="mt-0.5 truncate text-sm text-muted-foreground"
                                >
                                    {{ primaryContact(customer.contacts) }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 text-sm font-medium tabular-nums"
                                >{{ customer.bookings_count }} bookings</span
                            >
                        </div>
                        <div
                            v-if="customer.latest_booking"
                            class="mt-3 flex items-center justify-between gap-3"
                        >
                            <BookingStatusBadge
                                :status="customer.latest_booking.status"
                            /><span
                                class="truncate text-xs text-muted-foreground"
                                >{{
                                    customer.latest_booking.vehicle_name
                                }}</span
                            >
                        </div></Link
                    >
                </div>
                <AdminPagination
                    :paginator="customers"
                    label="Customer pages"
                />
            </template>
        </section>
    </div>
</template>
