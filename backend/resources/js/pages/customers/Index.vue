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
import AdminSelect from '@/components/AdminSelect.vue';
import BookingStatusBadge from '@/components/bookings/BookingStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
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
const { t } = useLocale();

function primaryContact(contacts: CustomerContact[]): string {
    return (
        contacts.find((contact) => contact.is_primary)?.value ??
        contacts[0]?.value ??
        t('No contact')
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
    <Head :title="t('Customers')" />
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="border-b px-4 py-5 sm:px-6 lg:px-8">
            <p class="text-sm text-muted-foreground">
                {{ t('Contacts and rental history') }}
            </p>
            <h1 class="text-2xl font-semibold">{{ t('Customers') }}</h1>
        </header>

        <section class="grid border-b sm:grid-cols-3">
            <div
                class="flex items-center gap-3 border-b px-4 py-4 sm:border-r sm:border-b-0 lg:px-6"
            >
                <Users class="size-5 text-cyan-700" />
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Total') }}
                    </p>
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
                    <p class="text-xs text-muted-foreground">
                        {{ t('With bookings') }}
                    </p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.with_bookings }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3 px-4 py-4 lg:px-6">
                <FileCheck2 class="size-5 text-violet-700" />
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('With documents') }}
                    </p>
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
                ><span class="sr-only">{{ t('Search customers') }}</span
                ><Search
                    class="absolute top-2.5 left-3 size-4 text-muted-foreground" /><Input
                    v-model="filters.search"
                    class="pl-9"
                    :placeholder="t('Name, phone, Telegram or email')"
            /></label>
            <AdminSelect
                v-model="filters.documents"
                :aria-label="t('Documents')"
                :options="[
                    { value: 'all', label: t('Any documents') },
                    { value: 'yes', label: t('Has documents') },
                    { value: 'no', label: t('No documents') },
                ]"
            />
            <AdminSelect
                v-model="filters.sort"
                :aria-label="t('Sort customers')"
                :options="[
                    { value: 'updated_at', label: t('Recently updated') },
                    { value: 'created_at', label: t('Recently added') },
                    { value: 'name', label: t('Name') },
                ]"
            />
            <div class="flex gap-2">
                <Button type="submit" class="flex-1"
                    ><Search />{{ t('Apply') }}</Button
                ><Button
                    type="button"
                    variant="outline"
                    size="icon"
                    :title="t('Reset filters')"
                    @click="resetFilters"
                    ><RotateCcw /><span class="sr-only">{{
                        t('Reset')
                    }}</span></Button
                >
            </div>
        </form>

        <section class="min-w-0 flex-1">
            <div
                v-if="customers.data.length === 0"
                class="flex min-h-72 flex-col items-center justify-center px-6 text-center"
            >
                <UserRound class="mb-3 size-8 text-muted-foreground" />
                <h2 class="font-medium">{{ t('No customers found') }}</h2>
            </div>
            <template v-else>
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead
                            class="border-b bg-muted/35 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium lg:px-6">
                                    {{ t('Customer') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ t('Contacts') }}
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    {{ t('Bookings') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ t('Latest booking') }}
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    {{ t('Documents') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ t('Updated') }}
                                </th>
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
                                        {{
                                            t('More contacts: :count', {
                                                count:
                                                    customer.contacts.length -
                                                    1,
                                            })
                                        }}
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
                                >{{
                                    t('Bookings count', {
                                        count: customer.bookings_count,
                                    })
                                }}</span
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
                    :label="t('Customer pages')"
                />
            </template>
        </section>
    </div>
</template>
