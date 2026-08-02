<script setup lang="ts">
import { Check, Languages, Monitor, Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';
import { useLocale } from '@/composables/useLocale';
import type { AppLocale } from '@/composables/useLocale';
import type { Appearance } from '@/types';

const { appearance, resolvedAppearance, updateAppearance } = useAppearance();
const { locale, t, updateLocale } = useLocale();

const themeOptions = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
] as const;

const languageOptions = [
    { value: 'ru', shortLabel: 'RU', label: 'Russian' },
    { value: 'en', shortLabel: 'EN', label: 'English' },
] as const;

const ThemeIcon = computed(() =>
    resolvedAppearance.value === 'dark' ? Moon : Sun,
);
</script>

<template>
    <div class="ml-auto flex shrink-0 items-center gap-1">
        <DropdownMenu :modal="false">
            <DropdownMenuTrigger as-child>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-9 gap-1.5 px-2"
                    :aria-label="t('Language')"
                    :title="t('Language')"
                >
                    <Languages class="size-4" />
                    <span class="text-xs font-semibold">{{
                        locale.toUpperCase()
                    }}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-44">
                <DropdownMenuLabel>{{ t('Language') }}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    v-for="option in languageOptions"
                    :key="option.value"
                    class="cursor-pointer"
                    @select="updateLocale(option.value as AppLocale)"
                >
                    <span class="w-6 text-xs font-semibold">{{
                        option.shortLabel
                    }}</span>
                    <span>{{ t(option.label) }}</span>
                    <Check
                        v-if="locale === option.value"
                        class="ml-auto size-4"
                    />
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <DropdownMenu :modal="false">
            <DropdownMenuTrigger as-child>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-9"
                    :aria-label="t('Theme')"
                    :title="t('Theme')"
                >
                    <component :is="ThemeIcon" class="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-44">
                <DropdownMenuLabel>{{ t('Theme') }}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    v-for="option in themeOptions"
                    :key="option.value"
                    class="cursor-pointer"
                    @select="updateAppearance(option.value as Appearance)"
                >
                    <component :is="option.icon" class="size-4" />
                    <span>{{ t(option.label) }}</span>
                    <Check
                        v-if="appearance === option.value"
                        class="ml-auto size-4"
                    />
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
