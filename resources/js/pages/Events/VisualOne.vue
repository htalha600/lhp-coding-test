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
const scrollEl = ref<HTMLElement | null>(null);
const ROW_GAP = 20; // matches gap-5
const ESTIMATED_ROW = 380; // card height + gap; refined by measureElement

// The panel must be a height-bounded scroll container, but the surrounding
// layout uses min-height ancestors that never pin a concrete height — so a pure
// flex-1 child just grows and the window scrolls instead. We measure the panel's
// real top offset and set its height to exactly (viewport - top), which makes it
// the single scroller with no magic numbers and no clipped overflow.
const panelHeight = ref<string>('100svh');

function measurePanelHeight() {
    const el = scrollEl.value;

    if (!el) {
        return;
    }

    const top = el.getBoundingClientRect().top;
    panelHeight.value = `${Math.max(0, window.innerHeight - top)}px`;
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
    measurePanelHeight();
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
        getScrollElement: () => scrollEl.value,
        estimateSize: () => ESTIMATED_ROW + ROW_GAP,
        overscan: 4,
    })),
);

const virtualRows = computed(() => virtualizer.value.getVirtualItems());
const totalHeight = computed(() => virtualizer.value.getTotalSize());

// Load based on real scroll geometry. NEAR_EDGE is the px from an edge that
// triggers a fetch. The inner spacer div carries the full virtual height, so
// scrollEl.scrollHeight is the true total content height (not just rendered
// rows). The handler is bound directly in the template via @scroll, which
// guarantees it fires on the element that actually scrolls. We only auto-load
// after the first user scroll so the initial render never cascades pages.
const NEAR_EDGE = 600;
const hasScrolled = ref(false);

function onScroll() {
    const el = scrollEl.value;

    if (!el || loading.value) {
        return;
    }

    if (el.scrollTop > 0) {
        hasScrolled.value = true;
    }

    if (!hasScrolled.value) {
        return;
    }

    const distanceToBottom = el.scrollHeight - el.scrollTop - el.clientHeight;

    if (distanceToBottom < NEAR_EDGE && hasMore()) {
        loadMore();
    } else if (el.scrollTop < NEAR_EDGE && el.scrollTop > 0 && hasPrev()) {
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
        const el = scrollEl.value;

        if (!el || !hasMore()) {
            break;
        }

        // One screen of buffer beyond the viewport so there's room to scroll.
        const loadedHeight = rows.value.length * (ESTIMATED_ROW + ROW_GAP);

        if (loadedHeight > el.clientHeight * 2) {
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

    if (removedRows > 0 && scrollEl.value) {
        scrollEl.value.scrollTop -= removedRows * (ESTIMATED_ROW + ROW_GAP);
    }
});

// When a previous page is prepended, every existing row shifts down — scroll
// down by the inserted height so the cards under the viewport stay put.
let lastPrepended = 0;
watch(prependedCount, (prepended) => {
    const addedRows = (prepended - lastPrepended) / columns.value;
    lastPrepended = prepended;

    if (addedRows > 0 && scrollEl.value) {
        // Adjust after the virtualizer re-lays-out the grown list.
        nextTick(() => {
            if (scrollEl.value) {
                scrollEl.value.scrollTop += addedRows * (ESTIMATED_ROW + ROW_GAP);
            }
        });
    }
});

// Reset the window bookkeeping + scroll to top before re-running filters.
async function onApply() {
    lastDropped = 0;
    lastPrepended = 0;

    if (scrollEl.value) {
        scrollEl.value.scrollTop = 0;
    }

    await applyFilters();
    await ensureScrollable();
}

onMounted(async () => {
    if (scrollEl.value) {
        computeColumns(scrollEl.value.clientWidth);
    }

    measurePanelHeight();
    window.addEventListener('resize', measurePanelHeight, { passive: true });

    await loadMore();
    await ensureScrollable();
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', measurePanelHeight);
});
</script>

<template>
    <Head title="Event Visuals — Grid" />

    <div class="flex min-h-0 flex-1 flex-col bg-linear-to-b from-background to-muted/30">
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

        <!-- Virtualized scroll area: only on-screen rows are in the DOM. Its
             height is measured to exactly fill the viewport below its top edge,
             making it the single scroll container (the virtualizer scrolls
             internally; the window does not scroll). -->
        <div
            ref="scrollEl"
            class="mt-6 min-h-0 overflow-y-auto"
            :style="{ height: panelHeight }"
            @scroll="onScroll"
        >
            <div class="mx-auto w-full max-w-7xl px-6 pb-6">
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
                        :style="{ transform: `translateY(${vRow.start}px)`, paddingBottom: `${ROW_GAP}px` }"
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
