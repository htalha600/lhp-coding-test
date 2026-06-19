<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Building2, CalendarDays, MapPin, Plus, Ticket } from '@lucide/vue';
import { useVirtualizer } from '@tanstack/vue-virtual';
import { useResizeObserver } from '@vueuse/core';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CreateEventDialog from '@/components/events/CreateEventDialog.vue';
import EventFilterBar from '@/components/events/EventFilterBar.vue';
import RegisterDialog from '@/components/events/RegisterDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useEvents } from '@/composables/useEvents';
import type { EventCard, EventFilters } from '@/composables/useEvents';

const props = defineProps<{
    filters: EventFilters;
    cities: string[];
}>();

// Keep at most ~600 events in memory; the bidirectional sliding window loads
// pages as you scroll in either direction and trims the far end, so both the
// DOM (virtualized) and the JS array stay bounded without breaking scroll-up.
const {
    form,
    events,
    total,
    loading,
    loadedOnce,
    droppedCount,
    prependedCount,
    hasMore,
    hasPrev,
    loadMore,
    loadPrev,
    applyFilters,
} = useEvents(props.filters, { maxItems: 600 });

const selected = ref<EventCard | null>(null);
const showCreate = ref(false);

// Track the active image index per card for the hover image switch.
const activeImage = ref<Record<string, number>>({});
function cycleImage(id: string, count: number) {
    activeImage.value[id] = ((activeImage.value[id] ?? 0) + 1) % count;
}

