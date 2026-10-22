<script setup lang="ts">
import { computed } from 'vue';
import { vehicleNameLines, vehicleNameParts } from '@/lib/timelinePresentation';
const props = defineProps<{ name: string; stacked?: boolean }>();
const parts = computed(() => vehicleNameParts(props.name));
const lines = computed(() => vehicleNameLines(props.name));
</script>

<template>
    <span
        v-if="stacked"
        class="block leading-5 [overflow-wrap:anywhere] break-words"
    >
        <span class="block">{{ lines.model }}</span>
        <TimelineVehicleName
            v-if="lines.details"
            :name="lines.details"
            class="block"
        />
    </span>
    <span v-else
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
