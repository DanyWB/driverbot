<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CircleCheck, Database, Layers3, ShieldCheck } from '@lucide/vue';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-8 p-6 lg:p-8">
        <header class="flex flex-col gap-1">
            <p class="text-sm text-muted-foreground">Drive Phangan</p>
            <h1 class="text-2xl font-semibold">Operations</h1>
        </header>

        <section aria-labelledby="system-status-heading">
            <div class="mb-3 flex items-center justify-between">
                <h2 id="system-status-heading" class="text-base font-medium">
                    System status
                </h2>
                <span class="flex items-center gap-2 text-sm text-emerald-700">
                    <CircleCheck class="size-4" />
                    Ready
                </span>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-md border p-4">
                    <Layers3 class="mb-3 size-5 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">Backend</p>
                    <p class="mt-1 font-medium">Laravel 13</p>
                </div>

                <div class="rounded-md border p-4">
                    <Database class="mb-3 size-5 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">Database</p>
                    <p class="mt-1 font-medium">PostgreSQL</p>
                </div>

                <div class="rounded-md border p-4">
                    <CircleCheck class="mb-3 size-5 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">Queue and cache</p>
                    <p class="mt-1 font-medium">Redis</p>
                </div>

                <div class="rounded-md border p-4">
                    <ShieldCheck class="mb-3 size-5 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">Admin access</p>
                    <p class="mt-1 font-medium">Private</p>
                </div>
            </div>
        </section>

        <section class="border-t pt-6" aria-labelledby="workspace-heading">
            <h2 id="workspace-heading" class="text-base font-medium">
                Workspace
            </h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Booking operations will appear here after the core data model is
                connected.
            </p>
        </section>
    </div>
</template>
