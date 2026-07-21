<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

const name = usePage().props.name;

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh w-full flex-col items-center justify-center overflow-x-hidden bg-background p-4 sm:p-6 md:p-10"
    >
        <div class="w-full max-w-sm min-w-0">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <div
                            class="mb-1 flex size-10 items-center justify-center rounded-md bg-foreground text-background"
                        >
                            <AppLogoIcon class="size-6" />
                        </div>
                        <span class="text-sm font-semibold">{{ name }}</span>
                    </Link>
                    <div class="min-w-0 space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p
                            class="max-w-full text-center text-sm break-words text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
