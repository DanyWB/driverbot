<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminSelect from '@/components/AdminSelect.vue';
import InputError from '@/components/InputError.vue';
import CmsShell from '@/components/website-cms/CmsShell.vue';
import CmsLeaveGuard from '@/components/website-cms/CmsLeaveGuard.vue';
import CmsPreview from '@/components/website-cms/CmsPreview.vue';
import LocalizedFields from '@/components/website-cms/LocalizedFields.vue';
import PublicationBar from '@/components/website-cms/PublicationBar.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    emptyLocaleText,
} from '@/lib/websiteCms';
import type {
    CardContent,
    CmsActions,
    CmsCapabilities,
    CmsLocale,
    CmsPublication,
    WebsiteMedia,
} from '@/types/websiteCms';
const props = defineProps<{
    vehicle: {
        id: number;
        name: string;
        inventory_code: string | null;
        type: string;
        year: number | null;
        fleet_eligible: boolean;
        fleet_url: string;
    };
    profile: CmsPublication & {
        draft: CardContent;
        published: CardContent | null;
    };
    media: WebsiteMedia[];
    locales: CmsLocale[];
    limits: { max_bytes: number; max_pixels: number; max_dimension: number };
    capabilities: CmsCapabilities;
    actions: CmsActions;
}>();
const { ct } = useWebsiteCms();
const initial = () => {
    const content = cloneCms(props.profile.draft);
    delete content.cover_asset_id;

    return {
        content,
        cover_asset_id: props.profile.draft.cover_asset_id ?? null,
    };
};
const saved = ref(initial());
const form = useForm(`WebsiteCard:${props.vehicle.id}`, {
    expected_revision: props.profile.revision,
    ...initial(),
});
const mutation = useForm({ expected_revision: props.profile.revision });
const deletionForm = useForm({ vehicle_id: props.vehicle.id });
const upload = useForm<{
    media: File | null;
    kind: 'photo' | 'artwork';
    alt_text: ReturnType<typeof emptyLocaleText>;
}>({ media: null, kind: 'photo', alt_text: emptyLocaleText() });
const dirty = computed(() =>
    cmsDirty(
        { content: form.content, cover_asset_id: form.cover_asset_id },
        saved.value,
    ),
);
const errors = computed(
    () => ({ ...form.errors, ...mutation.errors }) as Record<string, string>,
);
const processing = computed(
    () => form.processing || mutation.processing || upload.processing,
);
const preview = ref(false);
const deletion = ref<WebsiteMedia | null>(null);
const uploadComplete = ref(false);
const uploadInput = ref<HTMLInputElement | null>(null);
function save() {
    form.transform((data) => ({
        ...data,
        content: cmsTransport(data.content),
    })).post(props.actions.draft, {
        preserveScroll: true,
        onSuccess: () => {
            const fresh = initial();
            form.content = fresh.content;
            form.cover_asset_id = fresh.cover_asset_id;
            form.expected_revision = props.profile.revision;
            saved.value = cloneCms(fresh);
            mutation.clearErrors();
        },
    });
}
function publish(kind: 'publish' | 'unpublish') {
    mutation.expected_revision = form.expected_revision;
    mutation.post(props.actions[kind], {
        preserveScroll: true,
        onSuccess: () => {
            form.expected_revision = props.profile.revision;
        },
    });
}
function sendUpload() {
    uploadComplete.value = false;
    upload
        .transform((data) => ({
            ...data,
            alt_text: cmsTransport(data.alt_text),
        }))
        .post(props.actions.upload!, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                upload.reset();

                if (uploadInput.value) {
                    uploadInput.value.value = '';
                }

                uploadComplete.value = true;
            },
        });
}
function remove() {
    if (!deletion.value) {
        return;
    }

    deletionForm.delete(`/website/media/${deletion.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deletion.value = null;
        },
    });
}
</script>
<template>
    <Head :title="`${ct('Edit card')} · ${vehicle.name}`" /><CmsShell
        :title="vehicle.name"
        section="cards"
        :content-locales="locales"
        :content-errors="{ ...errors, ...upload.errors }"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <Link href="/website/cards" class="text-sm underline">{{
                ct('Back')
            }}</Link
            ><Button as-child variant="outline"
                ><Link :href="vehicle.fleet_url">{{
                    ct('Open Fleet')
                }}</Link></Button
            >
        </div>
        <p
            v-if="!vehicle.fleet_eligible"
            class="rounded-lg border border-amber-400 p-3 text-sm"
        >
            {{ ct('Not eligible for the public catalog') }}.
            {{
                ct(
                    'Publishing this profile does not activate a hidden or archived vehicle.',
                )
            }}
        </p>
        <PublicationBar
            :publication="profile"
            :capabilities="capabilities"
            :dirty="dirty"
            :processing="processing"
            :errors="errors"
            :revision-error="errors.revision"
            @save="save"
            @publish="publish('publish')"
            @unpublish="publish('unpublish')"
            @preview="preview = true"
        />
        <div
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]"
        >
            <form class="cms-panel space-y-6" @submit.prevent="save">
                <h2>{{ ct('Website content') }}</h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        ct(
                            'Plain text only. Translations are entered separately; empty languages are not filled automatically.',
                        )
                    }}
                </p>
                <LocalizedFields
                    v-for="field in [
                        { key: 'title', label: 'Title', max: 160 },
                        { key: 'summary', label: 'Summary', max: 4000 },
                        { key: 'meta_title', label: 'SEO title', max: 160 },
                        {
                            key: 'meta_description',
                            label: 'SEO description',
                            max: 500,
                        },
                        {
                            key: 'image_alt',
                            label: 'Image description',
                            max: 255,
                        },
                    ] as const"
                    :key="field.key"
                    v-model="form.content[field.key]"
                    :field="`content.${field.key}`"
                    :label="ct(field.label)"
                    :locales="locales"
                    :multiline="
                        field.key === 'summary' ||
                        field.key === 'meta_description'
                    "
                    :max="field.max"
                    :errors="errors"
                    :disabled="!capabilities.edit || processing"
                /><Button
                    type="submit"
                    :disabled="!capabilities.edit || processing"
                    >{{ ct('Save draft') }}</Button
                >
            </form>
            <div class="space-y-6">
                <section class="cms-panel space-y-4">
                    <h2>{{ ct('Website cover') }}</h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            ct(
                                'Fleet photo will be used until a separate website cover is saved and published.',
                            )
                        }}
                    </p>
                    <Button
                        type="button"
                        :variant="
                            form.cover_asset_id === null ? 'default' : 'outline'
                        "
                        :disabled="!capabilities.edit"
                        @click="form.cover_asset_id = null"
                        >{{ ct('Use Fleet photo') }}</Button
                    ><InputError :message="errors.cover_asset_id" />
                    <p
                        v-if="!media.length"
                        class="text-sm text-muted-foreground"
                    >
                        {{ ct('No website images uploaded') }}
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <article
                            v-for="asset in media"
                            :key="asset.id"
                            class="overflow-hidden rounded border"
                            :class="
                                form.cover_asset_id === asset.id
                                    ? 'ring-2 ring-primary'
                                    : ''
                            "
                        >
                            <img
                                :src="asset.variants.card.url"
                                :alt="asset.alt_text.en || vehicle.name"
                                class="aspect-[3/2] w-full bg-muted object-contain"
                                loading="lazy"
                            />
                            <div class="space-y-2 p-3">
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        ct(
                                            asset.kind === 'photo'
                                                ? 'Photo'
                                                : 'Artwork',
                                        )
                                    }}
                                    · {{ asset.width }} × {{ asset.height }}
                                </p>
                                <p
                                    v-if="asset.referenced_by_published"
                                    class="text-xs"
                                >
                                    {{ ct('Published cover') }}
                                </p>
                                <p
                                    v-if="asset.referenced_by_draft"
                                    class="text-xs"
                                >
                                    {{ ct('In draft') }}
                                </p>
                                <Button
                                    type="button"
                                    size="sm"
                                    class="w-full"
                                    :variant="
                                        form.cover_asset_id === asset.id
                                            ? 'default'
                                            : 'outline'
                                    "
                                    :disabled="!capabilities.edit || processing"
                                    @click="form.cover_asset_id = asset.id"
                                    >{{
                                        ct(
                                            form.cover_asset_id === asset.id
                                                ? 'Selected'
                                                : 'Choose cover',
                                        )
                                    }}</Button
                                ><Button
                                    v-if="
                                        !asset.referenced_by_draft &&
                                        !asset.referenced_by_published &&
                                        form.cover_asset_id !== asset.id
                                    "
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="w-full text-destructive"
                                    :disabled="processing || !capabilities.edit"
                                    @click="deletion = asset"
                                    >{{ ct('Delete') }}</Button
                                >
                            </div>
                        </article>
                    </div>
                </section>
                <form class="cms-panel space-y-4" @submit.prevent="sendUpload">
                    <h2>{{ ct('Upload website image') }}</h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            ct(
                                'Uploading does not change the cover or publish it. Choose the uploaded image, then save the draft.',
                            )
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        JPEG / PNG / WebP ·
                        {{ Math.floor(limits.max_bytes / 1024 / 1024) }} MB ·
                        {{ limits.max_dimension }} px
                    </p>
                    <div>
                        <Label for="website-image">{{ ct('Image') }}</Label
                        ><Input
                            id="website-image"
                            ref="uploadInput"
                            class="mt-2"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            :disabled="!capabilities.edit || processing"
                            @change="
                                upload.media =
                                    ($event.target as HTMLInputElement)
                                        .files?.[0] ?? null
                            "
                        /><InputError :message="upload.errors.media" />
                    </div>
                    <AdminSelect
                        v-model="upload.kind"
                        :aria-label="ct('Image')"
                        :disabled="!capabilities.edit || processing"
                        :options="[
                            { value: 'photo', label: ct('Photo') },
                            { value: 'artwork', label: ct('Artwork') },
                        ]"
                    /><LocalizedFields
                        v-model="upload.alt_text"
                        field="alt_text"
                        :label="ct('Image description')"
                        :locales="locales"
                        :max="255"
                        :errors="upload.errors as Record<string, string>"
                        :disabled="!capabilities.edit || processing"
                    /><Button
                        type="submit"
                        :disabled="
                            !upload.media || processing || !capabilities.edit
                        "
                        >{{ ct('Upload') }}</Button
                    >
                    <p v-if="uploadComplete" role="status" class="text-sm">
                        {{ ct('Upload complete. Choose the image below.') }}
                    </p>
                </form>
            </div>
        </div>
        <CmsPreview
            v-model:open="preview"
            :urls="actions.preview"
            :locales="locales"
        /><CmsLeaveGuard :dirty="dirty" />
    </CmsShell>
    <Dialog
        :open="deletion !== null"
        @update:open="!$event && (deletion = null)"
        ><DialogContent
            ><DialogHeader
                ><DialogTitle>{{ ct('Delete unused image?') }}</DialogTitle
                ><DialogDescription>{{
                    ct(
                        'This image is not referenced by a draft or publication. Deletion cannot be undone.',
                    )
                }}</DialogDescription></DialogHeader
            >
            <p
                v-if="Object.keys(deletionForm.errors).length"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ Object.values(deletionForm.errors).join(' ') }}
            </p>
            <DialogFooter
                ><Button
                    type="button"
                    variant="outline"
                    @click="deletion = null"
                    >{{ ct('Cancel') }}</Button
                ><Button
                    type="button"
                    variant="destructive"
                    :disabled="deletionForm.processing"
                    @click="remove"
                    >{{ ct('Delete') }}</Button
                ></DialogFooter
            ></DialogContent
        ></Dialog
    >
</template>
