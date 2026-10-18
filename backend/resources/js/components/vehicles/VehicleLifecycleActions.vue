<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, EyeOff, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import type { VehicleListItem } from '@/types';

const props = defineProps<{
    vehicle: VehicleListItem;
    disabled?: boolean;
    hidePublication?: boolean;
}>();
const { t } = useLocale();
type Action = 'archive' | 'restore' | 'delete' | 'unpublish';
const action = ref<Action>('archive');
const open = ref(false);
const form = useForm({ confirmed: true, confirmation_name: '', vehicle: '' });
const labels: Record<Action, string> = {
    archive: 'Move to archive',
    restore: 'Restore vehicle',
    delete: 'Delete permanently',
    unpublish: 'Unpublish vehicle',
};
const explanations: Record<Action, string> = {
    archive:
        'The vehicle will leave the working fleet and disappear from booking. Photos, prices and history are preserved.',
    restore:
        'The vehicle will return to the fleet but remain unpublished. Check its prices and publish it when ready.',
    delete: 'This permanently removes the vehicle, its prices and photos. This cannot be undone.',
    unpublish:
        'Customers will no longer see this vehicle for new bookings. Existing bookings remain unchanged.',
};
const title = computed(() => t(labels[action.value]));
function confirm(next: Action): void {
    form.reset();
    form.clearErrors();
    action.value = next;
    open.value = true;
}
function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (action.value === 'delete') {
        form.delete(`/vehicles/${props.vehicle.id}`, options);
    } else {
        form.post(`/vehicles/${props.vehicle.id}/${action.value}`, options);
    }
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap gap-2">
            <template v-if="vehicle.archived_at">
                <Button
                    type="button"
                    class="transition-colors duration-[350ms] ease-in-out motion-reduce:transition-none"
                    variant="outline"
                    :disabled="disabled"
                    @click="confirm('restore')"
                    ><ArchiveRestore />{{ t('Restore vehicle') }}</Button
                >
                <Button
                    type="button"
                    class="transition-colors duration-[350ms] ease-in-out motion-reduce:transition-none"
                    variant="destructive"
                    :disabled="disabled || !vehicle.can_delete"
                    @click="confirm('delete')"
                    ><Trash2 />{{ t('Delete permanently') }}</Button
                >
            </template>
            <template v-else>
                <Button
                    type="button"
                    v-if="vehicle.is_visible_for_booking && !hidePublication"
                    variant="outline"
                    class="transition-colors duration-[350ms] ease-in-out motion-reduce:transition-none"
                    :disabled="disabled"
                    @click="confirm('unpublish')"
                    ><EyeOff />{{ t('Unpublish vehicle') }}</Button
                >
                <Button
                    type="button"
                    variant="destructive"
                    class="transition-colors duration-[350ms] ease-in-out motion-reduce:transition-none"
                    :disabled="
                        disabled || vehicle.unfinished_bookings_count > 0
                    "
                    @click="confirm('archive')"
                    ><Archive />{{ t('Move to archive') }}</Button
                >
            </template>
        </div>
        <p
            v-if="!vehicle.archived_at && vehicle.unfinished_bookings_count > 0"
            class="max-w-xl text-sm text-muted-foreground"
        >
            {{
                t(
                    'Archive is unavailable while bookings are unfinished. Unpublish the vehicle and complete or cancel those bookings first.',
                )
            }}
        </p>
        <p
            v-if="vehicle.archived_at && !vehicle.can_delete"
            class="max-w-xl text-sm text-muted-foreground"
        >
            {{
                t(
                    'Rental history or occupancy records are preserved. Permanent deletion is unavailable.',
                )
            }}
        </p>
        <Dialog
            :open="open"
            @update:open="
                (value) => {
                    if (!form.processing) open = value;
                }
            "
        >
            <DialogContent
                class="duration-[350ms] ease-in-out motion-reduce:animate-none"
                :show-close-button="!form.processing"
                @escape-key-down="
                    (event) => {
                        if (form.processing) event.preventDefault();
                    }
                "
                @interact-outside="
                    (event) => {
                        if (form.processing) event.preventDefault();
                    }
                "
            >
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>{{
                        t(explanations[action])
                    }}</DialogDescription>
                </DialogHeader>
                <p class="font-semibold break-words">{{ vehicle.name }}</p>
                <form class="space-y-4" @submit.prevent="submit">
                    <div v-if="action === 'delete'">
                        <Label :for="`delete-vehicle-${vehicle.id}`">{{
                            t('Enter the exact vehicle name')
                        }}</Label>
                        <Input
                            :id="`delete-vehicle-${vehicle.id}`"
                            v-model="form.confirmation_name"
                            class="mt-2"
                            autocomplete="off"
                            :disabled="form.processing"
                        />
                    </div>
                    <p
                        v-if="
                            form.errors.vehicle || form.errors.confirmation_name
                        "
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{
                            form.errors.vehicle || form.errors.confirmation_name
                        }}
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="open = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :variant="
                                action === 'delete' || action === 'archive'
                                    ? 'destructive'
                                    : 'default'
                            "
                            :disabled="
                                form.processing ||
                                (action === 'delete' &&
                                    form.confirmation_name !== vehicle.name)
                            "
                            >{{
                                form.processing ? t('Saving…') : title
                            }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
