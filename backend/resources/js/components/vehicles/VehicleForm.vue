<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CategoryOption, VehicleDetail } from '@/types';

const props = defineProps<{
    vehicle?: VehicleDetail;
    types: string[];
    categories: CategoryOption[];
}>();

const editing = computed(() => props.vehicle !== undefined);
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
    pricing_profile: props.vehicle?.pricing_profile ?? '',
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
                <h2 class="font-semibold">Catalog details</h2>
            </div>

            <div>
                <Label for="vehicle_name">Name</Label>
                <Input
                    id="vehicle_name"
                    v-model="form.name"
                    class="mt-2"
                    autocomplete="off"
                />
                <InputError class="mt-1" :message="form.errors.name" />
            </div>

            <div>
                <Label for="vehicle_external_code">Catalog code</Label>
                <Input
                    id="vehicle_external_code"
                    v-model="form.external_code"
                    class="mt-2"
                    autocomplete="off"
                    placeholder="Generated when left empty"
                />
                <InputError class="mt-1" :message="form.errors.external_code" />
            </div>

            <div>
                <Label for="vehicle_type">Type</Label>
                <select
                    id="vehicle_type"
                    v-model="form.type"
                    class="admin-select mt-2 w-full capitalize"
                >
                    <option v-for="type in types" :key="type" :value="type">
                        {{ type }}
                    </option>
                </select>
                <InputError class="mt-1" :message="form.errors.type" />
            </div>

            <div>
                <div class="flex items-center justify-between gap-3">
                    <Label for="vehicle_category">Category</Label>
                    <Link
                        href="/categories"
                        class="text-xs text-muted-foreground hover:text-foreground hover:underline"
                        >Manage</Link
                    >
                </div>
                <select
                    id="vehicle_category"
                    v-model.number="form.category_id"
                    class="admin-select mt-2 w-full"
                >
                    <option :value="null">No category</option>
                    <option
                        v-for="category in availableCategories"
                        :key="category.id"
                        :value="category.id"
                    >
                        {{ category.name
                        }}{{ category.is_active ? '' : ' · inactive' }}
                    </option>
                </select>
                <InputError class="mt-1" :message="form.errors.category_id" />
            </div>

            <div>
                <Label for="vehicle_inventory_code">Inventory code</Label>
                <Input
                    id="vehicle_inventory_code"
                    v-model="form.inventory_code"
                    class="mt-2"
                    autocomplete="off"
                />
                <InputError
                    class="mt-1"
                    :message="form.errors.inventory_code"
                />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <Label for="vehicle_year">Year</Label>
                    <Input
                        id="vehicle_year"
                        v-model.number="form.year"
                        type="number"
                        min="1900"
                        max="2200"
                        class="mt-2"
                    />
                    <InputError class="mt-1" :message="form.errors.year" />
                </div>
                <div>
                    <Label for="vehicle_sort_order">Sort order</Label>
                    <Input
                        id="vehicle_sort_order"
                        v-model.number="form.sort_order"
                        type="number"
                        min="0"
                        class="mt-2"
                    />
                    <InputError
                        class="mt-1"
                        :message="form.errors.sort_order"
                    />
                </div>
            </div>

            <div>
                <Label for="vehicle_pricing_profile">Pricing profile</Label>
                <Input
                    id="vehicle_pricing_profile"
                    v-model="form.pricing_profile"
                    class="mt-2"
                    autocomplete="off"
                />
                <InputError
                    class="mt-1"
                    :message="form.errors.pricing_profile"
                />
            </div>

            <div>
                <Label for="vehicle_emoji">Bot label</Label>
                <Input
                    id="vehicle_emoji"
                    v-model="form.emoji"
                    class="mt-2"
                    autocomplete="off"
                />
                <InputError class="mt-1" :message="form.errors.emoji" />
            </div>
        </section>

        <section class="grid gap-5 px-4 py-6 sm:px-6 lg:grid-cols-2 lg:px-8">
            <div class="lg:col-span-2">
                <h2 class="font-semibold">Customer-facing content</h2>
            </div>
            <div>
                <Label for="vehicle_description">Description</Label>
                <textarea
                    id="vehicle_description"
                    v-model="form.description"
                    class="admin-textarea mt-2 min-h-32"
                />
                <InputError class="mt-1" :message="form.errors.description" />
            </div>
            <div>
                <Label for="vehicle_characteristics">Characteristics</Label>
                <textarea
                    id="vehicle_characteristics"
                    v-model="form.characteristics_text"
                    class="admin-textarea mt-2 min-h-32"
                />
                <InputError
                    class="mt-1"
                    :message="form.errors.characteristics_text"
                />
            </div>
        </section>

        <section class="px-4 py-6 sm:px-6 lg:px-8">
            <h2 class="font-semibold">Availability controls</h2>
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
                        <span class="block text-sm font-medium">Active</span>
                        <span class="block text-xs text-muted-foreground"
                            >Available to operations.</span
                        >
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
                        <span class="block text-sm font-medium"
                            >Visible for booking</span
                        >
                        <span class="block text-xs text-muted-foreground"
                            >Published to client channels.</span
                        >
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
                <Link href="/vehicles">Cancel</Link>
            </Button>
            <Button type="submit" :disabled="form.processing">
                <Save />{{ editing ? 'Save details' : 'Create vehicle' }}
            </Button>
        </footer>
    </form>
</template>
