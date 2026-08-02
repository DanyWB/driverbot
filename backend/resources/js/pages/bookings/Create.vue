<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Calculator,
    CheckCircle2,
    Clock3,
    RefreshCw,
    Save,
    Search,
    UserRound,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AdminSelect from '@/components/AdminSelect.vue';
import PriceBreakdown from '@/components/bookings/PriceBreakdown.vue';
import AdminDateInput from '@/components/AdminDateInput.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { formatMoney } from '@/lib/bookings';
import type { PriceQuote } from '@/types';

type CustomerOption = { id: number; name: string; contacts: string[] };
type VehicleOption = {
    id: number;
    name: string;
    type: string;
    inventory_code: string | null;
    is_visible: boolean;
    has_complete_pricing: boolean;
};

const props = defineProps<{
    vehicles: VehicleOption[];
    defaults: {
        starts_on: string;
        ends_on: string;
        vehicle_id: number | null;
    };
    default_customer: CustomerOption | null;
    return_to: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Bookings', href: '/bookings' },
            { title: 'New booking', href: '/bookings/create' },
        ],
    },
});

const form = useForm({
    customer_mode: props.default_customer ? 'existing' : 'new',
    customer_id: props.default_customer?.id ?? (null as number | null),
    customer_name: '',
    phone: '',
    telegram_username: '',
    vehicle_id: props.defaults.vehicle_id,
    starts_on: props.defaults.starts_on,
    ends_on: props.defaults.ends_on,
    pickup_time: '',
    return_time: '',
    source: 'admin_phone',
    initial_status: 'approved',
    client_comment: '',
    admin_note: '',
    deposit_note: '',
    manual_total: '',
    override_reason: '',
});

const quote = ref<PriceQuote | null>(null);
const quoteAvailable = ref<boolean | null>(null);
const quoteError = ref('');
const quoteLoading = ref(false);
const manualPrice = ref(false);
const customerSearch = ref('');
const customerResults = ref<CustomerOption[]>([]);
const selectedCustomer = ref<CustomerOption | null>(props.default_customer);
const customerSearchError = ref('');
const customerSearchLoading = ref(false);
const { t } = useLocale();
let quoteTimer: ReturnType<typeof setTimeout> | undefined;
let quoteRequest: AbortController | undefined;
let customerTimer: ReturnType<typeof setTimeout> | undefined;
let customerRequest: AbortController | undefined;

const selectedVehicle = computed(() =>
    props.vehicles.find((vehicle) => vehicle.id === Number(form.vehicle_id)),
);
const backHref = computed(() => props.return_to ?? '/bookings');

watch(
    () => [form.vehicle_id, form.starts_on, form.ends_on],
    () => {
        clearTimeout(quoteTimer);
        quoteRequest?.abort();
        quote.value = null;
        quoteAvailable.value = null;
        quoteError.value = '';

        if (!form.vehicle_id || !form.starts_on || !form.ends_on) {
            return;
        }

        quoteTimer = setTimeout(loadQuote, 300);
    },
    { immediate: true },
);

watch(manualPrice, (enabled) => {
    if (!enabled) {
        form.manual_total = '';
        form.override_reason = '';
    } else if (quote.value) {
        form.manual_total = String(quote.value.final_total);
    }
});

watch(customerSearch, () => {
    clearTimeout(customerTimer);
    customerRequest?.abort();
    customerResults.value = [];
    customerSearchError.value = '';

    if (customerSearch.value.trim().length < 2 || selectedCustomer.value) {
        return;
    }

    customerTimer = setTimeout(loadCustomers, 250);
});

watch(
    () => form.customer_mode,
    (mode) => {
        if (mode === 'new') {
            clearCustomer();
        }
    },
);

onBeforeUnmount(() => {
    clearTimeout(quoteTimer);
    clearTimeout(customerTimer);
    quoteRequest?.abort();
    customerRequest?.abort();
});

