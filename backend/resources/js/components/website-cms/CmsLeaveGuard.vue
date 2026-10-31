<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
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
const props = defineProps<{ dirty: boolean }>();
const { ct } = useWebsiteCms();
const destination = ref<string | null>(null);
let removeListener: (() => void) | undefined;
let allow = false;
function unload(event: BeforeUnloadEvent) {
    if (props.dirty) {
        event.preventDefault();
        event.returnValue = '';
    }
}
onMounted(() => {
    window.addEventListener('beforeunload', unload);
    removeListener = router.on('before', (event) => {
        if (
            props.dirty &&
            !allow &&
            event.detail.visit.method.toLowerCase() === 'get'
        ) {
            event.preventDefault();
            destination.value = event.detail.visit.url.href;
        }
    });
});
onUnmounted(() => {
    window.removeEventListener('beforeunload', unload);
    removeListener?.();
});
function leave() {
    if (!destination.value) {
        return;
    }

    allow = true;
    router.visit(destination.value);
}
</script>
<template>
    <Dialog
        :open="destination !== null"
        @update:open="!$event && (destination = null)"
        ><DialogContent
            ><DialogHeader
                ><DialogTitle>{{ ct('Unsaved changes') }}</DialogTitle
                ><DialogDescription>{{
                    ct('Leave this editor? Your changes have not been saved.')
                }}</DialogDescription></DialogHeader
            ><DialogFooter
                ><Button
                    type="button"
                    variant="outline"
                    @click="destination = null"
                    >{{ ct('Cancel') }}</Button
                ><Button type="button" variant="destructive" @click="leave">{{
                    ct('Leave editor')
                }}</Button></DialogFooter
            ></DialogContent
        ></Dialog
    >
</template>
