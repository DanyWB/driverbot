<script setup lang="ts">
import { computed } from 'vue';
import {
    vehicleNameLines,
    vehicleNameSegments,
} from '@/lib/timelinePresentation';
const props = defineProps<{ name: string; stacked?: boolean }>();
const segments = computed(() => vehicleNameSegments(props.name));
const lines = computed(() => vehicleNameLines(props.name));
</script>

<template>
    <span v-if="stacked" class="block leading-tight">
        <span class="block whitespace-nowrap">{{ lines.model }}</span>
        <TimelineVehicleName
            v-if="lines.details"
            :name="lines.details"
            class="block whitespace-nowrap"
        />
    </span>
    <span v-else
        ><template v-for="(part, index) in segments" :key="index"
            ><span
                v-if="part.ink"
                class="timeline-paint-word"
                :class="{
                    'timeline-paint-black': part.text.toLowerCase() === 'black',
                }"
                :style="{ '--paint-ink': part.ink }"
                ><span class="timeline-paint-outline" aria-hidden="true">{{
                    part.text
                }}</span
                ><span class="timeline-paint-fill">{{ part.text }}</span></span
            ><template v-else>{{ part.text }}</template></template
        ></span
    >
</template>