async function loadQuote(): Promise<void> {
    quoteRequest?.abort();
    const request = new AbortController();
    quoteRequest = request;
    quoteLoading.value = true;
    quoteError.value = '';

    try {
        const params = new URLSearchParams({
            vehicle_id: String(form.vehicle_id),
            starts_on: form.starts_on,
            ends_on: form.ends_on,
        });
        const response = await fetch(`/bookings/quote?${params}`, {
            headers: { Accept: 'application/json' },
            signal: request.signal,
        });
        const payload = await response.json();

        if (!response.ok) {
            quoteError.value = t(
                payload.error?.message || 'Price could not be calculated.',
            );

            return;
        }

        quote.value = payload.quote;
        quoteAvailable.value = payload.available;

        if (manualPrice.value && form.manual_total === '') {
            form.manual_total = String(payload.quote.final_total);
        }
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            quoteError.value = t('Price preview is temporarily unavailable.');
        }
    } finally {
        if (quoteRequest === request) {
            quoteLoading.value = false;
        }
    }
}

async function loadCustomers(): Promise<void> {
    customerRequest?.abort();
    const request = new AbortController();
    customerRequest = request;
    customerSearchLoading.value = true;
    customerSearchError.value = '';

    try {
        const params = new URLSearchParams({
            search: customerSearch.value.trim(),
        });
        const response = await fetch(`/bookings/customers/search?${params}`, {
            headers: { Accept: 'application/json' },
            signal: request.signal,
        });
        const payload = await response.json();

        if (!response.ok) {
            customerSearchError.value = t('Customer search is unavailable.');

            return;
        }

        customerResults.value = payload.data;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            customerSearchError.value = t('Customer search is unavailable.');
        }
    } finally {
        if (customerRequest === request) {
            customerSearchLoading.value = false;
        }
    }
}

function selectCustomer(customer: CustomerOption): void {
    selectedCustomer.value = customer;
    form.customer_id = customer.id;
    customerResults.value = [];
}

function clearCustomer(): void {
    selectedCustomer.value = null;
    form.customer_id = null;
    customerSearch.value = '';
    customerResults.value = [];
    customerSearchError.value = '';
}

function submit(): void {
    form.post(withReturnTo('/bookings'), { preserveScroll: true });
}

function withReturnTo(path: string): string {
    if (!props.return_to) {
        return path;
    }

    return `${path}?${new URLSearchParams({ return_to: props.return_to })}`;
}

function domainError(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}
</script>

