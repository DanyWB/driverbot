<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { nextTick, ref } from 'vue';
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

type TriggerComponent = {
    $el: Element;
};

const open = ref(false);
const trigger = ref<HTMLElement | TriggerComponent | null>(null);
let pendingTabDirection: 1 | -1 | null = null;
let pendingTabTarget: HTMLElement | null = null;
let pendingTabPopup: HTMLElement | null = null;

const tabbableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function getTriggerElement(): HTMLElement | null {
    const element =
        trigger.value && '$el' in trigger.value
            ? trigger.value.$el
            : trigger.value;

    return element instanceof HTMLElement ? element : null;
}

function isAvailableTabStop(
    element: HTMLElement,
    allowedAriaHiddenRoot: HTMLElement | null,
): boolean {
    const style = window.getComputedStyle(element);
    const ariaHiddenAncestor = element.closest<HTMLElement>(
        '[aria-hidden="true"]',
    );

    return (
        element.tabIndex >= 0 &&
        element.getClientRects().length > 0 &&
        !element.matches(':disabled') &&
        !element.hasAttribute('data-reka-focus-guard') &&
        !element.closest('[inert], [hidden]') &&
        (!ariaHiddenAncestor || ariaHiddenAncestor === allowedAriaHiddenRoot) &&
        style.visibility !== 'hidden' &&
        style.visibility !== 'collapse'
    );
}

function adjacentControl(direction: 1 | -1): HTMLElement | null {
    const triggerElement = getTriggerElement();

    if (!triggerElement) {
        return null;
    }

    const focusScope =
        triggerElement.closest<HTMLElement>('[role="dialog"], dialog') ??
        document;
    const allowedAriaHiddenRoot = triggerElement.closest<HTMLElement>(
        '[aria-hidden="true"]',
    );
    const tabStops = Array.from(
        focusScope.querySelectorAll<HTMLElement>(tabbableSelector),
    ).filter((element) => isAvailableTabStop(element, allowedAriaHiddenRoot));
    const triggerIndex = tabStops.indexOf(triggerElement);

    return triggerIndex >= 0
        ? (tabStops[triggerIndex + direction] ?? null)
        : null;
}

function focusTabTargetIfSafe(
    destination: HTMLElement | null,
    closingPopup: HTMLElement | null,
): void {
    const activeElement = document.activeElement;
    const triggerElement = getTriggerElement();
    const focusIsStillInClosingPopup =
        activeElement instanceof HTMLElement &&
        closingPopup?.contains(activeElement) === true;

    if (
        destination &&
        (activeElement === document.body ||
            activeElement === triggerElement ||
            focusIsStillInClosingPopup)
    ) {
        destination.focus();
    }
}

async function handleContentTab(event: KeyboardEvent): Promise<void> {
    const direction = event.shiftKey ? -1 : 1;
    const destination = adjacentControl(direction);
    const activeElement = document.activeElement;
    const closingPopup =
        activeElement instanceof HTMLElement
            ? activeElement.closest<HTMLElement>('[role="listbox"]')
            : null;
    pendingTabDirection = direction;
    pendingTabTarget = destination;
    pendingTabPopup = closingPopup;
    open.value = false;
    await nextTick();
    focusTabTargetIfSafe(destination, closingPopup);
}

function handleCloseAutoFocus(event: Event): void {
    if (pendingTabDirection === null) {
        return;
    }

    event.preventDefault();
    const direction = pendingTabDirection;
    pendingTabDirection = null;
    const destination = pendingTabTarget ?? adjacentControl(direction);
    const closingPopup = pendingTabPopup;
    focusTabTargetIfSafe(destination, closingPopup);
    pendingTabTarget = null;
    pendingTabPopup = null;
}

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
        v-model:open="open"
        :model-value="encode(modelValue)"
        :disabled="disabled"
        @update:model-value="updateValue"
    >
        <SelectTrigger
            ref="trigger"
            v-bind="$attrs"
            :class="
                cn(
                    'w-full min-w-0 bg-background transition-[color,background-color,border-color,box-shadow] duration-[350ms] ease-out motion-reduce:transition-none',
                    props.class,
                )
            "
        >
            <SelectValue :placeholder="placeholder" />
        </SelectTrigger>
        <SelectContent
            position="popper"
            :body-lock="false"
            :disable-outside-pointer-events="false"
            @keydown.tab.capture.stop.prevent="handleContentTab"
            @close-auto-focus="handleCloseAutoFocus"
            :class="
                cn(
                    'max-h-80 duration-[350ms] ease-out data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95 motion-reduce:animate-none motion-reduce:transition-none',
                    props.contentClass,
                )
            "
        >
            <SelectItem
                v-for="option in options"
                :key="encode(option.value)"
                :value="encode(option.value)"
                :disabled="option.disabled"
                class="transition-colors duration-[350ms] ease-out motion-reduce:transition-none"
            >
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
