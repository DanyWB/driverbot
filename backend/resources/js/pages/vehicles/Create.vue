<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import VehicleForm from '@/components/vehicles/VehicleForm.vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import type { CategoryOption } from '@/types';

defineProps<{
    types: string[];
    categories: CategoryOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Fleet', href: '/vehicles' },
            { title: 'New vehicle', href: '/vehicles/create' },
        ],
    },
});

const { t } = useLocale();
</script>

<template>
    <Head :title="t('New vehicle')" />
    <div class="flex min-w-0 flex-1 flex-col">
        <header
            class="flex items-center gap-3 border-b px-4 py-5 sm:px-6 lg:px-8"
        >
            <Button
                as-child
                variant="ghost"
                size="icon"
                :title="t('Back to fleet')"
            >
                <Link href="/vehicles"
                    ><ArrowLeft /><span class="sr-only">{{
                        t('Back')
                    }}</span></Link
                >
            </Button>
            <div>
                <p class="text-sm text-muted-foreground">
                    {{ t('Fleet catalog') }}
                </p>
                <h1 class="text-2xl font-semibold">
                    {{ t('New vehicle') }}
                </h1>
            </div>
        </header>
        <VehicleForm :types="types" :categories="categories" />
    </div>
</template>
