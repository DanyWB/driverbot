<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Info, X } from '@lucide/vue';
import {
    PopoverClose,
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import TimelineVehicleName from '@/components/timeline/TimelineVehicleName.vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import type { TimelineVehicle } from '@/types';

withDefaults(defineProps<{ vehicle: TimelineVehicle; zoom?: number }>(), {
    zoom: 1,
});
const emit = defineEmits<{ 'update:open': [open: boolean] }>();
const { t } = useLocale();
</script>

<template>
    <PopoverRoot @update:open="emit('update:open', $event)">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="shrink-0"
                :style="{
                    width: `${Math.max(32, 32 * zoom)}px`,
                    height: `${Math.max(32, 32 * zoom)}px`,
                }"
                :aria-label="
                    t('Details for :vehicle', { vehicle: vehicle.name })
                "
                :title="t('Vehicle details')"
            >
                <Info
                    :style="{
                        width: `${Math.max(14, 14 * zoom)}px`,
                        height: `${Math.max(14, 14 * zoom)}px`,
                    }"
                />
            </Button>
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                side="right"
                align="start"
                :side-offset="8"
                :collision-padding="12"
                class="timeline-vehicle-info z-50 max-h-[min(var(--reka-popover-content-available-height),calc(100dvh-24px))] w-72 max-w-[calc(100vw-24px)] overflow-y-auto rounded-lg border bg-popover p-4 text-popover-foreground shadow-lg focus:outline-none"
                :aria-label="t('Vehicle details')"
            >
                <div class="mb-3 flex items-start gap-2">
                    <h3
                        class="min-w-0 flex-1 text-sm font-semibold break-words"
                    >
                        <TimelineVehicleName :name="vehicle.name" />
                    </h3>
                    <PopoverClose as-child>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="-mt-2 -mr-2 size-9 shrink-0"
                            :aria-label="t('Close')"
                            ><X class="size-4"
                        /></Button>
                    </PopoverClose>
                </div>
                <dl
                    class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-2 text-xs"
                >
                    <dt class="text-muted-foreground">{{ t('Type') }}</dt>
                    <dd class="break-words">{{ t(vehicle.type) }}</dd>
                    <dt class="text-muted-foreground">{{ t('Category') }}</dt>
                    <dd class="break-words">
                        {{ vehicle.category?.name ?? t('Not set') }}
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('Inventory code') }}
                    </dt>
                    <dd class="break-words">
                        {{ vehicle.inventory_code ?? t('Not set') }}
                    </dd>
                    <dt class="text-muted-foreground">{{ t('State') }}</dt>
                    <dd>
                        {{ t(vehicle.is_active ? 'Active' : 'Inactive') }} ·
                        {{ t(vehicle.is_visible ? 'Published' : 'Hidden') }}
                    </dd>
                </dl>
                <Link
                    :href="`/vehicles/${vehicle.id}/edit`"
                    class="mt-4 inline-flex min-h-9 items-center text-sm font-medium underline underline-offset-4"
                >
                    {{ t('Edit vehicle') }}
                </Link>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
