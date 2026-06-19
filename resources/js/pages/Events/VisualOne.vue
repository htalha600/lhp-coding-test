<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import CreateEventDialog from '@/components/events/CreateEventDialog.vue';
import EventFilterBar from '@/components/events/EventFilterBar.vue';
import RegisterDialog from '@/components/events/RegisterDialog.vue';
import { useEvents } from '@/composables/useEvents';
import type { EventCard, EventFilters } from '@/composables/useEvents';

const props = defineProps<{
    filters: EventFilters;
    cities: string[];
}>();

const { form, events, total, loading, loadedOnce, hasMore, loadMore, applyFilters } = useEvents(props.filters);

const selected = ref<EventCard | null>(null);
const showCreate = ref(false);

const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

// Track the active image index per card for the hover image switch.
const activeImage = ref<Record<string, number>>({});
function cycleImage(id: string, count: number) {
    activeImage.value[id] = ((activeImage.value[id] ?? 0) + 1) % count;
}

function formatDate(iso: string | null): string {
    if (!iso) {
return 'Date TBA';
}

    return new Date(iso).toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZoneName: 'short',
    });
}

onMounted(() => {
    observer = new IntersectionObserver((entries) => entries[0]?.isIntersecting && loadMore(), {
        rootMargin: '600px',
    });

    if (sentinel.value) {
observer.observe(sentinel.value);
}

    loadMore();
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <Head title="Event Visuals — Grid" />

    <div class="min-h-screen bg-linear-to-b from-background to-muted/30">
        <div class="mx-auto flex max-w-7xl flex-col gap-6 p-6">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <p class="text-sm font-medium uppercase tracking-wider text-primary">Visual 1</p>
                    <h1 class="text-3xl font-bold tracking-tight">Browse Events</h1>
                    <p class="text-muted-foreground">A card grid of everything happening around the world.</p>
                </div>
                <button
                    class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-primary-foreground transition hover:opacity-90 active:scale-95"
                    @click="showCreate = true"
                >
                    + Add event
                </button>
            </header>

            <EventFilterBar v-model:form="form" :cities="cities" :total="total" @apply="applyFilters" />

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="(event, i) in events"
                    :key="event.id"
                    class="group fade-in-up flex flex-col overflow-hidden rounded-2xl border bg-card shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-xl"
                    :style="{ animationDelay: `${(i % 24) * 40}ms` }"
                >
                    <div
                        class="relative aspect-16/10 overflow-hidden bg-muted"
                        @mouseenter="event.images.length > 1 && cycleImage(event.id, event.images.length)"
                    >
                        <img
                            v-if="event.images.length"
                            :src="event.images[activeImage[event.id] ?? 0].url"
                            :alt="event.images[activeImage[event.id] ?? 0].alt ?? event.title"
                            class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                        />
                        <span v-if="event.images.length > 1" class="absolute bottom-3 right-3 flex gap-1">
                            <span
                                v-for="(img, idx) in event.images"
                                :key="idx"
                                class="h-1.5 w-1.5 rounded-full bg-white/90 transition"
                                :class="(activeImage[event.id] ?? 0) === idx ? 'opacity-100' : 'opacity-40'"
                            />
                        </span>
                    </div>

                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <h2 class="line-clamp-1 text-lg font-semibold">{{ event.title }}</h2>
                        <p class="line-clamp-2 text-sm text-muted-foreground">{{ event.description }}</p>

                        <div class="mt-1 flex flex-col gap-1 text-sm">
                            <span class="flex items-center gap-1.5 text-foreground/80">
                                📍 {{ event.location?.label ?? 'Unknown location' }}
                            </span>
                            <span class="flex items-center gap-1.5 text-foreground/80">🗓 {{ formatDate(event.date_time) }}</span>
                        </div>

                        <div class="mt-auto flex justify-end pt-3">
                            <button
                                class="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground transition hover:opacity-90 active:scale-95"
                                @click="selected = event"
                            >
                                Register
                            </button>
                        </div>
                    </div>
                </article>
            </div>

            <div v-if="loading" class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="n in 6" :key="n" class="h-72 animate-pulse rounded-2xl bg-muted" />
            </div>

            <p v-if="loadedOnce && !loading && events.length === 0" class="py-16 text-center text-muted-foreground">
                No events match your filters.
            </p>

            <div ref="sentinel" class="h-4" />
            <p v-if="loadedOnce && !hasMore() && events.length" class="py-6 text-center text-sm text-muted-foreground">
                You've reached the end.
            </p>
        </div>
    </div>

    <RegisterDialog :event="selected" @close="selected = null" />
    <CreateEventDialog :open="showCreate" @close="showCreate = false" @created="applyFilters" />
</template>

<style scoped>
@keyframes fade-in-up {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
.fade-in-up {
    animation: fade-in-up 0.4s ease-out both;
}
</style>
