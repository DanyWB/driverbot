<script setup lang="ts">
import { computed } from 'vue';
import { vehicleNameParts } from '@/lib/timelinePresentation';
const props = defineProps<{ name: string }>();
const parts = computed(() => vehicleNameParts(props.name));
</script>

<template>
    <span
        >{{ parts.before
        }}<span
            v-if="parts.ink"
            class="timeline-paint-word"
            :class="{
                'timeline-paint-black': parts.color.toLowerCase() === 'black',
            }"
            :style="{ '--paint-ink': parts.ink }"
            >{{ parts.color }}</span
        >{{ parts.after }}</span
    >
</template>
