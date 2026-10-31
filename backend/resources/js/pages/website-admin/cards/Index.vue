<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AdminSelect from '@/components/AdminSelect.vue';
import AdminPagination from '@/components/AdminPagination.vue';
import CmsShell from '@/components/website-cms/CmsShell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWebsiteCms } from '@/composables/useWebsiteCms';
type CardRow = {
    id: number;
    name: string;
    inventory_code: string | null;
    type: string;
    year: number | null;
    fleet_eligible: boolean;
    profile_status: string;
    revision: number;
    published_revision: number | null;
    cover_preview_url: string | null;
    edit_url: string;
};
const props = defineProps<{
    filters: {
        search: string | null;
        type: string | null;
        status: string | null;
    };
    vehicles: {
        data: CardRow[];
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();
const { ct } = useWebsiteCms();
const filters = reactive({
    search: props.filters.search ?? '',
    type: props.filters.type ?? '',
    status: props.filters.status ?? '',
});
function search() {
    router.get('/website/cards', filters, {
        preserveState: true,
        replace: true,
    });
}
</script>
<template>
    <Head :title="ct('Website cards')" /><CmsShell
        :title="ct('Website cards')"
        section="cards"
    >
        <form
            class="grid gap-3 rounded-lg border bg-muted/20 p-4 sm:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr_auto]"
            @submit.prevent="search"
        >
            <div>
                <Label for="cms-search">{{ ct('Search') }}</Label
                ><Input
                    id="cms-search"
                    v-model="filters.search"
                    class="mt-2"
                    maxlength="100"
                />
            </div>
            <div>
                <Label for="cms-type">{{ ct('Vehicle type') }}</Label
                ><AdminSelect
                    id="cms-type"
                    v-model="filters.type"
                    class="mt-2"
                    :options="[
                        { value: '', label: ct('All types') },
                        { value: 'car', label: ct('car') },
                        { value: 'scooter', label: ct('scooter') },
                    ]"
                />
            </div>
            <div>
                <Label for="cms-status">{{ ct('Status') }}</Label
                ><AdminSelect
                    id="cms-status"
                    v-model="filters.status"
                    class="mt-2"
                    :options="[
                        { value: '', label: ct('All statuses') },
                        ...['none', 'draft', 'published'].map((value) => ({
                            value,
                            label: ct(value),
                        })),
                    ]"
                />
            </div>
            <Button class="self-end" type="submit">{{ ct('Search') }}</Button>
        </form>
        <div
            v-if="vehicles.data.length"
            class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
            <article
                v-for="vehicle in vehicles.data"
                :key="vehicle.id"
                class="overflow-hidden rounded-lg border"
            >
                <div
                    v-if="vehicle.cover_preview_url"
                    class="aspect-[3/2] bg-muted"
                >
                    <img
                        :src="vehicle.cover_preview_url"
                        :alt="vehicle.name"
                        class="h-full w-full object-contain"
                        loading="lazy"
                    />
                </div>
                <div class="space-y-3 p-4">
                    <div>
                        <p class="text-xs text-muted-foreground">
                            {{ vehicle.inventory_code }} ·
                            {{ ct(vehicle.type) }} {{ vehicle.year }}
                        </p>
                        <h2 class="mt-1 font-semibold">{{ vehicle.name }}</h2>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="rounded border px-2 py-1">{{
                            ct(vehicle.profile_status)
                        }}</span
                        ><span
                            >{{ ct('Revision') }} {{ vehicle.revision }}</span
                        >
                    </div>
                    <p
                        v-if="!vehicle.fleet_eligible"
                        class="text-sm text-amber-700 dark:text-amber-400"
                    >
                        {{ ct('Not eligible for the public catalog') }}
                    </p>
                    <Button as-child variant="outline" class="w-full"
                        ><Link :href="vehicle.edit_url">{{
                            ct('Edit card')
                        }}</Link></Button
                    >
                </div>
            </article>
        </div>
        <section v-else class="cms-panel py-12 text-center">
            <h2>{{ ct('No results') }}</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ ct('Change the filters to find a vehicle.') }}
            </p>
        </section>
        <AdminPagination :paginator="vehicles" />
    </CmsShell>
</template>
