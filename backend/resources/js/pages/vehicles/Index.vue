<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Bike,
    CircleOff,
    Eye,
    Image,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    Settings2,
    Tags,
    TriangleAlert,
} from '@lucide/vue';
import { reactive } from 'vue';
import AdminPagination from '@/components/AdminPagination.vue';
import AdminSelect from '@/components/AdminSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { formatDateTime } from '@/lib/bookings';
import type { CategoryOption, PaginatedVehicles } from '@/types';

type Filters = {
    search: string;
    type: string;
    category_id: number | null;
    state: string;
    visibility: string;
    pricing: string;
    per_page: number;
};

const props = defineProps<{
    vehicles: PaginatedVehicles;
    filters: Filters;
    summary: {
        total: number;
        active: number;
        visible: number;
        incomplete_pricing: number;
    };
    options: {
        types: string[];
        categories: CategoryOption[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Fleet', href: '/vehicles' }],
    },
});

const filters = reactive<Filters>({ ...props.filters });
const { t } = useLocale();

function query(): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) => value !== '' && value !== null && value !== 'all',
        ),
    ) as Record<string, string | number>;
}

function applyFilters(): void {
    router.get('/vehicles', query(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function resetFilters(): void {
    Object.assign(filters, {
        search: '',
        type: '',
        category_id: null,
        state: 'all',
        visibility: 'all',
        pricing: 'all',
        per_page: 25,
    });
    applyFilters();
}
</script>

<template>
    <Head :title="t('Fleet')" />
    <div class="flex min-w-0 flex-1 flex-col">
        <header
            class="flex flex-col gap-4 border-b px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
        >
            <div>
                <p class="text-sm text-muted-foreground">
                    {{ t('Catalog and pricing') }}
                </p>
                <h1 class="text-2xl font-semibold">{{ t('Fleet') }}</h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline">
                    <Link href="/categories"
                        ><Tags />{{ t('Categories') }}</Link
                    >
                </Button>
                <Button as-child>
                    <Link href="/vehicles/create"
                        ><Plus />{{ t('Add vehicle') }}</Link
                    >
                </Button>
            </div>
        </header>

        <section class="grid border-b sm:grid-cols-2 xl:grid-cols-4">
            <div
                class="flex items-center gap-3 border-b px-4 py-4 sm:border-r lg:px-6 xl:border-b-0"
            >
                <Bike class="size-5 text-cyan-700" />
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
                class="flex items-center gap-3 border-b px-4 py-4 lg:px-6 xl:border-r xl:border-b-0"
            >
                <Settings2 class="size-5 text-emerald-700" />
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Active') }}
                    </p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.active }}
                    </p>
                </div>
            </div>
            <div
                class="flex items-center gap-3 border-b px-4 py-4 sm:border-r sm:border-b-0 lg:px-6"
            >
                <Eye class="size-5 text-blue-700" />
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Published') }}
                    </p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.visible }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3 px-4 py-4 lg:px-6">
                <TriangleAlert class="size-5 text-amber-700" />
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Incomplete prices') }}
                    </p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ summary.incomplete_pricing }}
                    </p>
                </div>
            </div>
        </section>

        <form
            class="grid gap-3 border-b px-4 py-4 sm:grid-cols-2 sm:px-6 lg:px-8 xl:grid-cols-7"
            @submit.prevent="applyFilters"
        >
            <label class="relative sm:col-span-2">
                <span class="sr-only">{{ t('Search fleet') }}</span>
                <Search
                    class="absolute top-2.5 left-3 size-4 text-muted-foreground"
                />
                <Input
                    v-model="filters.search"
                    class="pl-9"
                    :placeholder="t('Name, catalog or inventory code')"
                />
            </label>
            <AdminSelect
                v-model="filters.type"
                class="capitalize"
                :aria-label="t('Vehicle type')"
                :options="[
                    { value: '', label: t('All types') },
                    ...options.types.map((type) => ({
                        value: type,
                        label: t(type),
                    })),
                ]"
            />
            <AdminSelect
                v-model="filters.category_id"
                :aria-label="t('Category')"
                :options="[
                    { value: null, label: t('All categories') },
                    ...options.categories.map((category) => ({
                        value: category.id,
                        label: category.name,
                    })),
                ]"
            />
            <AdminSelect
                v-model="filters.state"
                :aria-label="t('Active state')"
                :options="[
                    { value: 'all', label: t('Any state') },
                    { value: 'active', label: t('Active') },
                    { value: 'inactive', label: t('Inactive') },
                ]"
            />
            <AdminSelect
                v-model="filters.visibility"
                :aria-label="t('Visibility')"
                :options="[
                    { value: 'all', label: t('Any visibility') },
                    { value: 'visible', label: t('Published') },
                    { value: 'hidden', label: t('Hidden') },
                ]"
            />
            <AdminSelect
                v-model="filters.pricing"
                :aria-label="t('Pricing completeness')"
                :options="[
                    { value: 'all', label: t('Any pricing') },
                    { value: 'complete', label: t('Complete prices') },
                    {
                        value: 'incomplete',
                        label: t('Incomplete prices'),
                    },
                ]"
            />
            <div class="flex gap-2 sm:col-span-2 xl:col-start-6">
                <Button type="submit" class="flex-1"
                    ><Search />{{ t('Apply') }}</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    :title="t('Reset filters')"
                    @click="resetFilters"
                    ><RotateCcw /><span class="sr-only">{{
                        t('Reset filters')
                    }}</span></Button
                >
            </div>
        </form>

        <section class="min-w-0 flex-1">
            <div
                v-if="vehicles.data.length === 0"
                class="flex min-h-72 flex-col items-center justify-center px-6 text-center"
            >
                <Bike class="mb-3 size-8 text-muted-foreground" />
                <h2 class="font-medium">{{ t('No vehicles found') }}</h2>
                <Button as-child variant="outline" class="mt-4"
                    ><Link href="/vehicles/create"
                        ><Plus />{{ t('Add vehicle') }}</Link
                    ></Button
                >
            </div>
            <template v-else>
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[980px] text-sm">
                        <thead
                            class="border-b bg-muted/35 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium lg:px-6">
                                    {{ t('Vehicle') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ t('Category') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ t('State') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ t('Pricing') }}
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    {{ t('History') }}
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    {{ t('Updated') }}
                                </th>
                                <th class="w-14 px-4 py-3">
                                    <span class="sr-only">{{
                                        t('Actions')
                                    }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="vehicle in vehicles.data"
                                :key="vehicle.id"
                                class="hover:bg-muted/25"
                            >
                                <td class="px-4 py-3 lg:px-6">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="grid h-12 w-16 shrink-0 place-items-center overflow-hidden rounded-md border bg-muted"
                                        >
                                            <img
                                                v-if="vehicle.primary_photo"
                                                :src="
                                                    vehicle.primary_photo
                                                        .thumbnail_url
                                                "
                                                :alt="
                                                    vehicle.primary_photo
                                                        .alt_text ??
                                                    vehicle.name
                                                "
                                                class="h-full w-full object-cover"
                                            />
                                            <Image
                                                v-else
                                                class="size-5 text-muted-foreground"
                                            />
                                        </div>
                                        <div class="min-w-0">
                                            <Link
                                                :href="`/vehicles/${vehicle.id}/edit`"
                                                class="font-medium hover:underline"
                                                >{{ vehicle.name }}</Link
                                            >
                                            <p
                                                class="truncate text-xs text-muted-foreground"
                                            >
                                                {{
                                                    vehicle.inventory_code ||
                                                    vehicle.external_code
                                                }}<span v-if="vehicle.year">
                                                    · {{ vehicle.year }}</span
                                                >
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <p>
                                        {{
                                            vehicle.category?.name ??
                                            t('Uncategorized')
                                        }}
                                    </p>
                                    <p
                                        class="text-xs text-muted-foreground capitalize"
                                    >
                                        {{ t(vehicle.type) }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        <span
                                            v-if="vehicle.is_active"
                                            class="rounded border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800"
                                            >{{ t('Active') }}</span
                                        >
                                        <span
                                            v-else
                                            class="inline-flex items-center gap-1 rounded border px-2 py-0.5 text-xs text-muted-foreground"
                                            ><CircleOff class="size-3" />{{
                                                t('Inactive')
                                            }}</span
                                        >
                                        <span
                                            v-if="
                                                vehicle.is_visible_for_booking
                                            "
                                            class="rounded border border-blue-200 bg-blue-50 px-2 py-0.5 text-xs text-blue-800"
                                            >{{ t('Published') }}</span
                                        >
                                        <span
                                            v-else
                                            class="rounded border px-2 py-0.5 text-xs text-muted-foreground"
                                            >{{ t('Hidden') }}</span
                                        >
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        :class="
                                            vehicle.has_complete_pricing
                                                ? 'text-emerald-700'
                                                : 'text-amber-700'
                                        "
                                        class="font-medium tabular-nums"
                                        >{{
                                            vehicle.active_price_tiers_count
                                        }}/15</span
                                    >
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            t('Photos count', {
                                                count: vehicle.photos_count,
                                            })
                                        }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ vehicle.bookings_count }}
                                    <p class="text-xs text-muted-foreground">
                                        {{ t('bookings') }}
                                    </p>
                                </td>
                                <td
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    {{ formatDateTime(vehicle.updated_at) }}
                                </td>
                                <td class="px-4 py-3">
                                    <Button
                                        as-child
                                        variant="ghost"
                                        size="icon"
                                        :title="t('Edit vehicle')"
                                        ><Link
                                            :href="`/vehicles/${vehicle.id}/edit`"
                                            ><Pencil /><span class="sr-only">{{
                                                t('Edit')
                                            }}</span></Link
                                        ></Button
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="divide-y md:hidden">
                    <Link
                        v-for="vehicle in vehicles.data"
                        :key="vehicle.id"
                        :href="`/vehicles/${vehicle.id}/edit`"
                        class="flex gap-3 px-4 py-4 hover:bg-muted/30"
                    >
                        <div
                            class="grid h-14 w-20 shrink-0 place-items-center overflow-hidden rounded-md border bg-muted"
                        >
                            <img
                                v-if="vehicle.primary_photo"
                                :src="vehicle.primary_photo.thumbnail_url"
                                :alt="
                                    vehicle.primary_photo.alt_text ??
                                    vehicle.name
                                "
                                class="h-full w-full object-cover"
                            /><Image
                                v-else
                                class="size-5 text-muted-foreground"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <p class="truncate font-medium">
                                    {{ vehicle.name }}
                                </p>
                                <span
                                    :class="
                                        vehicle.has_complete_pricing
                                            ? 'text-emerald-700'
                                            : 'text-amber-700'
                                    "
                                    class="shrink-0 text-xs font-medium"
                                    >{{
                                        vehicle.active_price_tiers_count
                                    }}/15</span
                                >
                            </div>
                            <p
                                class="mt-0.5 truncate text-sm text-muted-foreground"
                            >
                                {{ vehicle.category?.name ?? vehicle.type }}
                            </p>
                            <div class="mt-2 flex gap-2 text-xs">
                                <span>{{
                                    t(vehicle.is_active ? 'Active' : 'Inactive')
                                }}</span
                                ><span>·</span
                                ><span>{{
                                    vehicle.is_visible_for_booking
                                        ? t('Published')
                                        : t('Hidden')
                                }}</span>
                            </div>
                        </div>
                    </Link>
                </div>
                <AdminPagination
                    :paginator="vehicles"
                    :label="t('Fleet pages')"
                />
            </template>
        </section>
    </div>
</template>
