<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { computed, watch } from 'vue';
import AdminSelect from '@/components/AdminSelect.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import type { CategoryOption, VehicleDetail } from '@/types';

const props = defineProps<{
    vehicle?: VehicleDetail;
    types: string[];
    categories: CategoryOption[];
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
                <Label for="vehicle_description">{{ t('Description') }}</Label>
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

        <section class="px-4 py-6 sm:px-6 lg:px-8">
            <h2 class="font-semibold">{{ t('Availability controls') }}</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <label
                    class="flex min-h-16 items-start gap-3 rounded-md border px-4 py-3"
                >
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="mt-0.5 size-4 accent-current"
                    />
                    <span>
                        <span class="block text-sm font-medium">{{
                            t('Active')
                        }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            t('Available to operations.')
                        }}</span>
                    </span>
                </label>
                <label
                    class="flex min-h-16 items-start gap-3 rounded-md border px-4 py-3"
                    :class="!form.is_active ? 'opacity-60' : ''"
                >
                    <input
                        v-model="form.is_visible_for_booking"
                        type="checkbox"
                        class="mt-0.5 size-4 accent-current"
                        :disabled="!form.is_active"
                    />
                    <span>
                        <span class="block text-sm font-medium">{{
                            t('Visible for booking')
                        }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            t('Published to client channels.')
                        }}</span>
                    </span>
                </label>
            </div>
            <InputError
                class="mt-2"
                :message="
                    form.errors.is_active || form.errors.is_visible_for_booking
                "
            />
        </section>

        <footer
            class="flex flex-col-reverse gap-3 px-4 py-4 sm:flex-row sm:justify-end sm:px-6 lg:px-8"
        >
            <Button as-child type="button" variant="outline">
                <Link href="/vehicles">{{ t('Cancel') }}</Link>
            </Button>
            <Button type="submit" :disabled="form.processing">
                <Save />{{ t(editing ? 'Save details' : 'Create vehicle') }}
            </Button>
        </footer>
    </form>
</template>