function formatPrice(price: number | null): string {
    if (price === null) {
        return '';
    }

    return price === 0 ? 'Free' : `$${price.toFixed(2)}`;
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

// --- Virtualization -------------------------------------------------------
// Only the rows near the viewport are rendered, so memory stays flat no matter
// how far the user scrolls. We virtualize ROWS; each row holds `columns` cards.
//
// The single scroller is the WINDOW (document), not an inner panel — the app
// layout uses min-h-svh ancestors that let the document grow naturally, so the
// page produces exactly one (outer) scrollbar. The virtualizer is pointed at
// document.documentElement and offset by the grid's distance from the document
// top (scrollMargin), so virtual positions line up with the real page scroll.
const scrollEl = ref<HTMLElement | null>(null); // sizing/column measurement only
const gridEl = ref<HTMLElement | null>(null); // virtualized grid; gives scroll offset
const ROW_GAP = 20; // matches gap-5
const ESTIMATED_ROW = 380; // card height + gap; refined by measureElement

// Distance from the document top to the top of the virtualized grid. The
// virtualizer needs this so its internal scroll math matches window scroll.
const scrollMargin = ref(0);

function measureScrollMargin() {
    const el = gridEl.value;

    if (!el) {
        return;
    }

    scrollMargin.value = el.getBoundingClientRect().top + window.scrollY;
}

const columns = ref(3);
function computeColumns(width: number) {
    if (width < 640) {
        columns.value = 1;
    } else if (width < 1024) {
        columns.value = 2;
    } else {
        columns.value = 3;
    }
}

useResizeObserver(scrollEl, (entries) => {
    computeColumns(entries[0].contentRect.width);
    measureScrollMargin();
});

// Chunk the flat events list into rows of `columns`.
const rows = computed<EventCard[][]>(() => {
    const cols = columns.value;
    const out: EventCard[][] = [];

    for (let i = 0; i < events.value.length; i += cols) {
        out.push(events.value.slice(i, i + cols));
    }

    return out;
});

const virtualizer = useVirtualizer(
    computed(() => ({
        count: rows.value.length,
        // The window is the scroller. documentElement is the scrollable root;
        // scrollMargin offsets the virtual coordinate space by the grid's
        // distance from the document top so positions match the real scroll.
        getScrollElement: () => (typeof document !== 'undefined' ? document.documentElement : null),
        estimateSize: () => ESTIMATED_ROW + ROW_GAP,
        scrollMargin: scrollMargin.value,
        overscan: 4,
    })),
);

const virtualRows = computed(() => virtualizer.value.getVirtualItems());
const totalHeight = computed(() => virtualizer.value.getTotalSize());

// Load based on real window scroll geometry. NEAR_EDGE is the px from an edge
// that triggers a fetch. The window is the scroller, so we read documentElement
// geometry. We only auto-load after the first user scroll so the initial render
// never cascades pages.
const NEAR_EDGE = 600;
const hasScrolled = ref(false);

function onScroll() {
    if (loading.value) {
        return;
    }

    const doc = document.documentElement;
    const scrollTop = window.scrollY;

    if (scrollTop > 0) {
        hasScrolled.value = true;
    }

    if (!hasScrolled.value) {
        return;
    }

    const distanceToBottom = doc.scrollHeight - scrollTop - window.innerHeight;

    if (distanceToBottom < NEAR_EDGE && hasMore()) {
        loadMore();
    } else if (scrollTop < scrollMargin.value + NEAR_EDGE && scrollTop > 0 && hasPrev()) {
        loadPrev();
    }
}

// Load just enough pages (bounded) to make the list scrollable, so there's
// always something to scroll toward even on a very tall screen. The list is
// virtualized, so neither el.scrollHeight (rendered rows only) nor the
// virtualizer's totalHeight (updates async after layout) is reliable right
// after a fetch. We estimate the loaded content height directly from the row
// count, which is accurate the moment loadMore() resolves.
async function ensureScrollable() {
    for (let i = 0; i < 4; i++) {
        if (!hasMore()) {
            break;
        }

        // One screen of buffer beyond the viewport so there's room to scroll.
        const loadedHeight = rows.value.length * (ESTIMATED_ROW + ROW_GAP);

        if (loadedHeight > window.innerHeight * 2) {
            break;
        }

        await loadMore();
    }
}

// Re-measure rows when the column count changes (card heights shift).
watch(columns, () => nextTick(() => virtualizer.value.measure()));

// When old pages are trimmed off the front, rows shift up and totalSize shrinks
// — scroll up by the removed height so the view doesn't jump.
let lastDropped = 0;
watch(droppedCount, (dropped) => {
    const removedRows = (dropped - lastDropped) / columns.value;
    lastDropped = dropped;

    if (removedRows > 0) {
        window.scrollBy(0, -removedRows * (ESTIMATED_ROW + ROW_GAP));
    }
});

// When a previous page is prepended, every existing row shifts down — scroll
// down by the inserted height so the cards under the viewport stay put.
let lastPrepended = 0;
watch(prependedCount, (prepended) => {
    const addedRows = (prepended - lastPrepended) / columns.value;
    lastPrepended = prepended;

    if (addedRows > 0) {
        // Adjust after the virtualizer re-lays-out the grown list.
        nextTick(() => {
            window.scrollBy(0, addedRows * (ESTIMATED_ROW + ROW_GAP));
        });
    }
});

// Reset the window bookkeeping + scroll to top before re-running filters.
async function onApply() {
    lastDropped = 0;
    lastPrepended = 0;

    window.scrollTo(0, 0);

    await applyFilters();
    await ensureScrollable();
}

onMounted(async () => {
    if (scrollEl.value) {
        computeColumns(scrollEl.value.clientWidth);
    }

    await nextTick();
    measureScrollMargin();
    window.addEventListener('resize', measureScrollMargin, { passive: true });
    window.addEventListener('scroll', onScroll, { passive: true });

    await loadMore();
    await nextTick();
    measureScrollMargin();
    await ensureScrollable();
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', measureScrollMargin);
    window.removeEventListener('scroll', onScroll);
});
</script>

<template>
    <Head title="Event Visuals — Grid" />

    <div ref="scrollEl" class="flex flex-1 flex-col bg-linear-to-b from-background to-muted/30">
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-6 pt-6">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <p class="text-sm font-medium uppercase tracking-wider text-primary">Visual 1</p>
                    <h1 class="text-3xl font-bold tracking-tight">Browse Events</h1>
                    <p class="text-muted-foreground">A card grid of everything happening around the world.</p>
                </div>
                <Button size="lg" class="active:scale-95" @click="showCreate = true">
                    <Plus class="size-4" />
                    Add event
                </Button>
            </header>

            <EventFilterBar v-model:form="form" :cities="cities" :total="total" @apply="onApply" />
        </div>

        <!-- Virtualized grid. The window is the single scroller; only the rows
             near the viewport are in the DOM. The grid wrapper reports its
             offset from the document top (scrollMargin) so virtual positions
             line up with the real page scroll. -->
        <div class="mt-6">
            <div ref="gridEl" class="mx-auto w-full max-w-7xl px-6 pb-6">
                <p v-if="loadedOnce && !loading && events.length === 0" class="py-16 text-center text-muted-foreground">
                    No events match your filters.
                </p>

                <!-- Skeleton grid while the first page is loading. -->
                <div
                    v-if="!loadedOnce && loading"
                    class="grid gap-5"
                    :class="columns === 1 ? 'grid-cols-1' : columns === 2 ? 'grid-cols-2' : 'grid-cols-3'"
                >
                    <div v-for="n in columns * 3" :key="n" class="flex flex-col gap-3 rounded-xl border">
                        <Skeleton class="aspect-16/10 w-full rounded-b-none" />
                        <div class="flex flex-col gap-2 p-4">
                            <Skeleton class="h-5 w-2/3" />
                            <Skeleton class="h-4 w-full" />
                            <Skeleton class="h-4 w-1/2" />
                        </div>
                    </div>
                </div>

                <div v-if="rows.length" class="relative w-full" :style="{ height: `${totalHeight}px` }">
                    <div
                        v-for="vRow in virtualRows"
                        :key="vRow.key"
                        :ref="(el) => virtualizer.measureElement(el as Element)"
                        :data-index="vRow.index"
                        class="absolute left-0 top-0 grid w-full gap-5 px-1 pt-1"
                        :class="columns === 1 ? 'grid-cols-1' : columns === 2 ? 'grid-cols-2' : 'grid-cols-3'"
                        :style="{ transform: `translateY(${vRow.start - scrollMargin}px)`, paddingBottom: `${ROW_GAP}px` }"
                    >
                        <Card
                            v-for="event in rows[vRow.index]"
                            :key="event.id"
                            class="group card-in gap-0 overflow-hidden py-0 pb-4 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl"
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
                                <div class="absolute left-3 top-3 flex flex-wrap gap-1.5">
                                    <Badge v-if="event.type" class="capitalize shadow-sm">{{ event.type }}</Badge>
                                    <Badge
                                        v-if="event.status && event.status !== 'published'"
                                        variant="secondary"
                                        class="capitalize shadow-sm"
                                    >
                                        {{ event.status.replace('_', ' ') }}
                                    </Badge>
                                </div>
                                <Badge
                                    v-if="event.price !== null"
                                    variant="secondary"
                                    class="absolute right-3 top-3 shadow-sm"
                                >
                                    {{ formatPrice(event.price) }}
                                </Badge>
                                <span v-if="event.images.length > 1" class="absolute bottom-3 right-3 flex gap-1">
                                    <span
                                        v-for="(img, idx) in event.images"
                                        :key="idx"
                                        class="h-1.5 w-1.5 rounded-full bg-white/90 transition"
                                        :class="(activeImage[event.id] ?? 0) === idx ? 'opacity-100' : 'opacity-40'"
                                    />
                                </span>
                            </div>

                            <CardContent class="flex flex-1 flex-col gap-2 px-4 pt-4">
                                <h2 class="line-clamp-1 text-lg leading-none font-semibold">{{ event.title }}</h2>
                                <p class="line-clamp-2 text-sm text-muted-foreground">{{ event.description }}</p>

                                <div class="mt-1 flex flex-col gap-1.5 text-sm">
                                    <span class="flex items-center gap-1.5 text-foreground/80">
                                        <MapPin class="size-4 shrink-0 text-primary" />
                                        <span class="truncate">{{ event.location?.label ?? 'Unknown location' }}</span>
                                    </span>
                                    <span class="flex items-center gap-1.5 text-foreground/80">
                                        <CalendarDays class="size-4 shrink-0 text-primary" />
                                        {{ formatDate(event.date_time) }}
                                    </span>
                                    <span v-if="event.venue" class="flex items-center gap-1.5 text-foreground/80">
                                        <Building2 class="size-4 shrink-0 text-primary" />
                                        <span class="truncate">{{ event.venue }}</span>
                                    </span>
                                </div>
                            </CardContent>

                            <CardFooter class="justify-end px-4 pt-3">
                                <Button size="sm" class="active:scale-95" @click="selected = event">
                                    <Ticket class="size-4" />
                                    Register
                                </Button>
                            </CardFooter>
                        </Card>
                    </div>
                </div>

                <p v-if="loading" class="py-6 text-center text-sm text-muted-foreground">Loading more…</p>
                <p
                    v-if="loadedOnce && !hasMore() && events.length"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    You've reached the end.
                </p>
            </div>
        </div>
    </div>

    <RegisterDialog :event="selected" @close="selected = null" />
    <CreateEventDialog :open="showCreate" @close="showCreate = false" @created="onApply" />
</template>

<style scoped>
/* Mount-only fade: runs when a card is virtualized into view, but is short and
   subtle so re-mounting on scroll never feels janky. */
@keyframes card-in {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
.card-in {
    animation: card-in 0.25s ease-out both;
}
</style>