<template>
    <Head :title="t('New booking')" />

    <div class="flex min-w-0 flex-1 flex-col">
        <header
            class="flex items-center gap-3 border-b px-4 py-5 sm:px-6 lg:px-8"
        >
            <Button
                as-child
                variant="ghost"
                size="icon"
                :title="t(return_to ? 'Back to timeline' : 'Back to bookings')"
            >
                <Link :href="backHref"
                    ><ArrowLeft /><span class="sr-only">{{
                        t('Back')
                    }}</span></Link
                >
            </Button>
            <div>
                <p class="text-sm text-muted-foreground">
                    {{ t('Manual reservation') }}
                </p>
                <h1 class="text-2xl font-semibold">{{ t('New booking') }}</h1>
            </div>
        </header>

        <form
            class="grid min-w-0 flex-1 xl:grid-cols-[minmax(0,1fr)_380px]"
            @submit.prevent="submit"
        >
            <div class="min-w-0 divide-y">
                <section
                    class="px-4 py-6 sm:px-6 lg:px-8"
                    aria-labelledby="customer-heading"
                >
                    <div class="mb-5 flex items-center gap-2">
                        <UserRound class="size-5 text-muted-foreground" />
                        <h2 id="customer-heading" class="font-semibold">
                            {{ t('Customer') }}
                        </h2>
                    </div>

                    <div
                        class="mb-5 inline-flex rounded-md border bg-muted/30 p-0.5"
                    >
                        <button
                            v-for="mode in ['new', 'existing']"
                            :key="mode"
                            type="button"
                            class="h-8 rounded px-3 text-sm font-medium capitalize"
                            :class="
                                form.customer_mode === mode
                                    ? 'bg-background shadow-xs'
                                    : 'text-muted-foreground'
                            "
                            @click="form.customer_mode = mode"
                        >
                            {{ t(mode + ' customer') }}
                        </button>
                    </div>

                    <div
                        v-if="form.customer_mode === 'existing'"
                        class="max-w-xl"
                    >
                        <div
                            v-if="selectedCustomer"
                            class="flex items-center justify-between gap-3 rounded-md border px-3 py-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ selectedCustomer.name }}
                                </p>
                                <p
                                    v-if="selectedCustomer.contacts.length"
                                    class="mt-0.5 truncate text-sm text-muted-foreground"
                                >
                                    {{ selectedCustomer.contacts.join(' · ') }}
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :title="t('Change customer')"
                                @click="clearCustomer"
                            >
                                <X />
                                <span class="sr-only">{{
                                    t('Change customer')
                                }}</span>
                            </Button>
                        </div>
                        <template v-else>
                            <Label for="customer_search">{{
                                t('Customer')
                            }}</Label>
                            <div class="relative mt-2">
                                <Search
                                    class="absolute top-2.5 left-3 size-4 text-muted-foreground"
                                />
                                <Input
                                    id="customer_search"
                                    v-model="customerSearch"
                                    class="pl-9"
                                    autocomplete="off"
                                    :placeholder="t('Name, phone or Telegram')"
                                />
                            </div>
                            <p
                                v-if="customerSearchLoading"
                                class="mt-2 text-sm text-muted-foreground"
                            >
                                {{ t('Searching...') }}
                            </p>
                            <div
                                v-else-if="customerSearchError"
                                class="mt-2 flex items-center justify-between gap-3 rounded-md border border-destructive/30 p-3 text-sm text-destructive"
                            >
                                <span>{{ customerSearchError }}</span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="loadCustomers"
                                >
                                    <RefreshCw />{{ t('Retry') }}
                                </Button>
                            </div>
                            <div
                                v-else-if="customerResults.length"
                                class="mt-2 divide-y rounded-md border"
                            >
                                <button
                                    v-for="customer in customerResults"
                                    :key="customer.id"
                                    type="button"
                                    class="block w-full px-3 py-2.5 text-left hover:bg-muted/40"
                                    @click="selectCustomer(customer)"
                                >
                                    <span class="block font-medium">{{
                                        customer.name
                                    }}</span>
                                    <span
                                        v-if="customer.contacts.length"
                                        class="mt-0.5 block truncate text-sm text-muted-foreground"
                                    >
                                        {{ customer.contacts.join(' · ') }}
                                    </span>
                                </button>
                            </div>
                            <p
                                v-else-if="customerSearch.trim().length >= 2"
                                class="mt-2 text-sm text-muted-foreground"
                            >
                                {{ t('No customers found.') }}
                            </p>
                        </template>
                        <InputError
                            class="mt-1"
                            :message="form.errors.customer_id"
                        />
                    </div>

                    <div v-else class="grid max-w-3xl gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <Label for="customer_name">{{
                                t('Full name')
                            }}</Label>
                            <Input
                                id="customer_name"
                                v-model="form.customer_name"
                                class="mt-2"
                                autocomplete="name"
                            />
                            <InputError
                                class="mt-1"
                                :message="form.errors.customer_name"
                            />
                        </div>
                        <div>
                            <Label for="phone">{{ t('Phone') }}</Label>
                            <Input
                                id="phone"
                                v-model="form.phone"
                                class="mt-2"
                                type="tel"
                                autocomplete="tel"
                            />
                            <InputError
                                class="mt-1"
                                :message="form.errors.phone"
                            />
                        </div>
                        <div>
                            <Label for="telegram_username">{{
                                t('Telegram username')
                            }}</Label>
                            <Input
                                id="telegram_username"
                                v-model="form.telegram_username"
                                class="mt-2"
                                placeholder="@username"
                            />
                            <InputError
                                class="mt-1"
                                :message="form.errors.telegram_username"
                            />
                        </div>
                    </div>
                </section>

                <section
                    class="px-4 py-6 sm:px-6 lg:px-8"
                    aria-labelledby="rental-heading"
                >
                    <div class="mb-5 flex items-center gap-2">
                        <Clock3 class="size-5 text-muted-foreground" />
                        <h2 id="rental-heading" class="font-semibold">
                            {{ t('Rental') }}
                        </h2>
                    </div>
                    <div class="grid max-w-3xl gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <Label for="vehicle_id">{{ t('Vehicle') }}</Label>
                            <AdminSelect
                                id="vehicle_id"
                                v-model="form.vehicle_id"
                                class="mt-2"
                                :options="[
                                    {
                                        value: null,
                                        label: t('Select vehicle'),
                                        disabled: true,
                                    },
                                    ...vehicles.map((vehicle) => ({
                                        value: vehicle.id,
                                        label:
                                            vehicle.name +
                                            ' · ' +
                                            t(vehicle.type) +
                                            (!vehicle.is_visible
                                                ? ' · ' + t('internal')
                                                : '') +
                                            (!vehicle.has_complete_pricing
                                                ? ' · ' + t('incomplete price')
                                                : ''),
                                        disabled: !vehicle.has_complete_pricing,
                                    })),
                                ]"
                            />
                            <InputError
                                class="mt-1"
                                :message="form.errors.vehicle_id"
                            />
                        </div>
                        <div>
                            <Label for="starts_on">{{ t('Start date') }}</Label>
                            <AdminDateInput
                                id="starts_on"
                                v-model="form.starts_on"
                                class="mt-2"
                            />
                            <InputError
                                class="mt-1"
                                :message="form.errors.starts_on"
                            />
                        </div>
                        <div>
                            <Label for="ends_on">{{ t('End date') }}</Label>
                            <AdminDateInput
                                id="ends_on"
                                v-model="form.ends_on"
                                class="mt-2"
                            />
                            <InputError
                                class="mt-1"
                                :message="form.errors.ends_on"
                            />
                        </div>
                        <div>
                            <Label for="pickup_time">{{
                                t('Pickup time')
                            }}</Label>
                            <Input
                                id="pickup_time"
                                v-model="form.pickup_time"
                                class="mt-2"
                                type="time"
                            />
                            <InputError
                                class="mt-1"
                                :message="form.errors.pickup_time"
                            />
                        </div>
                        <div>
                            <Label for="return_time">{{
                                t('Return time')
                            }}</Label>
                            <Input
                                id="return_time"
                                v-model="form.return_time"
                                class="mt-2"
                                type="time"
                            />
                            <InputError
                                class="mt-1"
                                :message="form.errors.return_time"
                            />
                        </div>
                        <div>
                            <Label for="source">{{ t('Source') }}</Label>
                            <AdminSelect
                                id="source"
                                v-model="form.source"
                                class="mt-2"
                                :options="[
                                    {
                                        value: 'admin_phone',
                                        label: t('Phone'),
                                    },
                                    {
                                        value: 'admin_whatsapp',
                                        label: 'WhatsApp',
                                    },
                                    {
                                        value: 'admin_instagram',
                                        label: 'Instagram',
                                    },
                                    { value: 'telegram', label: 'Telegram' },
                                    {
                                        value: 'admin_manual',
                                        label: t('Manual'),
                                    },
                                ]"
                            />
                        </div>
                        <div>
                            <Label for="initial_status">{{
                                t('Initial status')
                            }}</Label>
                            <AdminSelect
                                id="initial_status"
                                v-model="form.initial_status"
                                class="mt-2"
                                :options="[
                                    {
                                        value: 'approved',
                                        label: t('Approved'),
                                    },
                                    {
                                        value: 'pending',
                                        label: t('Pending review'),
                                    },
                                ]"
                            />
                        </div>
                    </div>
                </section>

                <section
                    class="px-4 py-6 sm:px-6 lg:px-8"
                    aria-labelledby="notes-heading"
                >
                    <h2 id="notes-heading" class="mb-5 font-semibold">
                        {{ t('Notes') }}
                    </h2>
                    <div class="grid max-w-3xl gap-4 sm:grid-cols-2">
                        <div>
                            <Label for="client_comment">{{
                                t('Customer comment')
                            }}</Label>
                            <textarea
                                id="client_comment"
                                v-model="form.client_comment"
                                class="admin-textarea mt-2"
                                rows="4"
                            />
                        </div>
                        <div>
                            <Label for="admin_note">{{
                                t('Internal note')
                            }}</Label>
                            <textarea
                                id="admin_note"
                                v-model="form.admin_note"
                                class="admin-textarea mt-2"
                                rows="4"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="deposit_note">{{
                                t('Payment / deposit note')
                            }}</Label>
                            <Input
                                id="deposit_note"
                                v-model="form.deposit_note"
                                class="mt-2"
                            />
                        </div>
                    </div>
                </section>
            </div>

            <aside
                class="border-t bg-muted/15 px-4 py-6 xl:border-t-0 xl:border-l xl:px-6"
                aria-labelledby="price-heading"
            >
                <div class="sticky top-6">
                    <div class="flex items-center gap-2">
                        <Calculator class="size-5 text-muted-foreground" />
                        <h2 id="price-heading" class="font-semibold">
                            {{ t('Price preview') }}
                        </h2>
                    </div>
                    <p
                        v-if="selectedVehicle"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        {{ selectedVehicle.name }}
                    </p>

                    <div
                        v-if="quoteLoading"
                        class="mt-6 flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <span
                            class="size-4 animate-spin rounded-full border-2 border-muted-foreground border-t-transparent"
                        />
                        {{ t('Calculating') }}
                    </div>
                    <div
                        v-else-if="quoteError"
                        class="mt-6 flex items-center justify-between gap-3 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        <span class="flex gap-2">
                            <AlertTriangle class="mt-0.5 size-4 shrink-0" />{{
                                quoteError
                            }}
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="loadQuote"
                        >
                            <RefreshCw />{{ t('Retry') }}
                        </Button>
                    </div>
                    <div v-else-if="quote" class="mt-6 space-y-4">
                        <div
                            class="flex items-end justify-between gap-3 border-b pb-4"
                        >
                            <div>
                                <p class="text-xs text-muted-foreground">
                                    {{ t('Automatic total') }}
                                </p>
                                <p
                                    class="mt-1 text-2xl font-semibold tabular-nums"
                                >
                                    {{
                                        formatMoney(
                                            quote.final_total,
                                            quote.currency,
                                        )
                                    }}
                                </p>
                            </div>
                            <span class="text-sm text-muted-foreground"
                                >{{
                                    t('Days count', { count: quote.total_days })
                                }}
                                · {{ t(quote.tier_key) }}</span
                            >
                        </div>
                        <div
                            class="flex items-center gap-2 text-sm"
                            :class="
                                quoteAvailable
                                    ? 'text-emerald-700'
                                    : 'text-destructive'
                            "
                        >
                            <CheckCircle2
                                v-if="quoteAvailable"
                                class="size-4"
                            />
                            <AlertTriangle v-else class="size-4" />
                            {{
                                quoteAvailable
                                    ? t('Available for these dates')
                                    : t('Dates are already occupied')
                            }}
                        </div>
                        <PriceBreakdown
                            :items="quote.breakdown"
                            :currency="quote.currency"
                        />
                    </div>
                    <p v-else class="mt-6 text-sm text-muted-foreground">
                        {{ t('Select a vehicle and rental dates.') }}
                    </p>

                    <div class="mt-6 border-t pt-5">
                        <label
                            class="flex items-center gap-3 text-sm font-medium"
                        >
                            <input
                                v-model="manualPrice"
                                type="checkbox"
                                class="size-4 rounded border-input accent-foreground"
                            />
                            {{ t('Set final price manually') }}
                        </label>
                        <div v-if="manualPrice" class="mt-4 space-y-4">
                            <div>
                                <Label for="manual_total">{{
                                    t('Final total, THB')
                                }}</Label>
                                <Input
                                    id="manual_total"
                                    v-model="form.manual_total"
                                    class="mt-2"
                                    type="number"
                                    min="0"
                                    step="100"
                                />
                                <InputError
                                    class="mt-1"
                                    :message="form.errors.manual_total"
                                />
                            </div>
                            <div>
                                <Label for="override_reason">{{
                                    t('Reason')
                                }}</Label>
                                <textarea
                                    id="override_reason"
                                    v-model="form.override_reason"
                                    class="admin-textarea mt-2"
                                    rows="3"
                                />
                                <InputError
                                    class="mt-1"
                                    :message="form.errors.override_reason"
                                />
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="domainError('booking')"
                        class="mt-5 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        {{ domainError('booking') }}
                    </div>

                    <Button
                        type="submit"
                        class="mt-6 w-full"
                        size="lg"
                        :disabled="form.processing || quoteAvailable === false"
                    >
                        <Save />
                        {{ t(form.processing ? 'Saving…' : 'Create booking') }}
                    </Button>
                </div>
            </aside>
        </form>
    </div>
</template>
