<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarPlus, CircleOff, Eye } from '@lucide/vue';
import VehicleForm from '@/components/vehicles/VehicleForm.vue';
import VehiclePhotoManager from '@/components/vehicles/VehiclePhotoManager.vue';
import VehiclePricingEditor from '@/components/vehicles/VehiclePricingEditor.vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import type {
    CategoryOption,
    PricingTemplateOption,
    VehicleDetail,
} from '@/types';

defineProps<{
    vehicle: VehicleDetail;
    types: string[];
    categories: CategoryOption[];
    pricing_templates: PricingTemplateOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Fleet', href: '/vehicles' }],
    },
});

const { t } = useLocale();
</script>

<template>
    <Head :title="vehicle.name" />
    <div class="flex min-w-0 flex-1 flex-col">
        <header
            class="flex flex-col gap-4 border-b px-4 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8"
        >
            <div class="flex min-w-0 items-center gap-3">
                <Button
                    as-child
                    variant="ghost"
                    size="icon"
                    :title="t('Back to fleet')"
                    ><Link href="/vehicles"
                        ><ArrowLeft /><span class="sr-only">{{
                            t('Back')
                        }}</span></Link
                    ></Button
                >
                <div class="min-w-0">
                    <p class="truncate text-sm text-muted-foreground">
                        {{ vehicle.inventory_code || vehicle.external_code }}
                    </p>
                    <h1 class="truncate text-2xl font-semibold">
                        {{ vehicle.name }}
                    </h1>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span
                            v-if="vehicle.is_active"
                            class="rounded border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800"
                            >{{ t('Active') }}</span
                        ><span
                            v-else
                            class="inline-flex items-center gap-1 rounded border px-2 py-0.5 text-xs text-muted-foreground"
                            ><CircleOff class="size-3" />{{
                                t('Inactive')
                            }}</span
                        ><span
                            v-if="vehicle.is_visible_for_booking"
                            class="inline-flex items-center gap-1 rounded border border-blue-200 bg-blue-50 px-2 py-0.5 text-xs text-blue-800"
                            ><Eye class="size-3" />{{ t('Published') }}</span
                        ><span
                            :class="
                                vehicle.has_complete_pricing
                                    ? 'text-emerald-700'
                                    : 'text-amber-700'
                            "
                            class="rounded border px-2 py-0.5 text-xs"
                            >{{ t('Prices') }}
                            {{ vehicle.active_price_tiers_count }}/15</span
                        >
                    </div>
                </div>
            </div>
            <Button as-child variant="outline"
                ><Link :href="`/bookings/create?vehicle_id=${vehicle.id}`"
                    ><CalendarPlus />{{ t('New booking') }}</Link
                ></Button
            >
        </header>

        <nav
            class="flex gap-1 overflow-x-auto border-b px-4 py-2 sm:px-6 lg:px-8"
            :aria-label="t('Vehicle sections')"
        >
            <a
                href="#details"
                class="rounded-md px-3 py-1.5 text-sm font-medium hover:bg-muted"
                >{{ t('Details') }}</a
            ><a
                href="#photos"
                class="rounded-md px-3 py-1.5 text-sm font-medium hover:bg-muted"
                >{{ t('Photos') }}</a
            ><a
                href="#pricing"
                class="rounded-md px-3 py-1.5 text-sm font-medium hover:bg-muted"
                >{{ t('Pricing') }}</a
            >
        </nav>

        <div id="details">
            <VehicleForm
                :vehicle="vehicle"
                :types="types"
                :categories="categories"
            />
        </div>
        <VehiclePhotoManager
            :vehicle-id="vehicle.id"
            :photos="vehicle.photos"
        />
        <VehiclePricingEditor
            :vehicle-id="vehicle.id"
            :pricing="vehicle.pricing"
            :pricing-profile="vehicle.pricing_profile"
            :templates="pricing_templates"
        />
    </div>
</template>
