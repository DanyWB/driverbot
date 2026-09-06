<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
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
import AdminDateInput from '@/components/AdminDateInput.vue';
import AdminSelect from '@/components/AdminSelect.vue';
import InputError from '@/components/InputError.vue';
import PriceBreakdown from '@/components/bookings/PriceBreakdown.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLocale } from '@/composables/useLocale';
import { formatMoney } from '@/lib/bookings';
import type { PriceQuote, TimelineBookingSelection } from '@/types';

type CustomerOption = { id: number; name: string; contacts: string[] };

const props = defineProps<{
    open: boolean;
    selection: TimelineBookingSelection | null;
    returnTo: string;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const { t } = useLocale();
const form = useForm({
    customer_mode: 'new',
    customer_id: null as number | null,
    customer_name: '',
    phone: '',
    telegram_username: '',
    vehicle_id: null as number | null,
    starts_on: '',
    ends_on: '',
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
const selectedCustomer = ref<CustomerOption | null>(null);
const customerSearchError = ref('');
const customerSearchLoading = ref(false);
let quoteTimer: ReturnType<typeof setTimeout> | undefined;
let quoteRequest: AbortController | undefined;
let customerTimer: ReturnType<typeof setTimeout> | undefined;
let customerRequest: AbortController | undefined;

const selectedDays = computed(() => {
    if (!form.starts_on || !form.ends_on) {
        return 0;
    }

    const start = Date.parse(`${form.starts_on}T00:00:00Z`);
    const end = Date.parse(`${form.ends_on}T00:00:00Z`);

    return Number.isFinite(start) && Number.isFinite(end) && end >= start
        ? Math.floor((end - start) / 86_400_000) + 1
        : 0;
});

watch(
    () => [props.open, props.selection] as const,
    ([open, selection]) => {
        if (!open || !selection) {
            return;
        }

        resetForm(selection);
    },
    { immediate: true },
);

watch(
    () => [form.vehicle_id, form.starts_on, form.ends_on],
    () => {
        clearTimeout(quoteTimer);
        quoteRequest?.abort();
        quote.value = null;
        quoteAvailable.value = null;
        quoteError.value = '';

        if (
            !props.open ||
            !form.vehicle_id ||
            !form.starts_on ||
            !form.ends_on
        ) {
            return;
        }

        quoteTimer = setTimeout(loadQuote, 250);
    },
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

function resetForm(selection: TimelineBookingSelection): void {
    form.reset();
    form.clearErrors();
    form.vehicle_id = selection.vehicleId;
    form.starts_on = selection.startsOn;
    form.ends_on = selection.endsOn;
    manualPrice.value = false;
    selectedCustomer.value = null;
    customerSearch.value = '';
    customerResults.value = [];
    customerSearchError.value = '';
    quote.value = null;
    quoteAvailable.value = null;
    quoteError.value = '';
}

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

function close(): void {
    if (!form.processing) {
        emit('update:open', false);
    }
}

function submit(): void {
    const params = new URLSearchParams({
        return_to: props.returnTo,
        return_to_timeline: '1',
    });

    form.post(`/bookings?${params}`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}

function domainError(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent
            side="right"
            class="w-full gap-0 overflow-hidden p-0 sm:max-w-xl lg:max-w-2xl"
        >
            <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
                <SheetHeader class="shrink-0 border-b px-5 py-4 pr-12">
                    <SheetTitle>{{ t('Quick booking') }}</SheetTitle>
                    <SheetDescription v-if="selection">
                        {{ selection.vehicleName }} · {{ form.starts_on }} —
                        {{ form.ends_on }} ·
                        {{ t('Days count', { count: selectedDays }) }}
                    </SheetDescription>
                </SheetHeader>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <section class="border-b px-5 py-5">
                        <div class="mb-4 flex items-center gap-2">
                            <UserRound class="size-4 text-muted-foreground" />
                            <h3 class="font-semibold">{{ t('Customer') }}</h3>
                        </div>

                        <div
                            class="mb-4 inline-flex rounded-md border bg-muted/30 p-0.5"
                        >
                            <button
                                v-for="mode in ['new', 'existing']"
                                :key="mode"
                                type="button"
                                class="h-8 rounded px-3 text-sm font-medium"
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

                        <div v-if="form.customer_mode === 'existing'">
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
                                        {{
                                            selectedCustomer.contacts.join(
                                                ' · ',
                                            )
                                        }}
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
                                </Button>
                            </div>
                            <template v-else>
                                <Label for="timeline_customer_search">{{
                                    t('Customer')
                                }}</Label>
                                <div class="relative mt-2">
                                    <Search
                                        class="absolute top-2.5 left-3 size-4 text-muted-foreground"
                                    />
                                    <Input
                                        id="timeline_customer_search"
                                        v-model="customerSearch"
                                        class="pl-9"
                                        autocomplete="off"
                                        :placeholder="
                                            t('Name, phone or Telegram')
                                        "
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
                                    class="mt-2 max-h-48 divide-y overflow-y-auto rounded-md border"
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
                                    v-else-if="
                                        customerSearch.trim().length >= 2
                                    "
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

                        <div v-else class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <Label for="timeline_customer_name">{{
                                    t('Full name')
                                }}</Label>
                                <Input
                                    id="timeline_customer_name"
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
                                <Label for="timeline_phone">{{
                                    t('Phone')
                                }}</Label>
                                <Input
                                    id="timeline_phone"
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
                                <Label for="timeline_telegram">Telegram</Label>
                                <Input
                                    id="timeline_telegram"
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

                    <section class="border-b px-5 py-5">
                        <div class="mb-4 flex items-center gap-2">
                            <Clock3 class="size-4 text-muted-foreground" />
                            <h3 class="font-semibold">{{ t('Rental') }}</h3>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label for="timeline_booking_start">{{
                                    t('Start date')
                                }}</Label>
                                <AdminDateInput
                                    id="timeline_booking_start"
                                    v-model="form.starts_on"
                                    class="mt-2"
                                />
                                <InputError
                                    class="mt-1"
                                    :message="form.errors.starts_on"
                                />
                            </div>
                            <div>
                                <Label for="timeline_booking_end">{{
                                    t('End date')
                                }}</Label>
                                <AdminDateInput
                                    id="timeline_booking_end"
                                    v-model="form.ends_on"
                                    class="mt-2"
                                />
                                <InputError
                                    class="mt-1"
                                    :message="form.errors.ends_on"
                                />
                            </div>
                            <div>
                                <Label for="timeline_pickup_time">{{
                                    t('Pickup time')
                                }}</Label>
                                <Input
                                    id="timeline_pickup_time"
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
                                <Label for="timeline_return_time">{{
                                    t('Return time')
                                }}</Label>
                                <Input
                                    id="timeline_return_time"
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
                                <Label for="timeline_source">{{
                                    t('Source')
                                }}</Label>
                                <AdminSelect
                                    id="timeline_source"
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
                                        {
                                            value: 'telegram',
                                            label: 'Telegram',
                                        },
                                        {
                                            value: 'admin_manual',
                                            label: t('Manual'),
                                        },
                                    ]"
                                />
                            </div>
                            <div>
                                <Label for="timeline_initial_status">{{
                                    t('Initial status')
                                }}</Label>
                                <AdminSelect
                                    id="timeline_initial_status"
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

                    <section class="border-b px-5 py-5">
                        <div class="flex items-center gap-2">
                            <Calculator class="size-4 text-muted-foreground" />
                            <h3 class="font-semibold">
                                {{ t('Price preview') }}
                            </h3>
                        </div>
                        <div
                            v-if="quoteLoading"
                            class="mt-4 flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <span
                                class="size-4 animate-spin rounded-full border-2 border-muted-foreground border-t-transparent"
                            />
                            {{ t('Calculating') }}
                        </div>
                        <div
                            v-else-if="quoteError"
                            class="mt-4 flex items-center justify-between gap-3 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                        >
                            <span class="flex gap-2">
                                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                                {{ quoteError }}
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
                        <div v-else-if="quote" class="mt-4 space-y-4">
                            <div class="flex items-end justify-between gap-3">
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
                                <span class="text-sm text-muted-foreground">
                                    {{
                                        t('Days count', {
                                            count: quote.total_days,
                                        })
                                    }}
                                    · {{ t(quote.tier_key) }}
                                </span>
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
                    </section>

                    <details class="group px-5 py-5">
                        <summary class="cursor-pointer font-semibold">
                            {{ t('Notes and manual price') }}
                        </summary>
                        <div class="mt-4 grid gap-4">
                            <div>
                                <Label for="timeline_client_comment">{{
                                    t('Customer comment')
                                }}</Label>
                                <textarea
                                    id="timeline_client_comment"
                                    v-model="form.client_comment"
                                    class="admin-textarea mt-2"
                                    rows="3"
                                />
                            </div>
                            <div>
                                <Label for="timeline_admin_note">{{
                                    t('Internal note')
                                }}</Label>
                                <textarea
                                    id="timeline_admin_note"
                                    v-model="form.admin_note"
                                    class="admin-textarea mt-2"
                                    rows="3"
                                />
                            </div>
                            <div>
                                <Label for="timeline_deposit_note">{{
                                    t('Payment / deposit note')
                                }}</Label>
                                <Input
                                    id="timeline_deposit_note"
                                    v-model="form.deposit_note"
                                    class="mt-2"
                                />
                            </div>
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
                            <template v-if="manualPrice">
                                <div>
                                    <Label for="timeline_manual_total">{{
                                        t('Final total, THB')
                                    }}</Label>
                                    <Input
                                        id="timeline_manual_total"
                                        v-model="form.manual_total"
                                        class="mt-2"
                                        type="number"
                                        min="0"
                                        step="1"
                                    />
                                    <InputError
                                        class="mt-1"
                                        :message="form.errors.manual_total"
                                    />
                                </div>
                                <div>
                                    <Label for="timeline_override_reason">{{
                                        t('Reason')
                                    }}</Label>
                                    <textarea
                                        id="timeline_override_reason"
                                        v-model="form.override_reason"
                                        class="admin-textarea mt-2"
                                        rows="3"
                                    />
                                    <InputError
                                        class="mt-1"
                                        :message="form.errors.override_reason"
                                    />
                                </div>
                            </template>
                        </div>
                    </details>
                </div>

                <div class="shrink-0 border-t bg-background px-5 py-4">
                    <div
                        v-if="domainError('booking')"
                        class="mb-3 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        {{ domainError('booking') }}
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="close"
                        >
                            {{ t('Cancel') }}
                        </Button>
                        <Button
                            type="submit"
                            :disabled="
                                form.processing ||
                                quoteLoading ||
                                quoteAvailable !== true
                            "
                        >
                            <Save />
                            {{
                                t(
                                    form.processing
                                        ? 'Saving…'
                                        : 'Create booking',
                                )
                            }}
                        </Button>
                    </div>
                </div>
            </form>
        </SheetContent>
    </Sheet>
</template>
