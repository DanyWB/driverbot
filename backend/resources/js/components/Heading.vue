<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';

type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});

const { t } = useLocale();
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <h2
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'text-xl font-semibold tracking-tight'
            "
        >
            {{ t(title) }}
        </h2>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ t(description) }}
        </p>
    </header>
</template>
