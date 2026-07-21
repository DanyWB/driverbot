<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ExternalLink,
    ImagePlus,
    Save,
    Star,
    Trash2,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { VehiclePhoto } from '@/types';

const props = defineProps<{
    vehicleId: number;
    photos: VehiclePhoto[];
}>();

const ordered = ref<VehiclePhoto[]>([...props.photos]);
const primaryId = ref<number | null>(
    props.photos.find((photo) => photo.is_primary)?.id ??
        props.photos[0]?.id ??
        null,
);
const fileInput = ref<HTMLInputElement | null>(null);

watch(
    () => props.photos,
    (photos) => {
        ordered.value = [...photos];
        primaryId.value =
            photos.find((photo) => photo.is_primary)?.id ??
            photos[0]?.id ??
            null;
    },
);

const uploadForm = useForm<{ photo: File | null; alt_text: string }>({
    photo: null,
    alt_text: '',
});
const arrangeForm = useForm<{
    ordered_ids: number[];
    primary_id: number | null;
}>({
    ordered_ids: ordered.value.map((photo) => photo.id),
    primary_id: primaryId.value,
});

function selectFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    uploadForm.photo = input.files?.[0] ?? null;
}

function upload(): void {
    uploadForm.post(`/vehicles/${props.vehicleId}/photos`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset();

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function move(index: number, direction: -1 | 1): void {
    const destination = index + direction;

    if (destination < 0 || destination >= ordered.value.length) {
        return;
    }

    const copy = [...ordered.value];
    const [photo] = copy.splice(index, 1);

    if (!photo) {
        return;
    }

    copy.splice(destination, 0, photo);
    ordered.value = copy;
}

function saveArrangement(): void {
    arrangeForm.ordered_ids = ordered.value.map((photo) => photo.id);
    arrangeForm.primary_id = primaryId.value;
    arrangeForm.patch(`/vehicles/${props.vehicleId}/photos`, {
        preserveScroll: true,
    });
}

function remove(photo: VehiclePhoto): void {
    if (!window.confirm(`Delete this photo from the vehicle?`)) {
        return;
    }

    router.delete(`/vehicles/${props.vehicleId}/photos/${photo.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <section id="photos" class="border-t px-4 py-6 sm:px-6 lg:px-8">
        <div
            class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <p class="text-sm text-muted-foreground">Media</p>
                <h2 class="text-lg font-semibold">Photos</h2>
            </div>
            <p class="text-sm text-muted-foreground">
                {{ photos.length }} uploaded
            </p>
        </div>

        <form
            class="mt-5 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end"
            @submit.prevent="upload"
        >
            <div>
                <Label for="vehicle_photo">Image</Label>
                <Input
                    id="vehicle_photo"
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    class="mt-2"
                    @change="selectFile"
                />
                <InputError class="mt-1" :message="uploadForm.errors.photo" />
            </div>
            <div>
                <Label for="photo_alt">Alt text</Label>
                <Input
                    id="photo_alt"
                    v-model="uploadForm.alt_text"
                    class="mt-2"
                />
                <InputError
                    class="mt-1"
                    :message="uploadForm.errors.alt_text"
                />
            </div>
            <Button
                type="submit"
                :disabled="!uploadForm.photo || uploadForm.processing"
                ><ImagePlus />Upload</Button
            >
        </form>

        <div
            v-if="ordered.length"
            class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
            <article
                v-for="(photo, index) in ordered"
                :key="photo.id"
                class="overflow-hidden rounded-md border bg-background"
            >
                <a
                    :href="photo.url"
                    target="_blank"
                    rel="noopener"
                    class="relative block aspect-[3/2] overflow-hidden bg-muted"
                >
                    <img
                        :src="photo.thumbnail_url"
                        :alt="photo.alt_text ?? 'Vehicle photo'"
                        class="h-full w-full object-cover"
                    />
                    <span
                        v-if="primaryId === photo.id"
                        class="absolute top-2 left-2 inline-flex items-center gap-1 rounded bg-background/95 px-2 py-1 text-xs font-medium shadow-sm"
                        ><Star class="size-3 fill-current" />Primary</span
                    >
                </a>
                <div class="flex items-center justify-between gap-2 px-2 py-2">
                    <div class="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            title="Move up"
                            :disabled="index === 0"
                            @click="move(index, -1)"
                            ><ArrowUp /><span class="sr-only"
                                >Move up</span
                            ></Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            title="Move down"
                            :disabled="index === ordered.length - 1"
                            @click="move(index, 1)"
                            ><ArrowDown /><span class="sr-only"
                                >Move down</span
                            ></Button
                        >
                        <Button
                            type="button"
                            :variant="
                                primaryId === photo.id ? 'secondary' : 'ghost'
                            "
                            size="icon-sm"
                            title="Set as primary"
                            @click="primaryId = photo.id"
                            ><Star
                                :class="
                                    primaryId === photo.id ? 'fill-current' : ''
                                "
                            /><span class="sr-only"
                                >Set as primary</span
                            ></Button
                        >
                    </div>
                    <div class="flex items-center gap-1">
                        <Button
                            as-child
                            variant="ghost"
                            size="icon-sm"
                            title="Open original"
                            ><a :href="photo.url" target="_blank" rel="noopener"
                                ><ExternalLink /><span class="sr-only"
                                    >Open original</span
                                ></a
                            ></Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            title="Delete photo"
                            class="text-destructive"
                            @click="remove(photo)"
                            ><Trash2 /><span class="sr-only"
                                >Delete photo</span
                            ></Button
                        >
                    </div>
                </div>
            </article>
        </div>
        <div
            v-else
            class="mt-6 grid min-h-36 place-items-center rounded-md border border-dashed text-sm text-muted-foreground"
        >
            No photos
        </div>

        <div v-if="ordered.length" class="mt-4 flex justify-end">
            <Button
                type="button"
                variant="outline"
                :disabled="arrangeForm.processing || !primaryId"
                @click="saveArrangement"
                ><Save />Save photo order</Button
            >
        </div>
        <InputError
            class="mt-2"
            :message="
                arrangeForm.errors.ordered_ids || arrangeForm.errors.primary_id
            "
        />
    </section>
</template>
