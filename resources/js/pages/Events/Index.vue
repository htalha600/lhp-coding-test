<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    filters: Record<string, unknown>;
    statuses: string[];
    cities: string[];
}>();
</script>

<template>
    <Head title="Events" />

    <div class="flex min-h-screen flex-col items-center justify-center gap-8 bg-linear-to-b from-background to-muted/40 p-6">
        <div class="max-w-xl text-center">
            <h1 class="text-4xl font-bold tracking-tight">Event Visuals</h1>
            <p class="mt-2 text-muted-foreground">
                Browse a fully-seeded, global event dataset in two different ways. Pick a view to get started.
            </p>
        </div>

        <div class="grid w-full max-w-3xl grid-cols-1 gap-5 sm:grid-cols-2">
            <Link
                href="/events-visual-1"
                class="group rounded-2xl border bg-card p-6 shadow-sm transition-all hover:-translate-y-1 hover:shadow-xl"
            >
                <div class="text-3xl">🗂️</div>
                <h2 class="mt-3 text-xl font-semibold">Visual 1 — Card Grid</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    An animated, filterable grid of event cards with images, prices and locations.
                </p>
                <span class="mt-4 inline-block text-sm font-medium text-primary group-hover:underline">Open grid →</span>
            </Link>

            <Link
                href="/events-visual-2"
                class="group rounded-2xl border bg-card p-6 shadow-sm transition-all hover:-translate-y-1 hover:shadow-xl"
            >
                <div class="text-3xl">🗺️</div>
                <h2 class="mt-3 text-xl font-semibold">Visual 2 — Map</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    An interactive world map with event pins, popups and a synced side list.
                </p>
                <span class="mt-4 inline-block text-sm font-medium text-primary group-hover:underline">Open map →</span>
            </Link>
        </div>
    </div>
</template>
