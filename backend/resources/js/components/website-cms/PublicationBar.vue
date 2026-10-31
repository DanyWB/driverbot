<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useWebsiteCms } from '@/composables/useWebsiteCms';
import type { CmsPublication, CmsCapabilities } from '@/types/websiteCms';
withDefaults(
    defineProps<{
        publication: CmsPublication;
        capabilities: CmsCapabilities;
        dirty: boolean;
        processing: boolean;
        revisionError?: string;
        errors?: Record<string, string>;
        previewAvailable?: boolean;
        publishAllowed?: boolean;
    }>(),
    { previewAvailable: true, publishAllowed: true },
);
const emit = defineEmits<{
    save: [];
    publish: [];
    unpublish: [];
    preview: [];
}>();
const confirm = ref<'publish' | 'unpublish' | null>(null);
const { ct } = useWebsiteCms();
function accept() {
    if (confirm.value === 'publish') {
        emit('publish');
    } else {
        emit('unpublish');
    }

    confirm.value = null;
}
</script>
<template>
    <section class="cms-panel space-y-4 bg-muted/20" aria-live="polite">
        <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
            <span>{{ ct('Revision') }} {{ publication.revision }}</span
            ><span
                >{{ ct('Published revision') }}:
                {{
                    publication.published_revision ?? ct('Not published')
                }}</span
            ><span
                class="font-medium"
                :class="dirty ? 'text-amber-700 dark:text-amber-400' : ''"
                >{{
                    ct(
                        dirty
                            ? 'Unsaved changes'
                            : publication.has_unpublished_changes
                              ? 'Unpublished draft changes'
                              : 'Saved draft',
                    )
                }}</span
            >
        </div>
        <div
            v-if="revisionError"
            class="rounded border border-destructive p-3 text-sm"
            role="alert"
        >
            <p>{{ revisionError }}</p>
            <p class="mt-2">
                {{
                    ct(
                        'Another editor changed this content. Your input is kept. Reload the latest revision in a separate tab before deciding what to keep.',
                    )
                }}
            </p>
            <a
                :href="$page.url"
                target="_blank"
                rel="noopener"
                class="mt-2 inline-block underline"
                >{{ ct('Open latest in new tab') }}</a
            >
        </div>
        <ul
            v-if="
                errors && Object.keys(errors).some((key) => key !== 'revision')
            "
            class="list-disc space-y-1 pl-5 text-sm text-destructive"
            role="alert"
        >
            <li v-for="(error, key) in errors" :key="key">
                {{ key !== 'revision' ? error : '' }}
            </li>
        </ul>
        <div class="flex flex-wrap gap-2">
            <Button
                type="button"
                :disabled="processing || !capabilities.edit"
                @click="emit('save')"
                >{{ ct(processing ? 'Saving' : 'Save draft') }}</Button
            >
            <Button
                v-if="previewAvailable"
                type="button"
                variant="outline"
                :disabled="processing || dirty"
                @click="emit('preview')"
                >{{ ct('Preview') }}</Button
            >
            <Button
                type="button"
                variant="outline"
                :disabled="
                    processing ||
                    dirty ||
                    !capabilities.publish ||
                    !publishAllowed ||
                    Boolean(revisionError)
                "
                @click="confirm = 'publish'"
                >{{ ct('Publish') }}</Button
            >
            <Button
                v-if="publication.published_revision !== null"
                type="button"
                variant="outline"
                :disabled="
                    processing ||
                    !capabilities.publish ||
                    Boolean(revisionError)
                "
                @click="confirm = 'unpublish'"
                >{{ ct('Unpublish') }}</Button
            >
        </div>
        <p v-if="dirty" class="text-sm text-muted-foreground">
            {{ ct('Save your changes before previewing or publishing.') }}
        </p>
    </section>
    <Dialog :open="confirm !== null" @update:open="!$event && (confirm = null)"
        ><DialogContent
            ><DialogHeader
                ><DialogTitle>{{
                    ct(
                        confirm === 'publish'
                            ? 'Publish saved revision?'
                            : 'Unpublish this content?',
                    )
                }}</DialogTitle
                ><DialogDescription>{{
                    ct(
                        confirm === 'publish'
                            ? 'Visitors will see this saved revision. Review every language and the preview before publishing.'
                            : 'The website will return to its existing catalog content. The draft remains available.',
                    )
                }}</DialogDescription></DialogHeader
            ><DialogFooter
                ><Button
                    type="button"
                    variant="outline"
                    @click="confirm = null"
                    >{{ ct('Cancel') }}</Button
                ><Button type="button" @click="accept">{{
                    ct(confirm === 'publish' ? 'Publish' : 'Unpublish')
                }}</Button></DialogFooter
            ></DialogContent
        ></Dialog
    >
</template>
