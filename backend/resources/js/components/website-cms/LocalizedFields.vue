<script setup lang="ts">
import { inject, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWebsiteCms } from '@/composables/useWebsiteCms';
import { cmsContentLanguage } from '@/lib/websiteCmsContext';
import type { CmsLocale, LocaleText } from '@/types/websiteCms';
defineProps<{
    modelValue: LocaleText;
    field: string;
    label: string;
    locales: CmsLocale[];
    multiline?: boolean;
    max?: number;
    errors?: Record<string, string>;
    disabled?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: LocaleText] }>();
const { ct } = useWebsiteCms();
const selectedLanguage = inject(cmsContentLanguage, ref<CmsLocale>('en'));
</script>
<template>
    <fieldset class="min-w-0 space-y-3">
        <legend class="mb-1 text-sm font-medium">{{ label }}</legend>
        <div
            v-for="lang in locales"
            v-show="lang === selectedLanguage"
            :key="lang"
            class="min-w-0"
        >
            <Label
                :for="`${field}-${lang}`"
                class="text-xs text-muted-foreground"
                >{{
                    { en: 'English', ru: 'Русский', uk: 'Українська' }[lang]
                }}</Label
            >
            <textarea
                v-if="multiline"
                :id="`${field}-${lang}`"
                :value="modelValue?.[lang] ?? ''"
                :maxlength="max"
                :disabled="disabled"
                class="mt-1"
                :lang="lang"
                :aria-invalid="Boolean(errors?.[`${field}.${lang}`])"
                :aria-describedby="`${field}-${lang}-error`"
                @input="
                    emit('update:modelValue', {
                        ...modelValue,
                        [lang]: ($event.target as HTMLTextAreaElement).value,
                    })
                "
            />
            <Input
                v-else
                :id="`${field}-${lang}`"
                :model-value="modelValue?.[lang] ?? ''"
                :maxlength="max"
                :disabled="disabled"
                class="mt-1"
                :lang="lang"
                :aria-invalid="Boolean(errors?.[`${field}.${lang}`])"
                :aria-describedby="`${field}-${lang}-error`"
                @update:model-value="
                    emit('update:modelValue', {
                        ...modelValue,
                        [lang]: String($event),
                    })
                "
            />
            <div class="mt-1 flex justify-between gap-3">
                <InputError
                    :id="`${field}-${lang}-error`"
                    :message="errors?.[`${field}.${lang}`]"
                /><span v-if="max" class="ml-auto text-xs text-muted-foreground"
                    >{{ ct('Characters') }}:
                    {{ modelValue?.[lang]?.length ?? 0 }}/{{ max }}</span
                >
            </div>
        </div>
    </fieldset>
</template>
