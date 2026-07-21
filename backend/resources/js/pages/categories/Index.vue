<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, Plus, Tags } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CategoryItem } from '@/types';

defineProps<{ categories: CategoryItem[]; types: string[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Fleet', href: '/vehicles' },
            { title: 'Categories', href: '/categories' },
        ],
    },
});

const open = ref(false);
const editing = ref<CategoryItem | null>(null);
const form = useForm({
    code: '',
    name: '',
    vehicle_type: '',
    description: '',
    sort_order: 0,
    is_active: true,
});

function createCategory(): void {
    editing.value = null;
    form.clearErrors();
    Object.assign(form, {
        code: '',
        name: '',
        vehicle_type: '',
        description: '',
        sort_order: 0,
        is_active: true,
    });
    open.value = true;
}

function editCategory(category: CategoryItem): void {
    editing.value = category;
    form.clearErrors();
    Object.assign(form, {
        code: category.code,
        name: category.name,
        vehicle_type: category.vehicle_type ?? '',
        description: category.description ?? '',
        sort_order: category.sort_order,
        is_active: category.is_active,
    });
    open.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (editing.value) {
        form.patch(`/categories/${editing.value.id}`, options);
    } else {
        form.post('/categories', options);
    }
}
</script>

<template>
    <Head title="Categories" />
    <div class="flex min-w-0 flex-1 flex-col">
        <header
            class="flex flex-col gap-4 border-b px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
        >
            <div class="flex items-center gap-3">
                <Button
                    as-child
                    variant="ghost"
                    size="icon"
                    title="Back to fleet"
                    ><Link href="/vehicles"
                        ><ArrowLeft /><span class="sr-only">Back</span></Link
                    ></Button
                >
                <div>
                    <p class="text-sm text-muted-foreground">Fleet catalog</p>
                    <h1 class="text-2xl font-semibold">Categories</h1>
                </div>
            </div>
            <Button @click="createCategory"><Plus />Add category</Button>
        </header>

        <section class="min-w-0 flex-1">
            <div
                v-if="categories.length === 0"
                class="flex min-h-72 flex-col items-center justify-center px-6 text-center"
            >
                <Tags class="mb-3 size-8 text-muted-foreground" />
                <h2 class="font-medium">No categories</h2>
                <Button class="mt-4" variant="outline" @click="createCategory"
                    ><Plus />Add category</Button
                >
            </div>
            <template v-else>
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead
                            class="border-b bg-muted/35 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium lg:px-6">
                                    Category
                                </th>
                                <th class="px-4 py-3 font-medium">Type</th>
                                <th class="px-4 py-3 font-medium">State</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Vehicles
                                </th>
                                <th class="w-14 px-4 py-3">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="category in categories"
                                :key="category.id"
                                class="hover:bg-muted/25"
                            >
                                <td class="px-4 py-3 lg:px-6">
                                    <p class="font-medium">
                                        {{ category.name }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ category.code }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 capitalize">
                                    {{ category.vehicle_type ?? 'Any' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        :class="
                                            category.is_active
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                                : 'text-muted-foreground'
                                        "
                                        class="rounded border px-2 py-0.5 text-xs"
                                        >{{
                                            category.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}</span
                                    >
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ category.vehicles_count }}
                                    <p class="text-xs text-muted-foreground">
                                        {{ category.visible_vehicles_count }}
                                        published
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title="Edit category"
                                        @click="editCategory(category)"
                                        ><Pencil /><span class="sr-only"
                                            >Edit</span
                                        ></Button
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="divide-y md:hidden">
                    <button
                        v-for="category in categories"
                        :key="category.id"
                        type="button"
                        class="flex w-full items-start justify-between gap-3 px-4 py-4 text-left hover:bg-muted/30"
                        @click="editCategory(category)"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ category.name }}
                            </p>
                            <p
                                class="mt-0.5 text-sm text-muted-foreground capitalize"
                            >
                                {{ category.vehicle_type ?? 'Any type' }} ·
                                {{ category.code }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right text-sm tabular-nums">
                            <p>{{ category.vehicles_count }}</p>
                            <p class="text-xs text-muted-foreground">
                                vehicles
                            </p>
                        </div>
                    </button>
                </div>
            </template>
        </section>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader
                ><DialogTitle>{{
                    editing ? 'Edit category' : 'New category'
                }}</DialogTitle
                ><DialogDescription>{{
                    editing
                        ? `${editing.vehicles_count} assigned vehicles`
                        : 'Catalog grouping for vehicles'
                }}</DialogDescription></DialogHeader
            >
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <Label for="category_name">Name</Label
                    ><Input
                        id="category_name"
                        v-model="form.name"
                        class="mt-2"
                    /><InputError class="mt-1" :message="form.errors.name" />
                </div>
                <div>
                    <Label for="category_code">Code</Label
                    ><Input
                        id="category_code"
                        v-model="form.code"
                        class="mt-2"
                        placeholder="Generated when left empty"
                    /><InputError class="mt-1" :message="form.errors.code" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label for="category_type">Vehicle type</Label
                        ><select
                            id="category_type"
                            v-model="form.vehicle_type"
                            class="admin-select mt-2 w-full capitalize"
                        >
                            <option value="">Any type</option>
                            <option
                                v-for="type in types"
                                :key="type"
                                :value="type"
                            >
                                {{ type }}
                            </option></select
                        ><InputError
                            class="mt-1"
                            :message="form.errors.vehicle_type"
                        />
                    </div>
                    <div>
                        <Label for="category_order">Sort order</Label
                        ><Input
                            id="category_order"
                            v-model.number="form.sort_order"
                            type="number"
                            min="0"
                            class="mt-2"
                        /><InputError
                            class="mt-1"
                            :message="form.errors.sort_order"
                        />
                    </div>
                </div>
                <div>
                    <Label for="category_description">Description</Label
                    ><textarea
                        id="category_description"
                        v-model="form.description"
                        class="admin-textarea mt-2"
                    /><InputError
                        class="mt-1"
                        :message="form.errors.description"
                    />
                </div>
                <label
                    class="flex items-center gap-3 rounded-md border px-4 py-3"
                    ><input
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-4 accent-current"
                    /><span class="text-sm font-medium">Active</span></label
                >
                <InputError :message="form.errors.is_active" />
                <DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >Cancel</Button
                    ><Button type="submit" :disabled="form.processing">{{
                        editing ? 'Save category' : 'Create category'
                    }}</Button></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>
</template>
