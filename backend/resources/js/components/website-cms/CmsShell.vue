<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, provide, ref, watch } from 'vue';
import { useWebsiteCms } from '@/composables/useWebsiteCms';
import { cmsContentLanguage } from '@/lib/websiteCmsContext';
import type { CmsLocale } from '@/types/websiteCms';
const props = defineProps<{
    title: string;
    section: 'cards' | 'pages' | 'settings';
    contentLocales?: CmsLocale[];
    contentErrors?: Record<string, string>;
}>();
const { ct } = useWebsiteCms();
const contentLanguage = ref<CmsLocale>('en');
provide(cmsContentLanguage, contentLanguage);
const languageErrors = computed(() =>
    Object.keys(props.contentErrors ?? {}).reduce<
        Partial<Record<CmsLocale, number>>
    >((counts, key) => {
        const language = key
            .split('.')
            .find((part) => ['en', 'ru', 'uk'].includes(part)) as
            CmsLocale | undefined;

        if (language) {
            counts[language] = (counts[language] ?? 0) + 1;
        }

        return counts;
    }, {}),
);
watch(languageErrors, (counts) => {
    const first = props.contentLocales?.find((lang) => counts[lang]);

    if (first) {
        contentLanguage.value = first;
    }
});
</script>
<template>
    <main class="cms-shell min-w-0 flex-1">
        <header class="border-b px-4 py-5 sm:px-6 lg:px-8">
            <p class="text-sm text-muted-foreground">{{ ct('Website') }}</p>
            <h1 class="mt-1 text-2xl font-semibold">{{ title }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-muted-foreground">
                {{
                    ct(
                        'Site content is separate from Fleet and Telegram. Prices and availability stay in the shared catalog.',
                    )
                }}
            </p>
            <nav class="mt-5 flex flex-wrap gap-2" :aria-label="ct('Website')">
                <Link
                    v-for="item in ['cards', 'pages', 'settings'] as const"
                    :key="item"
                    :href="`/website/${item}`"
                    class="rounded-md border px-4 py-2 text-sm font-medium"
                    :class="
                        section === item
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'hover:bg-muted'
                    "
                    :aria-current="section === item ? 'page' : undefined"
                    >{{
                        ct(
                            item === 'cards'
                                ? 'Cards'
                                : item === 'pages'
                                  ? 'Pages'
                                  : 'Settings',
                        )
                    }}</Link
                >
            </nav>
            <div
                v-if="contentLocales"
                class="mt-5 flex flex-wrap items-center gap-3"
                role="group"
                :aria-label="ct('Content language')"
            >
                <span class="text-sm text-muted-foreground">{{
                    ct('Content language')
                }}</span>
                <div class="inline-flex gap-1 rounded-lg border p-1">
                    <button
                        v-for="lang in contentLocales"
                        :key="lang"
                        type="button"
                        class="min-w-14 rounded-md px-3 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        :class="
                            contentLanguage === lang
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-muted'
                        "
                        :aria-pressed="contentLanguage === lang"
                        :aria-label="`${{ en: 'English', ru: 'Русский', uk: 'Українська' }[lang]}${languageErrors[lang] ? ` (${ct('Errors')}: ${languageErrors[lang]})` : ''}`"
                        @click="contentLanguage = lang"
                    >
                        {{ lang.toUpperCase()
                        }}<span v-if="languageErrors[lang]" class="ml-1 text-xs"
                            >({{ languageErrors[lang] }})</span
                        >
                    </button>
                </div>
                <span class="text-xs text-muted-foreground">{{
                    ct('All languages are saved together.')
                }}</span>
            </div>
        </header>
        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8"><slot /></div>
    </main>
</template>
<style>
.cms-shell :is(button, a, input, textarea) {
    transition:
        color 350ms ease,
        background-color 350ms ease,
        border-color 350ms ease,
        box-shadow 350ms ease;
}
.cms-shell :is(button, input:not([type='checkbox']), [role='combobox']) {
    min-height: 44px;
}
.cms-shell textarea {
    min-height: 110px;
    width: 100%;
    border: 1px solid var(--input);
    border-radius: var(--radius);
    background: transparent;
    padding: 0.65rem 0.75rem;
    font-size: 0.875rem;
}
.cms-shell textarea:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
.cms-panel {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.25rem;
    min-width: 0;
}
.cms-panel h2 {
    font-size: 1.125rem;
    font-weight: 600;
}
@media (prefers-reduced-motion: reduce) {
    .cms-shell :is(button, a, input, textarea) {
        transition: none;
    }
}
@supports (interpolate-size: allow-keywords) {
    .cms-shell details {
        interpolate-size: allow-keywords;
    }
    .cms-shell details::details-content {
        block-size: 0;
        opacity: 0;
        overflow: clip;
        transition:
            block-size 350ms ease,
            opacity 350ms ease,
            content-visibility 350ms allow-discrete;
    }
    .cms-shell details[open]::details-content {
        block-size: auto;
        opacity: 1;
    }
}
@media (prefers-reduced-motion: reduce) {
    .cms-shell details::details-content {
        transition: none;
    }
}
</style>
