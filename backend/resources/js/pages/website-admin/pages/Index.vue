<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import CmsShell from '@/components/website-cms/CmsShell.vue';
import { Button } from '@/components/ui/button';
import { useWebsiteCms } from '@/composables/useWebsiteCms';
import type { CmsPublication } from '@/types/websiteCms';
defineProps<{
    pages: (CmsPublication & {
        key: string;
        status: string;
        edit_url: string;
    })[];
}>();
const { ct } = useWebsiteCms();
</script>
<template>
    <Head :title="ct('Website pages')" /><CmsShell
        :title="ct('Website pages')"
        section="pages"
        ><div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="page in pages"
                :key="page.key"
                class="cms-panel space-y-4"
            >
                <h2>{{ ct(page.key) }}</h2>
                <p class="text-sm">
                    {{ ct(page.status) }} · {{ ct('Revision') }}
                    {{ page.revision }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ ct('Published revision') }}:
                    {{ page.published_revision ?? ct('Not published') }}
                </p>
                <Button as-child variant="outline" class="w-full"
                    ><Link :href="page.edit_url">{{
                        ct('Edit page')
                    }}</Link></Button
                >
            </article>
        </div></CmsShell
    >
</template>
