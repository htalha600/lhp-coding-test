<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import RegisterDialog from '@/components/events/RegisterDialog.vue';
import { Badge } from '@/components/ui/badge';
import type { EventCard } from '@/composables/useEvents';

interface EventDetail extends EventCard {
    attendees_count: number;
}

defineProps<{ event: EventDetail }>();

const activeImage = ref(0);
const showRegister = ref(false);

const flash = computed(() => (usePage().props.flash as { success?: string } | undefined)?.success);

function formatDate(iso: string | null): string {
    if (!iso) {
return 'Date TBA';
}

    return new Date(iso).toLocaleString(undefined, {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZoneName: 'short',
    });
}

function formatPrice(price: number | null): string {
    if (price === null) {
        return '';
    }

    return price === 0 ? 'Free' : `$${price.toFixed(2)}`;
}
</script>

<template>
    <Head :title="event.title" />

    <div class="mx-auto flex max-w-4xl flex-col gap-6 p-6">
        <Link href="/events" class="text-sm text-primary hover:underline">← Back to events</Link>

        <p v-if="flash" class="rounded-lg bg-emerald-500/15 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-400">
            {{ flash }}
        </p>

        <!-- Image gallery -->
        <div class="flex flex-col gap-3">
            <div class="aspect-video overflow-hidden rounded-2xl bg-muted">
                <img
                    v-if="event.images.length"
                    :src="event.images[activeImage].url"
                    :alt="event.images[activeImage].alt ?? event.title"
                    class="h-full w-full object-cover"
                />
            </div>
            <div v-if="event.images.length > 1" class="flex gap-2">
                <button
                    v-for="(img, idx) in event.images"
                    :key="idx"
                    class="h-16 w-24 overflow-hidden rounded-lg border-2 transition"
                    :class="activeImage === idx ? 'border-primary' : 'border-transparent opacity-70'"
                    @click="activeImage = idx"
                >
                    <img :src="img.url" :alt="img.alt ?? ''" class="h-full w-full object-cover" />
                </button>
            </div>
        </div>

        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <Badge v-if="event.type" class="capitalize">{{ event.type }}</Badge>
                <Badge v-if="event.status" variant="secondary" class="capitalize">{{ event.status.replace('_', ' ') }}</Badge>
                <Badge v-if="event.price !== null" variant="outline">{{ formatPrice(event.price) }}</Badge>
            </div>

            <h1 class="text-3xl font-bold tracking-tight">{{ event.title }}</h1>
            <p class="text-muted-foreground">{{ event.description }}</p>

            <dl class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-xl border p-4">
                    <dt class="text-xs uppercase text-muted-foreground">When</dt>
                    <dd class="mt-1 font-medium">{{ formatDate(event.date_time) }}</dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-xs uppercase text-muted-foreground">Where</dt>
                    <dd class="mt-1 font-medium">{{ event.location?.label ?? 'Unknown' }}</dd>
                </div>
                <div v-if="event.venue" class="rounded-xl border p-4">
                    <dt class="text-xs uppercase text-muted-foreground">Venue</dt>
                    <dd class="mt-1 font-medium">{{ event.venue }}</dd>
                </div>
                <div v-if="event.organizer" class="rounded-xl border p-4">
                    <dt class="text-xs uppercase text-muted-foreground">Organizer</dt>
                    <dd class="mt-1 font-medium">{{ event.organizer }}</dd>
                </div>
                <div v-if="event.capacity !== null" class="rounded-xl border p-4">
                    <dt class="text-xs uppercase text-muted-foreground">Capacity</dt>
                    <dd class="mt-1 font-medium">{{ event.capacity.toLocaleString() }}</dd>
                </div>
                <div class="rounded-xl border p-4 sm:col-span-2">
                    <dt class="text-xs uppercase text-muted-foreground">Attendees</dt>
                    <dd class="mt-1 font-medium">{{ event.attendees_count }} registered</dd>
                </div>
            </dl>

            <button
                class="mt-2 h-11 w-full rounded-lg bg-primary text-sm font-semibold text-primary-foreground transition hover:opacity-90 active:scale-[0.99] sm:w-auto sm:px-8"
                @click="showRegister = true"
            >
                Register interest
            </button>
        </div>
    </div>

    <RegisterDialog :event="showRegister ? event : null" @close="showRegister = false" />
</template>
