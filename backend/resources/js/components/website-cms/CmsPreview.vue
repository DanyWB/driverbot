<script setup lang="ts">
import { computed, ref } from 'vue';
import AdminSelect from '@/components/AdminSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useWebsiteCms } from '@/composables/useWebsiteCms';
import type { CmsLocale } from '@/types/websiteCms';
const props = defineProps<{
    open: boolean;
    urls?: Partial<Record<CmsLocale, string>>;
    locales: CmsLocale[];
}>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const lang = ref<CmsLocale>('en');
const mode = ref<'desktop' | 'mobile'>('desktop');
const { ct } = useWebsiteCms();
const url = computed(() => props.urls?.[lang.value]);
</script>
<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)"
        ><DialogContent
            class="flex h-[94vh] w-[96vw] max-w-[96vw] flex-col sm:max-w-[96vw]"
            ><DialogHeader
                ><DialogTitle>{{ ct('Preview') }}</DialogTitle
                ><DialogDescription>{{
                    ct('Preview only. Booking submissions are disabled.')
                }}</DialogDescription></DialogHeader
            >
            <div class="flex flex-wrap items-center gap-2">
                <AdminSelect
                    v-model="lang"
                    class="w-40"
                    :aria-label="ct('Preview')"
                    :options="
                        locales.map((value) => ({
                            value,
                            label: {
                                en: 'English',
                                ru: 'Русский',
                                uk: 'Українська',
                            }[value],
                        }))
                    "
                /><Button
                    type="button"
                    :variant="mode === 'desktop' ? 'default' : 'outline'"
                    @click="mode = 'desktop'"
                    >{{ ct('Desktop') }}</Button
                ><Button
                    type="button"
                    :variant="mode === 'mobile' ? 'default' : 'outline'"
                    @click="mode = 'mobile'"
                    >{{ ct('Mobile') }}</Button
                >
            </div>
            <div
                class="min-h-0 flex-1 overflow-auto rounded border bg-muted p-2"
            >
                <iframe
                    v-if="url"
                    :key="url"
                    :src="url"
                    :title="`${ct('Preview')} ${lang}`"
                    sandbox="allow-same-origin allow-scripts"
                    class="mx-auto h-full min-h-[400px] border-0 bg-black"
                    :style="{ width: mode === 'mobile' ? '390px' : '1280px' }"
                /></div></DialogContent
    ></Dialog>
</template>
