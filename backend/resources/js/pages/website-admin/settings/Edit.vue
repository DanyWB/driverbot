<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import CmsShell from '@/components/website-cms/CmsShell.vue';
import CmsLeaveGuard from '@/components/website-cms/CmsLeaveGuard.vue';
import LocalizedFields from '@/components/website-cms/LocalizedFields.vue';
import PublicationBar from '@/components/website-cms/PublicationBar.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWebsiteCms } from '@/composables/useWebsiteCms';
import {
    cloneCms,
    cmsDirty,
    cmsTransport,
    moveCmsItem,
} from '@/lib/websiteCms';
import type {
    CmsActions,
    CmsCapabilities,
    CmsLocale,
    CmsPublication,
    SettingsContent,
} from '@/types/websiteCms';
const props = defineProps<{
    settings: CmsPublication & {
        draft: SettingsContent;
        published: SettingsContent | null;
    };
    locales: CmsLocale[];
    navigation_options: string[];
    capabilities: CmsCapabilities;
    actions: CmsActions;
}>();
const { ct } = useWebsiteCms();
const saved = ref(cloneCms(props.settings.draft));
const form = useForm('WebsiteSettings', {
    expected_revision: props.settings.revision,
    content: cloneCms(props.settings.draft),
});
const mutation = useForm({ expected_revision: props.settings.revision });
const dirty = computed(() => cmsDirty(form.content, saved.value));
const errors = computed(
    () => ({ ...form.errors, ...mutation.errors }) as Record<string, string>,
);
const processing = computed(() => form.processing || mutation.processing);
function save() {
    form.transform((data) => ({
        ...data,
        content: {
            ...cmsTransport(data.content),
            business: Object.fromEntries(
                Object.entries(data.content.business).map(([key, value]) => [
                    key,
                    value?.trim() || null,
                ]),
            ),
            contacts: Object.fromEntries(
                Object.entries(data.content.contacts).map(([key, value]) => [
                    key,
                    value?.trim() || null,
                ]),
            ),
        },
    })).post(props.actions.draft, {
        preserveScroll: true,
        onSuccess: () => {
            form.content = cloneCms(props.settings.draft);
            saved.value = cloneCms(props.settings.draft);
            form.expected_revision = props.settings.revision;
            mutation.clearErrors();
        },
    });
}
function publish(kind: 'publish' | 'unpublish') {
    mutation.expected_revision = form.expected_revision;
    mutation.post(props.actions[kind], {
        preserveScroll: true,
        onSuccess: () => {
            form.expected_revision = props.settings.revision;
        },
    });
}
function toggle(key: string, checked: boolean) {
    form.content.navigation = checked
        ? [...form.content.navigation, key]
        : form.content.navigation.filter((value) => value !== key);
}
</script>
<template>
    <Head :title="ct('Website settings')" /><CmsShell
        :title="ct('Website settings')"
        section="settings"
        :content-locales="locales"
        :content-errors="errors"
        ><p class="rounded-lg border p-4 text-sm text-muted-foreground">
            {{
                ct(
                    'Content settings do not control deployment, indexing, booking acceptance or provider credentials.',
                )
            }}
        </p>
        <PublicationBar
            :preview-available="false"
            :publication="settings"
            :capabilities="capabilities"
            :dirty="dirty"
            :processing="processing"
            :errors="errors"
            :revision-error="errors.revision"
            @save="save"
            @publish="publish('publish')"
            @unpublish="publish('unpublish')" />
        <form class="space-y-6" @submit.prevent="save">
            <div class="grid items-start gap-6 lg:grid-cols-2">
                <section class="cms-panel space-y-5">
                    <h2>{{ ct('Business') }}</h2>
                    <div
                        v-for="field in [
                            { key: 'name', label: 'Business name' },
                            { key: 'legal_name', label: 'Legal name' },
                            { key: 'address', label: 'Address' },
                            { key: 'telephone', label: 'Telephone' },
                        ] as const"
                        :key="field.key"
                    >
                        <Label :for="`business-${field.key}`">{{
                            ct(field.label)
                        }}</Label
                        ><Input
                            :id="`business-${field.key}`"
                            :model-value="
                                form.content.business[field.key] ?? ''
                            "
                            @update:model-value="
                                form.content.business[field.key] =
                                    String($event)
                            "
                            class="mt-2"
                            :maxlength="field.key === 'address' ? 500 : 160"
                            :disabled="!capabilities.edit || processing"
                            :aria-invalid="
                                Boolean(errors[`content.business.${field.key}`])
                            "
                        /><InputError
                            :message="errors[`content.business.${field.key}`]"
                        />
                    </div>
                </section>
                <section class="cms-panel space-y-5">
                    <h2>{{ ct('Public contacts') }}</h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            ct(
                                'Leave unknown contacts empty. Empty channels do not appear as buttons.',
                            )
                        }}
                    </p>
                    <div
                        v-for="field in [
                            {
                                key: 'telegram',
                                label: 'telegram',
                                example: 'https://t.me/username',
                            },
                            {
                                key: 'whatsapp',
                                label: 'whatsapp',
                                example: 'https://wa.me/...',
                            },
                            {
                                key: 'email',
                                label: 'email',
                                example: 'mailto:...',
                            },
                            {
                                key: 'maps',
                                label: 'Google Maps link',
                                example: 'https://maps.google.com/...',
                            },
                        ] as const"
                        :key="field.key"
                    >
                        <Label :for="`contact-${field.key}`">{{
                            ct(field.label)
                        }}</Label
                        ><Input
                            :id="`contact-${field.key}`"
                            :model-value="
                                form.content.contacts[field.key] ?? ''
                            "
                            @update:model-value="
                                form.content.contacts[field.key] =
                                    String($event)
                            "
                            class="mt-2"
                            :placeholder="field.example"
                            maxlength="500"
                            :disabled="!capabilities.edit || processing"
                            :aria-invalid="
                                Boolean(errors[`content.contacts.${field.key}`])
                            "
                        /><InputError
                            :message="errors[`content.contacts.${field.key}`]"
                        />
                    </div>
                </section>
            </div>
            <section class="cms-panel space-y-4">
                <h2>{{ ct('Navigation') }}</h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        ct(
                            'Move selected links to change their order. Only existing website pages are allowed.',
                        )
                    }}
                </p>
                <div class="flex flex-wrap gap-4">
                    <label
                        v-for="option in navigation_options"
                        :key="option"
                        class="flex min-h-11 items-center gap-2 text-sm"
                        ><input
                            type="checkbox"
                            :checked="form.content.navigation.includes(option)"
                            :disabled="!capabilities.edit || processing"
                            @change="
                                toggle(
                                    option,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />{{ ct(option) }}</label
                    >
                </div>
                <ol class="space-y-2">
                    <li
                        v-for="(key, index) in form.content.navigation"
                        :key="key"
                        class="flex flex-wrap items-center justify-between gap-3 rounded border p-2"
                    >
                        <span class="text-sm"
                            >{{ index + 1 }}. {{ ct(key) }}</span
                        >
                        <div class="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="
                                    !capabilities.edit ||
                                    processing ||
                                    index === 0
                                "
                                :aria-label="`${ct('Move up')} ${ct(key)}`"
                                @click="
                                    form.content.navigation = moveCmsItem(
                                        form.content.navigation,
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
                                    index === form.content.navigation.length - 1
                                "
                                :aria-label="`${ct('Move down')} ${ct(key)}`"
                                @click="
                                    form.content.navigation = moveCmsItem(
                                        form.content.navigation,
                                        index,
                                        1,
                                    )
                                "
                                >{{ ct('Move down') }}</Button
                            >
                        </div>
                    </li>
                </ol>
            </section>
            <section class="cms-panel">
                <LocalizedFields
                    v-model="form.content.footer_note"
                    field="content.footer_note"
                    :label="ct('Footer note')"
                    :locales="locales"
                    :max="500"
                    multiline
                    :errors="errors"
                    :disabled="!capabilities.edit || processing"
                />
            </section>
            <Button
                type="submit"
                :disabled="!capabilities.edit || processing"
                >{{ ct('Save draft') }}</Button
            >
        </form>
        <CmsLeaveGuard :dirty="dirty"
    /></CmsShell>
</template>
