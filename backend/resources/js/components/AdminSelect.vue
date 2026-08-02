<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

export type AdminSelectValue = string | number | null;
export type AdminSelectOption = {
    value: AdminSelectValue;
    label: string;
    disabled?: boolean;
};

defineOptions({
    inheritAttrs: false,
});

const props = withDefaults(
    defineProps<{
        modelValue: AdminSelectValue;
        options: AdminSelectOption[];
        placeholder?: string;
        disabled?: boolean;
        class?: HTMLAttributes['class'];
        contentClass?: HTMLAttributes['class'];
    }>(),
    {
        placeholder: '',
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: AdminSelectValue];
    change: [value: AdminSelectValue];
}>();

function encode(value: AdminSelectValue): string {
    if (value === null) {
        return '__admin_select_null__';
    }

    return typeof value === 'number' ? 'number:' + value : 'string:' + value;
}

function updateValue(encoded: unknown): void {
    const option = props.options.find(
        (candidate) => encode(candidate.value) === encoded,
    );

    if (!option) {
        return;
    }

    emit('update:modelValue', option.value);
    emit('change', option.value);
}
</script>

<template>
    <Select
        :model-value="encode(modelValue)"
        :disabled="disabled"
        @update:model-value="updateValue"
    >
        <SelectTrigger
            v-bind="$attrs"
            :class="cn('w-full min-w-0 bg-background', props.class)"
        >
            <SelectValue :placeholder="placeholder" />
        </SelectTrigger>
        <SelectContent
            position="popper"
            :body-lock="false"
            :disable-outside-pointer-events="false"
            :class="cn('max-h-80', props.contentClass)"
        >
            <SelectItem
                v-for="option in options"
                :key="encode(option.value)"
                :value="encode(option.value)"
                :disabled="option.disabled"
            >
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
