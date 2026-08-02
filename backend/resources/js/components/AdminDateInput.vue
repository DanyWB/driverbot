<script setup lang="ts">
import { CalendarDays } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import { ref } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({
    inheritAttrs: false,
});

const props = withDefaults(
    defineProps<{
        modelValue: string;
        disabled?: boolean;
        min?: string;
        max?: string;
        required?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        disabled: false,
        required: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const input = ref<HTMLInputElement | null>(null);

function openPicker(): void {
    if (props.disabled || !input.value) {
        return;
    }

    input.value.focus({ preventScroll: true });

    try {
        input.value.showPicker();
    } catch {
        // Browsers without showPicker retain their native input behaviour.
    }
}

function updateValue(event: Event): void {
    emit('update:modelValue', (event.target as HTMLInputElement).value);
}
</script>

<template>
    <div :class="cn('relative min-w-0', props.class)">
        <input
            ref="input"
            v-bind="$attrs"
            type="date"
            :value="modelValue"
            :disabled="disabled"
            :min="min"
            :max="max"
            :required="required"
            class="admin-date-input h-9 w-full cursor-pointer rounded-md border border-input bg-background py-1 pr-10 pl-3 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
            @click="openPicker"
            @keydown.down.prevent="openPicker"
            @input="updateValue"
        />
        <CalendarDays
            class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground"
        />
    </div>
</template>
