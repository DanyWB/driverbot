<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarPlus,
    Download,
    FilePlus2,
    FileText,
    MessageCircle,
    Phone,
    Trash2,
    UserRound,
} from '@lucide/vue';
import { ref } from 'vue';
import AdminPagination from '@/components/AdminPagination.vue';
import BookingStatusBadge from '@/components/bookings/BookingStatusBadge.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatMoney, shortBookingId } from '@/lib/bookings';
import type { CustomerDetail, PaginatedCustomerBookings } from '@/types';

const props = defineProps<{
    customer: CustomerDetail;
    bookings: PaginatedCustomerBookings;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Customers', href: '/customers' }] },
});

const fileInput = ref<HTMLInputElement | null>(null);
const documentForm = useForm<{ document: File | null; type: string }>({
    document: null,
    type: 'passport',
});

function selectFile(event: Event): void {
    documentForm.document =
        (event.target as HTMLInputElement).files?.[0] ?? null;
}

function uploadDocument(): void {
    documentForm.post(`/customers/${props.customer.id}/documents`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            documentForm.reset();

            if (fileInput.value) {
                fileInput.value.value = '';
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

function fileSize(bytes: number | null): string {
    if (bytes === null) {
        return 'Unknown size';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.ceil(bytes / 1024)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
</script>

<template>
    <Head :title="customer.name" />
    <div class="flex min-w-0 flex-1 flex-col">
        <header
            class="flex flex-col gap-4 border-b px-4 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8"
        >
            <div class="flex min-w-0 items-center gap-3">
                <Button
                    as-child
                    variant="ghost"
                    size="icon"
                    title="Back to customers"
                    ><Link href="/customers"
                        ><ArrowLeft /><span class="sr-only">Back</span></Link
                    ></Button
                >
                <div class="min-w-0">
                    <p class="text-sm text-muted-foreground">
                        Customer #{{ customer.id }}
                    </p>
                    <h1 class="truncate text-2xl font-semibold">
                        {{ customer.name }}
                    </h1>
                </div>
            </div>
            <Button as-child
                ><Link :href="`/bookings/create?customer_id=${customer.id}`"
                    ><CalendarPlus />New booking</Link
                ></Button
            >
        </header>

        <div class="grid border-b lg:grid-cols-[minmax(0,1fr)_340px]">
            <section class="px-4 py-6 sm:px-6 lg:border-r lg:px-8">
                <div class="flex items-center gap-2">
                    <UserRound class="size-5 text-muted-foreground" />
                    <h2 class="font-semibold">Contacts</h2>
                </div>
                <div
                    v-if="customer.contacts.length"
                    class="mt-4 grid gap-3 sm:grid-cols-2"
                >
                    <div
                        v-for="contact in customer.contacts"
                        :key="contact.id"
                        class="min-w-0 rounded-md border px-4 py-3"
                    >
                        <p class="text-xs text-muted-foreground capitalize">
                            {{ contact.type.replace('_', ' ')
                            }}{{ contact.is_primary ? ' · primary' : '' }}
                        </p>
                        <a
                            v-if="contact.type === 'phone'"
                            :href="`tel:${contact.value}`"
                            class="mt-1 block truncate font-medium hover:underline"
                            ><Phone class="mr-1 inline size-3.5" />{{
                                contact.value
                            }}</a
                        >
                        <p v-else class="mt-1 truncate font-medium">
                            <MessageCircle class="mr-1 inline size-3.5" />{{
                                contact.value
                            }}
                        </p>
                    </div>
                </div>
                <p v-else class="mt-4 text-sm text-muted-foreground">
                    No contacts.
                </p>
                <div v-if="customer.identities.length" class="mt-6">
                    <h3 class="text-sm font-medium">Channel identities</h3>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span
                            v-for="identity in customer.identities"
                            :key="identity.id"
                            class="rounded border px-2 py-1 text-xs"
                            ><span class="capitalize">{{
                                identity.provider
                            }}</span>
                            · {{ identity.external_id }}</span
                        >
                    </div>
                </div>
                <div v-if="customer.internal_note" class="mt-6">
                    <h3 class="text-sm font-medium">Internal note</h3>
                    <p
                        class="mt-2 text-sm whitespace-pre-wrap text-muted-foreground"
                    >
                        {{ customer.internal_note }}
                    </p>
                </div>
            </section>

            <aside class="px-4 py-6 sm:px-6">
                <div class="flex items-center gap-2">
                    <FileText class="size-5 text-muted-foreground" />
                    <h2 class="font-semibold">Private documents</h2>
                </div>
                <form class="mt-4 space-y-3" @submit.prevent="uploadDocument">
                    <div>
                        <Label for="customer_document_type">Type</Label
                        ><select
                            id="customer_document_type"
                            v-model="documentForm.type"
                            class="admin-select mt-2 w-full"
                        >
                            <option value="passport">Passport</option>
                            <option value="driver_license">
                                Driver license
                            </option>
                            <option value="photo">Photo</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <Label for="customer_document">File</Label
                        ><Input
                            id="customer_document"
                            ref="fileInput"
                            type="file"
                            accept="application/pdf,image/jpeg,image/png,image/webp"
                            class="mt-2"
                            @change="selectFile"
                        /><InputError
                            class="mt-1"
                            :message="documentForm.errors.document"
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        class="w-full"
                        :disabled="
                            !documentForm.document || documentForm.processing
                        "
                        ><FilePlus2 />Upload document</Button
                    >
                </form>
                <ul
                    v-if="customer.documents.length"
                    class="mt-5 divide-y border-t"
                >
                    <li
                        v-for="document in customer.documents"
                        :key="document.id"
                        class="py-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    {{ document.filename }}
                                </p>
                                <p
                                    class="mt-0.5 text-xs text-muted-foreground capitalize"
                                >
                                    {{ document.type.replace('_', ' ') }} ·
                                    {{ fileSize(document.file_size) }}
                                </p>
                                <Link
                                    v-if="document.booking_public_id"
                                    :href="`/bookings/${document.booking_public_id}`"
                                    class="mt-1 block text-xs hover:underline"
                                    >Booking #{{
                                        shortBookingId(
                                            document.booking_public_id,
                                        )
                                    }}</Link
                                >
                            </div>
                            <div class="flex shrink-0">
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
                                ><Button
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
                            </div>
                        </div>
                    </li>
                </ul>
                <p v-else class="mt-5 text-sm text-muted-foreground">
                    No documents.
                </p>
            </aside>
        </div>

        <section>
            <div
                class="flex items-center justify-between border-b px-4 py-4 sm:px-6 lg:px-8"
            >
                <h2 class="font-semibold">Booking history</h2>
                <span class="text-sm text-muted-foreground"
                    >{{ bookings.total }} total</span
                >
            </div>
            <div
                v-if="bookings.data.length === 0"
                class="grid min-h-40 place-items-center text-sm text-muted-foreground"
            >
                No bookings.
            </div>
            <template v-else>
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[800px] text-sm">
                        <thead
                            class="border-b bg-muted/35 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium lg:px-6">
                                    Booking
                                </th>
                                <th class="px-4 py-3 font-medium">Vehicle</th>
                                <th class="px-4 py-3 font-medium">
                                    Rental dates
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Final price
                                </th>
                                <th class="px-4 py-3 font-medium">Source</th>
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
                                        >#{{
                                            shortBookingId(booking.public_id)
                                        }}</Link
                                    >
                                    <div class="mt-1.5">
                                        <BookingStatusBadge
                                            :status="booking.status"
                                        />
                                    </div>
                                </td>
                                <td class="px-4 py-3 font-medium">
                                    {{ booking.vehicle.name }}
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ formatDate(booking.starts_on) }} –
                                    {{ formatDate(booking.ends_on) }}
                                    <p class="text-xs text-muted-foreground">
                                        {{ booking.total_days }} days
                                    </p>
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-medium tabular-nums"
                                >
                                    {{
                                        booking.price
                                            ? formatMoney(
                                                  booking.price.final_total,
                                                  booking.price.currency,
                                              )
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-4 py-3 text-muted-foreground capitalize"
                                >
                                    {{ booking.source.replace('admin_', '') }}
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
                        class="block px-4 py-4"
                        ><div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-medium">
                                    {{ booking.vehicle.name }}
                                </p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ formatDate(booking.starts_on) }} –
                                    {{ formatDate(booking.ends_on) }}
                                </p>
                            </div>
                            <BookingStatusBadge :status="booking.status" /></div
                    ></Link>
                </div>
                <AdminPagination
                    :paginator="bookings"
                    label="Customer booking pages"
                />
            </template>
        </section>
    </div>
</template>
