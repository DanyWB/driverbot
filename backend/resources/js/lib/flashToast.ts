import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { useLocale } from '@/composables/useLocale';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    const { t } = useLocale();

    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        toast[data.type](t(data.message));
    });
}
