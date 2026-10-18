<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Eye, EyeOff, Save } from '@lucide/vue';
import { computed, watch } from 'vue';
import AdminSelect from '@/components/AdminSelect.vue';
import InputError from '@/components/InputError.vue';
import VehicleLifecycleActions from '@/components/vehicles/VehicleLifecycleActions.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import type { CategoryOption, VehicleDetail } from '@/types';

const props = defineProps<{
    vehicle?: VehicleDetail;
    types: string[];
    categories: CategoryOption[];
    returnTo?: string | null;
}>();

const editing = computed(() => props.vehicle !== undefined);
const { t } = useLocale();
const form = useForm({
    external_code: props.vehicle?.external_code ?? '',
    type: props.vehicle?.type ?? 'scooter',
    category_id: props.vehicle?.category?.id ?? (null as number | null),
    name: props.vehicle?.name ?? '',
    inventory_code: props.vehicle?.inventory_code ?? '',
    year: props.vehicle?.year ?? ('' as number | string),
    description: props.vehicle?.description ?? '',
    description_ru: props.vehicle?.description_ru ?? '',
    description_ua: props.vehicle?.description_ua ?? '',
    characteristics_text: props.vehicle?.characteristics_text ?? '',
    emoji: props.vehicle?.emoji ?? '',
    is_active: props.vehicle?.is_active ?? true,
    is_visible_for_booking: props.vehicle?.is_visible_for_booking ?? false,
    sort_order: props.vehicle?.sort_order ?? 0,
});

const availableCategories = computed(() =>
    props.categories.filter(
        (category) => !category.type || category.type === form.type,
    ),
);
const publishProblem = computed(() => {
    if (props.vehicle?.archived_at) {
        return 'This vehicle is archived';
    }

    if (!form.is_active) {
        return 'Activate the vehicle before publishing.';
    }

    if (
        !props.categories.find(
            (category) => category.id === Number(form.category_id),
        )?.is_active
    ) {
        return 'Select an active category before publishing.';
    }

    if (!props.vehicle?.has_complete_pricing) {
        return 'Complete all 15 prices before publishing.';
    }

    return '';
});

watch(
    () => form.type,
    () => {
        if (
            form.category_id &&
            !availableCategories.value.some(
                (category) => category.id === Number(form.category_id),
            )
        ) {
            form.category_id = null;
        }
    },
);

watch(
    () => form.is_active,
    (active) => {
        if (!active) {
            form.is_visible_for_booking = false;
        }
    },
);

function submit(): void {
    if (props.vehicle) {
        form.patch(`/vehicles/${props.vehicle.id}`, {
            preserveScroll: true,
        });

        return;
    }

    form.post('/vehicles');
}
</script>

