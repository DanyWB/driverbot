<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminSelect from '@/components/AdminSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import type { BookingDetail } from '@/types';

const props = defineProps<{
    booking: BookingDetail;
    returnTo: string | null;
}>();
const { t } = useLocale();
const payments = ['prepayment', 'deposit'] as const;
const labels = { prepayment: 'Prepayment', deposit: 'Security deposit' };
function defaults() {
    return {
        expected_details_version: props.booking.details_version,
        contact_name: props.booking.customer.name,
        contact_phone: props.booking.customer.phone ?? '',
        contact_telegram: props.booking.customer.telegram ?? '',
        payments: Object.fromEntries(
            payments.map((key) => [
                key,
                {
                    amount: props.booking.payments[key].amount ?? '',
                    currency:
                        props.booking.payments[key].currency ??
                        (key === 'prepayment' ? 'THB' : 'USD'),
                    received: props.booking.payments[key].received,
                },
            ]),
        ) as Record<
            (typeof payments)[number],
            { amount: string; currency: string; received: boolean }
        >,
    };
}
const form = useForm(defaults());
const saved = ref(false);
function submit(): void {
    saved.value = false;
    const query = props.returnTo
        ? `?${new URLSearchParams({ return_to: props.returnTo })}`
        : '';
    form.transform((data) => ({
        ...data,
        payments: Object.fromEntries(
            payments.map((key) => [
                key,
                {
                    ...data.payments[key],
                    amount: data.payments[key].amount.trim() || null,
                },
            ]),
        ),
    })).patch(`/bookings/${props.booking.public_id}/details${query}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(defaults());
            form.reset();
            saved.value = true;
        },
    });
}
</script>

<template>
    <section
        class="space-y-5 px-4 py-6 sm:px-6 lg:px-8"
        aria-labelledby="booking-details-editor-title"
    >
        <div>
            <h2 id="booking-details-editor-title" class="font-semibold">
                {{ t('Booking contacts and payments') }}
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{
                    t(
                        'These contacts apply only to this booking. The customer profile and Telegram delivery address do not change.',
                    )
                }}
            </p>
        </div>
        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <Label for="booking-contact-name">{{
                        t('Full name')
                    }}</Label
                    ><Input
                        id="booking-contact-name"
                        v-model="form.contact_name"
                        class="mt-2"
                        maxlength="255"
                        :disabled="form.processing"
                    />
                </div>
                <div>
                    <Label for="booking-contact-phone">{{ t('Phone') }}</Label
                    ><Input
                        id="booking-contact-phone"
                        v-model="form.contact_phone"
                        class="mt-2"
                        type="tel"
                        maxlength="80"
                        :disabled="form.processing"
                    />
                </div>
                <div>
                    <Label for="booking-contact-telegram">Telegram</Label
                    ><Input
                        id="booking-contact-telegram"
                        v-model="form.contact_telegram"
                        class="mt-2"
                        maxlength="80"
                        :disabled="form.processing"
                    />
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <fieldset
                    v-for="payment in payments"
                    :key="payment"
                    class="space-y-3 rounded-lg border p-4"
                >
                    <legend class="px-1 font-semibold">
                        {{ t(labels[payment]) }}
                    </legend>
                    <div class="grid grid-cols-[minmax(0,1fr)_100px] gap-3">
                        <div>
                            <Label :for="`booking-${payment}-amount`">{{
                                t('Required amount')
                            }}</Label
                            ><Input
                                :id="`booking-${payment}-amount`"
                                v-model="form.payments[payment].amount"
                                inputmode="decimal"
                                class="mt-2"
                                :disabled="form.processing"
                            />
                        </div>
                        <div>
                            <Label :for="`booking-${payment}-currency`">{{
                                t('Currency')
                            }}</Label
                            ><AdminSelect
                                :id="`booking-${payment}-currency`"
                                class="mt-2"
                                :model-value="form.payments[payment].currency"
                                :options="[
                                    { value: 'THB', label: 'THB' },
                                    { value: 'USD', label: 'USD' },
                                ]"
                                :disabled="form.processing"
                                @update:model-value="
                                    form.payments[payment].currency =
                                        String($event)
                                "
                            />
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="form.payments[payment].received"
                            type="checkbox"
                            :disabled="form.processing"
                        />{{ t('Received') }}</label
                    >
                </fieldset>
            </div>
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Changing an amount or currency clears its previous receipt mark. Save, then mark the corrected amount as received if appropriate. No automatic payment or booking confirmation.',
                    )
                }}
            </p>
            <div
                v-if="form.hasErrors"
                role="alert"
                class="space-y-1 text-sm text-destructive"
            >
                <p v-for="(message, field) in form.errors" :key="field">
                    {{ t(message ?? '') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    :disabled="form.processing || !form.isDirty"
                    >{{
                        form.processing
                            ? t('Saving…')
                            : t('Save booking details')
                    }}</Button
                ><span
                    v-if="saved"
                    role="status"
                    class="text-sm text-emerald-600"
                    >{{ t('Saved') }}</span
                >
            </div>
        </form>
    </section>
</template>
