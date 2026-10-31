<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminSelect from '@/components/AdminSelect.vue';
import BlockEditor from '@/components/website-cms/BlockEditor.vue';
import CmsShell from '@/components/website-cms/CmsShell.vue';
import CmsLeaveGuard from '@/components/website-cms/CmsLeaveGuard.vue';
import CmsPreview from '@/components/website-cms/CmsPreview.vue';
import LocalizedFields from '@/components/website-cms/LocalizedFields.vue';
import PublicationBar from '@/components/website-cms/PublicationBar.vue';
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
import {
    cloneCms,
    cmsDirty,
    cmsTransport,
    moveCmsItem,
    newCmsBlock,
} from '@/lib/websiteCms';
import type {
    CmsActions,
    CmsBlockType,
    CmsCapabilities,
    CmsLocale,
    CmsPublication,
    PageContent,
    StaticAsset,
} from '@/types/websiteCms';
const props = defineProps<{
    page: CmsPublication & {
        key: string;
        draft: PageContent;
        published: PageContent | null;
    };
    history: { revision: number; published_at: string; restore_url: string }[];
    block_types: CmsBlockType[];
    static_assets: StaticAsset[];
    locales: CmsLocale[];
    capabilities: CmsCapabilities;
    actions: CmsActions;
}>();
const { ct } = useWebsiteCms();
const saved = ref(cloneCms(props.page.draft));
const form = useForm(`WebsitePage:${props.page.key}`, {
    expected_revision: props.page.revision,
    content: cloneCms(props.page.draft),
});
const mutation = useForm({ expected_revision: props.page.revision });
const dirty = computed(() => cmsDirty(form.content, saved.value));
const errors = computed(
    () => ({ ...form.errors, ...mutation.errors }) as Record<string, string>,
);
const processing = computed(() => form.processing || mutation.processing);
const preview = ref(false);
const selectedType = ref<CmsBlockType>('text_image');
const restore = ref<{ revision: number; restore_url: string } | null>(null);
function save() {
    form.transform((data) => ({
        ...data,
        content: cmsTransport(data.content),
    })).post(props.actions.draft, {
        preserveScroll: true,
        onSuccess: () => {
            form.content = cloneCms(props.page.draft);
            saved.value = cloneCms(props.page.draft);
            form.expected_revision = props.page.revision;
            mutation.clearErrors();
        },
    });
}
function publish(kind: 'publish' | 'unpublish') {
    mutation.expected_revision = form.expected_revision;
    mutation.post(props.actions[kind], {
        preserveScroll: true,
        onSuccess: () => {
            form.expected_revision = props.page.revision;
        },
    });
}
function doRestore() {
    if (!restore.value) {
        return;
    }

    mutation.expected_revision = form.expected_revision;
    mutation.post(restore.value.restore_url, {
        preserveScroll: true,
        onSuccess: () => {
            form.content = cloneCms(props.page.draft);
            saved.value = cloneCms(props.page.draft);
            form.expected_revision = props.page.revision;
            form.clearErrors();
            restore.value = null;
        },
    });
}
</script>
<template>
    <Head :title="`${ct('Page content')} · ${ct(page.key)}`" /><CmsShell
        :title="ct(page.key)"
        section="pages"
        :content-locales="locales"
        :content-errors="errors"
        ><Link href="/website/pages" class="text-sm underline">{{
            ct('Back')
        }}</Link>
        <p
            v-if="['terms', 'privacy'].includes(page.key)"
            class="rounded-lg border border-amber-400 p-3 text-sm"
        >
            {{
                ct(
                    'Policies require separate legal approval before publishing. Saving a draft does not enable live rentals.',
                )
            }}
        </p>
        <PublicationBar
            :publish-allowed="page.draft.blocks.length > 0"
            :publication="page"
            :capabilities="capabilities"
            :dirty="dirty"
            :processing="processing"
            :errors="errors"
            :revision-error="errors.revision"
            @save="save"
            @publish="publish('publish')"
            @unpublish="publish('unpublish')"
            @preview="preview = true" />
        <form class="space-y-6" @submit.prevent="save">
            <p class="rounded-lg border border-amber-400 p-4 text-sm">
                {{
                    ct(
                        'Publishing these blocks replaces the current page content. Catalog pages keep their live filters, vehicle results and pagination. Unpublishing restores the existing layout.',
                    )
                }}
            </p>
            <section class="cms-panel space-y-5">
                <h2>{{ ct('Page content') }}</h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        ct(
                            'Plain text only. Translations are entered separately; empty languages are not filled automatically.',
                        )
                    }}
                </p>
                <LocalizedFields
                    v-for="field in [
                        { key: 'title', label: 'Page title', max: 160 },
                        { key: 'meta_title', label: 'SEO title', max: 160 },
                        {
                            key: 'meta_description',
                            label: 'SEO description',
                            max: 500,
                        },
                    ] as const"
                    :key="field.key"
                    v-model="form.content[field.key]"
                    :field="`content.${field.key}`"
                    :label="ct(field.label)"
                    :locales="locales"
                    :max="field.max"
                    :multiline="field.key === 'meta_description'"
                    :errors="errors"
                    :disabled="!capabilities.edit || processing"
                />
            </section>
            <section class="space-y-4">
                <h2 class="text-lg font-semibold">
                    {{ ct('Blocks') }} ({{ form.content.blocks.length }}/30)
                </h2>
                <p
                    v-if="!form.content.blocks.length"
                    class="cms-panel text-sm text-muted-foreground"
                >
                    {{
                        ct(
                            'No blocks yet. Add an existing design block to start.',
                        )
                    }}
                    {{
                        ct(
                            'An empty draft can be saved, but it cannot be published. SEO fields alone do not preserve the existing page body.',
                        )
                    }}
                </p>
                <details
                    v-for="(block, index) in form.content.blocks"
                    :key="block.id"
                    class="cms-panel"
                    :open="index === form.content.blocks.length - 1"
                >
                    <summary class="cursor-pointer py-1 font-semibold">
                        {{ index + 1 }}. {{ ct(block.type)
                        }}<span
                            class="ml-2 font-normal text-muted-foreground"
                            >{{ block.props.heading.en }}</span
                        >
                    </summary>
                    <div class="mt-5 space-y-5">
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="
                                    !capabilities.edit ||
                                    processing ||
                                    index === 0
                                "
                                @click="
                                    form.content.blocks = moveCmsItem(
                                        form.content.blocks,
                                        index,
                                        -1,
                                    )
                                "
                                >{{ ct('Move up') }}</Button
                            ><Button
                                type="button"
                                variant="outline"
                                :disabled="
                                    !capabilities.edit ||
                                    processing ||
                                    index === form.content.blocks.length - 1
                                "
                                @click="
                                    form.content.blocks = moveCmsItem(
                                        form.content.blocks,
                                        index,
                                        1,
                                    )
                                "
                                >{{ ct('Move down') }}</Button
                            ><Button
                                type="button"
                                variant="ghost"
                                class="text-destructive"
                                :disabled="!capabilities.edit || processing"
                                @click="form.content.blocks.splice(index, 1)"
                                >{{ ct('Remove') }}</Button
                            >
                        </div>
                        <BlockEditor
                            v-model="form.content.blocks[index]"
                            :index="index"
                            :locales="locales"
                            :assets="static_assets"
                            :errors="errors"
                            :disabled="!capabilities.edit || processing"
                        />
                    </div>
                </details>
                <div class="cms-panel flex flex-wrap gap-3">
                    <AdminSelect
                        v-model="selectedType"
                        class="w-full sm:w-64"
                        :aria-label="ct('Add block')"
                        :disabled="!capabilities.edit || processing"
                        :options="
                            block_types.map((value) => ({
                                value,
                                label: ct(value),
                            }))
                        "
                    /><Button
                        type="button"
                        variant="outline"
                        :disabled="
                            !capabilities.edit ||
                            processing ||
                            form.content.blocks.length >= 30
                        "
                        @click="
                            form.content.blocks.push(newCmsBlock(selectedType))
                        "
                        >{{ ct('Add block') }}</Button
                    >
                </div>
            </section>
            <Button
                type="submit"
                :disabled="!capabilities.edit || processing"
                >{{ ct('Save draft') }}</Button
            >
        </form>
        <section class="cms-panel space-y-4">
            <h2>{{ ct('Publication history') }}</h2>
            <p v-if="!history.length" class="text-sm text-muted-foreground">
                {{ ct('No publication yet') }}
            </p>
            <div
                v-for="entry in history"
                :key="entry.revision"
                class="flex flex-wrap items-center justify-between gap-3 border-t pt-3"
            >
                <span class="text-sm"
                    >{{ ct('Revision') }} {{ entry.revision }} ·
                    {{ new Date(entry.published_at).toLocaleString() }}</span
                ><Button
                    type="button"
                    variant="outline"
                    :disabled="dirty || processing || !capabilities.edit"
                    @click="restore = entry"
                    >{{ ct('Restore to draft') }}</Button
                >
            </div>
        </section>
        <CmsPreview
            v-model:open="preview"
            :urls="actions.preview"
            :locales="locales" /><CmsLeaveGuard :dirty="dirty"
    /></CmsShell>
    <Dialog :open="restore !== null" @update:open="!$event && (restore = null)"
        ><DialogContent
            ><DialogHeader
                ><DialogTitle>{{
                    ct('Restore this revision to the draft?')
                }}</DialogTitle
                ><DialogDescription>{{
                    ct(
                        'Your current saved draft will be replaced. Nothing will be published automatically.',
                    )
                }}</DialogDescription></DialogHeader
            ><DialogFooter
                ><Button
                    type="button"
                    variant="outline"
                    @click="restore = null"
                    >{{ ct('Cancel') }}</Button
                ><Button type="button" @click="doRestore">{{
                    ct('Restore to draft')
                }}</Button></DialogFooter
            ></DialogContent
        ></Dialog
    >
</template>