<template>
    <form class="divide-y" @submit.prevent="submit">
        <section
            class="space-y-4 bg-muted/20 px-4 py-5 sm:px-6 lg:px-8"
            aria-labelledby="vehicle-availability-title"
        >
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="vehicle-availability-title" class="font-semibold">
                        {{ t('Availability controls') }}
                    </h2>
                    <p
                        class="mt-1 text-sm"
                        :class="
                            form.is_visible_for_booking
                                ? 'text-emerald-700 dark:text-emerald-400'
                                : 'text-muted-foreground'
                        "
                    >
                        {{
                            t(
                                form.is_visible_for_booking
                                    ? 'Published to client channels.'
                                    : 'Hidden from clients.',
                            )
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        :variant="
                            form.is_visible_for_booking ? 'outline' : 'default'
                        "
                        :disabled="
                            form.processing ||
                            (!form.is_visible_for_booking && !!publishProblem)
                        "
                        :aria-pressed="form.is_visible_for_booking"
                        @click="
                            form.is_visible_for_booking =
                                !form.is_visible_for_booking
                        "
                    >
                        <EyeOff v-if="form.is_visible_for_booking" /><Eye
                            v-else
                        />{{
                            t(
                                form.is_visible_for_booking
                                    ? 'Unpublish vehicle'
                                    : 'Publish vehicle',
                            )
                        }}
                    </Button>
                    <Button type="submit" :disabled="form.processing"
                        ><Save />{{
                            t(editing ? 'Save details' : 'Create vehicle')
                        }}</Button
                    >
                </div>
            </div>
            <p
                v-if="publishProblem && !form.is_visible_for_booking"
                class="text-sm text-muted-foreground"
            >
                {{ t(publishProblem) }}
            </p>
            <label
                class="flex items-start gap-3 rounded-lg border bg-background p-3"
            >
                <input
                    v-model="form.is_active"
                    type="checkbox"
                    class="mt-1 size-4 accent-current"
                    :disabled="
                        !!vehicle?.archived_at ||
                        (!!vehicle?.is_active &&
                            vehicle.unfinished_bookings_count > 0)
                    "
                />
                <span
                    ><span class="block text-sm font-medium">{{
                        t('Active for operations')
                    }}</span
                    ><span class="text-xs text-muted-foreground">{{
                        t(
                            'Operational state is separate from publication and availability on selected dates.',
                        )
                    }}</span></span
                >
            </label>
            <p
                class="text-sm"
                :class="
                    form.isDirty
                        ? 'text-amber-700 dark:text-amber-400'
                        : 'text-muted-foreground'
                "
                aria-live="polite"
            >
                {{
                    t(
                        form.isDirty
                            ? 'Unsaved changes. Save to apply publication and details.'
                            : 'Publication changes apply when you save details.',
                    )
                }}
            </p>
            <InputError
                :message="
                    form.errors.is_active || form.errors.is_visible_for_booking
                "
            />
            <VehicleLifecycleActions
                v-if="vehicle"
                :vehicle="vehicle"
                :disabled="form.isDirty || form.processing"
                hide-publication
            />
        </section>
        <section class="grid gap-5 px-4 py-6 sm:px-6 lg:grid-cols-2 lg:px-8">
            <div class="lg:col-span-2">
                <h2 class="font-semibold">{{ t('Catalog details') }}</h2>
            </div>

            <div>
                <Label for="vehicle_name">{{ t('Name') }}</Label>
                <Input
                    id="vehicle_name"
                    v-model="form.name"
                    class="mt-2"
                    autocomplete="off"
                    :placeholder="
                        form.type === 'car'
                            ? t('e.g. Toyota Yaris Automatic')
                            : t('e.g. Honda Click 160 ABS Black')
                    "
                />
                <InputError class="mt-1" :message="form.errors.name" />
            </div>

            <div>
                <Label for="vehicle_external_code">{{
                    t('Catalog code')
                }}</Label>
                <Input
                    id="vehicle_external_code"
                    v-model="form.external_code"
                    class="mt-2"
                    autocomplete="off"
                    :placeholder="t('Generated when left empty')"
                />
                <p class="mt-1 text-xs text-muted-foreground">
                    {{
                        t('Stable technical code for imports and integrations.')
                    }}
                </p>
                <InputError class="mt-1" :message="form.errors.external_code" />
            </div>

            <div>
                <Label for="vehicle_type">{{ t('Type') }}</Label>
                <AdminSelect
                    id="vehicle_type"
                    v-model="form.type"
                    class="mt-2 capitalize"
                    :options="
                        types.map((type) => ({
                            value: type,
                            label: t(type),
                        }))
                    "
                />
                <InputError class="mt-1" :message="form.errors.type" />
            </div>

            <div>
                <div class="flex items-center justify-between gap-3">
                    <Label for="vehicle_category">{{ t('Category') }}</Label>
                    <Link
                        href="/categories"
                        class="text-xs text-muted-foreground hover:text-foreground hover:underline"
                        >{{ t('Manage') }}</Link
                    >
                </div>
                <AdminSelect
                    id="vehicle_category"
                    v-model="form.category_id"
                    class="mt-2"
                    :options="[
                        { value: null, label: t('No category') },
                        ...availableCategories.map((category) => ({
                            value: category.id,
                            label:
                                category.name +
                                (category.is_active
                                    ? ''
                                    : ` · ${t('inactive')}`),
                        })),
                    ]"
                />
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ t('Groups vehicles in the bot and client catalog.') }}
                </p>
                <InputError class="mt-1" :message="form.errors.category_id" />
            </div>

            <div>
                <Label for="vehicle_inventory_code">{{
                    t('Inventory code')
                }}</Label>
                <Input
                    id="vehicle_inventory_code"
                    v-model="form.inventory_code"
                    class="mt-2"
                    autocomplete="off"
                    :placeholder="t('e.g. SC-017 or CAR-05')"
                />
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ t('Unique code of this physical vehicle.') }}
                </p>
                <InputError
                    class="mt-1"
                    :message="form.errors.inventory_code"
                />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <Label for="vehicle_year">{{ t('Year') }}</Label>
                    <Input
                        id="vehicle_year"
                        v-model.number="form.year"
                        type="number"
                        min="1900"
                        max="2200"
                        class="mt-2"
                        placeholder="2024"
                    />
                    <InputError class="mt-1" :message="form.errors.year" />
                </div>
                <div>
                    <Label for="vehicle_sort_order">{{
                        t('Sort order')
                    }}</Label>
                    <Input
                        id="vehicle_sort_order"
                        v-model.number="form.sort_order"
                        type="number"
                        min="0"
                        class="mt-2"
                        placeholder="10"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ t('Lower values appear first.') }}
                    </p>
                    <InputError
                        class="mt-1"
                        :message="form.errors.sort_order"
                    />
                </div>
            </div>

            <div>
                <Label for="vehicle_emoji">{{ t('Bot icon') }}</Label>
                <Input
                    id="vehicle_emoji"
                    v-model="form.emoji"
                    class="mt-2"
                    autocomplete="off"
                    :placeholder="form.type === 'car' ? '🚗' : '🛵'"
                />
                <p class="mt-1 text-xs text-muted-foreground">
                    {{
                        t('Optional emoji; selected automatically when empty.')
                    }}
                </p>
                <InputError class="mt-1" :message="form.errors.emoji" />
            </div>
        </section>

        <section class="grid gap-5 px-4 py-6 sm:px-6 lg:grid-cols-2 lg:px-8">
            <div class="lg:col-span-2">
                <h2 class="font-semibold">
                    {{ t('Customer-facing content') }}
                </h2>
            </div>
            <div>
                <Label for="vehicle_description">{{
                    t('Description — English')
                }}</Label>
                <textarea
                    id="vehicle_description"
                    v-model="form.description"
                    class="admin-textarea mt-2 min-h-32"
                    :placeholder="
                        t(
                            'Short customer-facing description of the vehicle and its condition.',
                        )
                    "
                />
                <InputError class="mt-1" :message="form.errors.description" />
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        t(
                            'The bot uses the customer language. Empty translations fall back to English. Translations are entered manually.',
                        )
                    }}
                </p>
            </div>
            <div>
                <Label for="vehicle_description_ru">{{
                    t('Description — Russian')
                }}</Label>
                <textarea
                    id="vehicle_description_ru"
                    v-model="form.description_ru"
                    class="admin-textarea mt-2 min-h-32"
                />
                <InputError
                    class="mt-1"
                    :message="form.errors.description_ru"
                />
            </div>
            <div>
                <Label for="vehicle_description_ua">{{
                    t('Description — Ukrainian')
                }}</Label>
                <textarea
                    id="vehicle_description_ua"
                    v-model="form.description_ua"
                    class="admin-textarea mt-2 min-h-32"
                />
                <InputError
                    class="mt-1"
                    :message="form.errors.description_ua"
                />
            </div>
            <div>
                <Label for="vehicle_characteristics">{{
                    t('Characteristics')
                }}</Label>
                <textarea
                    id="vehicle_characteristics"
                    v-model="form.characteristics_text"
                    class="admin-textarea mt-2 min-h-32"
                    :placeholder="
                        form.type === 'car'
                            ? t(
                                  'e.g. Automatic, 5 seats, air conditioning, fuel type',
                              )
                            : t('e.g. 160 cc, ABS, 2 seats, helmet included')
                    "
                />
                <InputError
                    class="mt-1"
                    :message="form.errors.characteristics_text"
                />
            </div>
        </section>

        <footer
            class="flex flex-col-reverse gap-3 px-4 py-4 sm:flex-row sm:justify-end sm:px-6 lg:px-8"
        >
            <Button as-child type="button" variant="outline">
                <Link :href="returnTo || '/vehicles'">{{ t('Cancel') }}</Link>
            </Button>
            <Button type="submit" :disabled="form.processing">
                <Save />{{ t(editing ? 'Save details' : 'Create vehicle') }}
            </Button>
        </footer>
    </form>
</template>
