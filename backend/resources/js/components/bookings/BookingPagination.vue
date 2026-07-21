<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { PaginatedBookings } from '@/types';

defineProps<{ paginator: PaginatedBookings }>();
</script>

<template>
    <div
        v-if="paginator.last_page > 1"
        class="flex flex-col gap-3 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
    >
        <p class="text-sm text-muted-foreground">
            {{ paginator.from }}–{{ paginator.to }} of {{ paginator.total }}
        </p>
        <nav class="flex items-center gap-1" aria-label="Booking pages">
            <Button
                v-for="(link, index) in paginator.links"
                :key="`${link.label}-${index}`"
                as-child
                size="icon-sm"
                :variant="link.active ? 'default' : 'ghost'"
                :disabled="!link.url"
            >
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    :aria-current="link.active ? 'page' : undefined"
                >
                    <ChevronLeft v-if="index === 0" />
                    <ChevronRight
                        v-else-if="index === paginator.links.length - 1"
                    />
                    <span v-else>{{ link.label }}</span>
                    <span class="sr-only" v-if="index === 0">Previous</span>
                    <span
                        class="sr-only"
                        v-else-if="index === paginator.links.length - 1"
                        >Next</span
                    >
                </Link>
                <span v-else>
                    <ChevronLeft v-if="index === 0" />
                    <ChevronRight v-else />
                </span>
            </Button>
        </nav>
    </div>
</template>
