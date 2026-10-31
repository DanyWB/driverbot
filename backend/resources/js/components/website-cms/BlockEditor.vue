<script setup lang="ts">
import AdminSelect from '@/components/AdminSelect.vue';
import LocalizedFields from '@/components/website-cms/LocalizedFields.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWebsiteCms } from '@/composables/useWebsiteCms';
import { moveCmsItem, newCmsItem } from '@/lib/websiteCms';
import type { CmsBlock, CmsLocale, StaticAsset } from '@/types/websiteCms';
const block = defineModel<CmsBlock>({ required: true });
defineProps<{
    index: number;
    locales: CmsLocale[];
    assets: StaticAsset[];
    errors: Record<string, string>;
    disabled: boolean;
}>();
const { ct } = useWebsiteCms();
</script>
<template>
    <div class="space-y-6">
        <LocalizedFields
            v-model="block.props.heading"
            :field="`content.blocks.${index}.props.heading`"
            :label="ct('Heading')"
            :locales="locales"
            :max="160"
            :errors="errors"
            :disabled="disabled"
        />
        <LocalizedFields
            v-if="block.props.body"
            v-model="block.props.body"
            :field="`content.blocks.${index}.props.body`"
            :label="ct('Text')"
            :locales="locales"
            :max="4000"
            multiline
            :errors="errors"
            :disabled="disabled"
        />
        <div
            v-if="block.type === 'hero' || block.type === 'text_image'"
            class="space-y-3"
        >
            <Label :for="`block-image-${block.id}`">{{
                ct('Approved image')
            }}</Label
            ><AdminSelect
                :id="`block-image-${block.id}`"
                v-model="block.props.image_ref!"
                :disabled="disabled"
                :options="[
                    { value: null, label: ct('None') },
                    ...assets.map((asset) => ({
                        value: asset.id,
                        label: ct(asset.id),
                    })),
                ]"
            /><img
                v-if="
                    assets.find((asset) => asset.id === block.props.image_ref)
                "
                :src="
                    assets.find((asset) => asset.id === block.props.image_ref)
                        ?.url
                "
                alt=""
                class="max-h-56 w-full rounded bg-muted object-contain"
            />
        </div>
        <div v-if="block.type === 'hero'">
            <Label :for="`block-action-${block.id}`">{{
                ct('Button destination')
            }}</Label
            ><AdminSelect
                :id="`block-action-${block.id}`"
                v-model="block.props.action!"
                :disabled="disabled"
                class="mt-2"
                :options="
                    ['cars', 'scooters'].map((value) => ({
                        value,
                        label: ct(value),
                    }))
                "
            />
        </div>
        <LocalizedFields
            v-if="block.props.action_label"
            v-model="block.props.action_label"
            :field="`content.blocks.${index}.props.action_label`"
            :label="ct('Button text')"
            :locales="locales"
            :max="160"
            :errors="errors"
            :disabled="disabled"
        />
        <div v-if="block.type === 'catalog'" class="grid gap-4 sm:grid-cols-2">
            <div>
                <Label :for="`block-type-${block.id}`">{{
                    ct('Vehicle type')
                }}</Label
                ><AdminSelect
                    :id="`block-type-${block.id}`"
                    v-model="block.props.vehicle_type!"
                    :disabled="disabled"
                    class="mt-2"
                    :options="
                        ['all', 'car', 'scooter'].map((value) => ({
                            value,
                            label: ct(value),
                        }))
                    "
                />
            </div>
            <div>
                <Label :for="`block-limit-${block.id}`">{{
                    ct('Card count')
                }}</Label
                ><Input
                    :id="`block-limit-${block.id}`"
                    v-model="block.props.limit"
                    type="number"
                    min="1"
                    max="12"
                    step="1"
                    :disabled="disabled"
                    class="mt-2"
                    @change="block.props.limit = Number(block.props.limit)"
                />
            </div>
        </div>
        <div v-if="block.type === 'contact_cta'">
            <Label :for="`block-channel-${block.id}`">{{ ct('Channel') }}</Label
            ><AdminSelect
                :id="`block-channel-${block.id}`"
                v-model="block.props.channel!"
                :disabled="disabled"
                class="mt-2"
                :options="
                    ['telegram', 'whatsapp', 'email'].map((value) => ({
                        value,
                        label: ct(value),
                    }))
                "
            />
            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    ct(
                        'Leave unknown contacts empty. Empty channels do not appear as buttons.',
                    )
                }}
            </p>
        </div>
        <div
            v-if="block.type === 'steps' || block.type === 'faq'"
            class="space-y-4"
        >
            <div
                v-for="(item, itemIndex) in block.props.items"
                :key="item.id"
                class="space-y-4 rounded-lg border bg-muted/10 p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <strong class="text-sm">{{ itemIndex + 1 }}</strong>
                    <div class="flex flex-wrap gap-1">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="disabled || itemIndex === 0"
                            :aria-label="`${ct('Move up')} ${itemIndex + 1}`"
                            @click="
                                block.props.items = moveCmsItem(
                                    block.props.items!,
                                    itemIndex,
                                    -1,
                                )
                            "
                            >{{ ct('Move up') }}</Button
                        ><Button
                            type="button"
                            variant="outline"
                            :disabled="
                                disabled ||
                                itemIndex === block.props.items!.length - 1
                            "
                            :aria-label="`${ct('Move down')} ${itemIndex + 1}`"
                            @click="
                                block.props.items = moveCmsItem(
                                    block.props.items!,
                                    itemIndex,
                                    1,
                                )
                            "
                            >{{ ct('Move down') }}</Button
                        ><Button
                            type="button"
                            variant="ghost"
                            class="text-destructive"
                            :disabled="disabled"
                            @click="block.props.items!.splice(itemIndex, 1)"
                            >{{ ct('Remove') }}</Button
                        >
                    </div>
                </div>
                <template v-if="block.type === 'steps'"
                    ><LocalizedFields
                        v-model="item.title!"
                        :field="`content.blocks.${index}.props.items.${itemIndex}.title`"
                        :label="ct('Title')"
                        :locales="locales"
                        :max="160"
                        :errors="errors"
                        :disabled="disabled" /><LocalizedFields
                        v-model="item.body!"
                        :field="`content.blocks.${index}.props.items.${itemIndex}.body`"
                        :label="ct('Text')"
                        :locales="locales"
                        :max="4000"
                        multiline
                        :errors="errors"
                        :disabled="disabled" /></template
                ><template v-else
                    ><LocalizedFields
                        v-model="item.question!"
                        :field="`content.blocks.${index}.props.items.${itemIndex}.question`"
                        :label="ct('Question')"
                        :locales="locales"
                        :max="160"
                        :errors="errors"
                        :disabled="disabled" /><LocalizedFields
                        v-model="item.answer!"
                        :field="`content.blocks.${index}.props.items.${itemIndex}.answer`"
                        :label="ct('Answer')"
                        :locales="locales"
                        :max="4000"
                        multiline
                        :errors="errors"
                        :disabled="disabled"
                /></template>
            </div>
            <Button
                type="button"
                variant="outline"
                :disabled="
                    disabled ||
                    (block.props.items?.length ?? 0) >=
                        (block.type === 'steps' ? 8 : 20)
                "
                @click="block.props.items!.push(newCmsItem(block.type))"
                >{{ ct('Add item') }}</Button
            >
        </div>
    </div>
</template>
