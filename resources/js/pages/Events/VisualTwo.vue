<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarDays, MapPin, Plus } from '@lucide/vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CreateEventDialog from '@/components/events/CreateEventDialog.vue';
import EventFilterBar from '@/components/events/EventFilterBar.vue';
import RegisterDialog from '@/components/events/RegisterDialog.vue';
import { Button } from '@/components/ui/button';
import { useEvents } from '@/composables/useEvents';
import type { EventCard, EventFilters } from '@/composables/useEvents';

// Self-contained SVG pin so we don't depend on Leaflet's bundled PNG icons
// (which can fail to resolve and render as broken images).
const pinIcon = L.divIcon({
    className: 'event-pin',
    html: `<svg width="26" height="38" viewBox="0 0 26 38" xmlns="http://www.w3.org/2000/svg">
        <path d="M13 0C5.8 0 0 5.8 0 13c0 9.2 13 25 13 25s13-15.8 13-25C26 5.8 20.2 0 13 0z" fill="#6366f1"/>
        <circle cx="13" cy="13" r="5" fill="#fff"/>
    </svg>`,
    iconSize: [26, 38],
    iconAnchor: [13, 38],
    popupAnchor: [0, -34],
});

const props = defineProps<{
    filters: EventFilters;
    cities: string[];
}>();

const { form, events, total, loading, hasMore, loadMore, applyFilters } = useEvents(props.filters);

const selected = ref<EventCard | null>(null);
const showCreate = ref(false);
const mapEl = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
let markerLayer: L.LayerGroup | null = null;
// Fit the view to markers only on the first render / after a filter change,
// never on incremental loads — otherwise the user's zoom keeps getting reset.
let shouldFit = true;

function formatDate(iso: string | null): string {
    if (!iso) {
return 'Date TBA';
}

    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function renderMarkers() {
    if (!map || !markerLayer) {
return;
}

    markerLayer.clearLayers();
    const points: L.LatLngExpression[] = [];

    for (const event of events.value) {
        if (event.latitude == null || event.longitude == null) {
continue;
}

        const marker = L.marker([event.latitude, event.longitude], { icon: pinIcon });
        marker.bindPopup(
            `<div style="min-width:180px">
                <strong>${event.title}</strong><br/>
                <span style="color:#666">${event.location?.label ?? ''}</span><br/>
                <span style="color:#666">${formatDate(event.date_time)}</span><br/>
                <button data-event="${event.id}" class="map-register"
                    style="margin-top:6px;padding:4px 10px;border-radius:6px;background:#6366f1;color:#fff;border:none;cursor:pointer">
                    Register
                </button>
            </div>`,
        );
        marker.addTo(markerLayer);
        points.push([event.latitude, event.longitude]);
    }

    // Only auto-fit once (initial load / after filtering); never on incremental
    // page loads, so the user's manual zoom/pan is preserved.
    if (points.length && shouldFit) {
        map.fitBounds(L.latLngBounds(points), { padding: [40, 40], maxZoom: 6 });
        shouldFit = false;
    }
}

// Open the register dialog when a popup's button is clicked.
function onPopupClick(e: Event) {
    const target = e.target as HTMLElement;

    if (target.classList.contains('map-register')) {
        const id = target.getAttribute('data-event');
        const event = events.value.find((ev) => ev.id === id);

        if (event) {
selected.value = event;
}
    }
}

watch(events, renderMarkers, { deep: true });

async function loadAllForMap() {
    // Pull a few pages so the map has a useful spread of pins.
    for (let i = 0; i < 4 && hasMore(); i++) {
        await loadMore();
    }
}

async function onApply() {
    shouldFit = true; // re-frame the map to the new result set
    await applyFilters();
    await loadAllForMap();
}

onMounted(async () => {
    if (!mapEl.value) {
return;
}

    map = L.map(mapEl.value, {
        worldCopyJump: true,
        scrollWheelZoom: true,
        // Snappy mouse-wheel / trackpad zoom: small px-per-level means each
        // scroll/pinch covers more zoom, fired with little debounce.
        zoomSnap: 0,
        zoomDelta: 1,
        wheelPxPerZoomLevel: 30,
        wheelDebounceTime: 5,
    }).setView([30, 0], 2);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 18,
    }).addTo(map);
    markerLayer = L.layerGroup().addTo(map);
    mapEl.value.addEventListener('click', onPopupClick);

    await loadAllForMap();
});

onBeforeUnmount(() => {
    mapEl.value?.removeEventListener('click', onPopupClick);
    map?.remove();
});
</script>

<template>
    <Head title="Event Visuals — Map" />

    <div class="flex h-screen flex-col">
        <header class="flex flex-col gap-3 border-b bg-card/80 p-4 backdrop-blur">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <p class="text-sm font-medium uppercase tracking-wider text-primary">Visual 2</p>
                    <h1 class="text-2xl font-bold tracking-tight">Events Map</h1>
                </div>
                <Button size="lg" class="active:scale-95" @click="showCreate = true">
                    <Plus class="size-4" />
                    Add event
                </Button>
            </div>
            <EventFilterBar v-model:form="form" :cities="cities" :total="total" @apply="onApply" />
        </header>

        <div class="relative flex min-h-0 flex-1">
            <!-- Map -->
            <div ref="mapEl" class="z-0 h-full flex-1" />

            <!-- Side list of currently loaded events -->
            <aside class="hidden w-80 shrink-0 overflow-y-auto border-l bg-card/60 md:block">
                <div class="sticky top-0 border-b bg-card/90 p-3 text-sm font-medium backdrop-blur">
                    {{ events.length }} events on the map
                    <span v-if="loading" class="text-muted-foreground"> · loading…</span>
                </div>
                <ul>
                    <li
                        v-for="event in events"
                        :key="event.id"
                        class="cursor-pointer border-b p-3 transition hover:bg-accent"
                        @click="selected = event"
                    >
                        <div class="flex gap-3">
                            <img
                                v-if="event.images.length"
                                :src="event.images[0].url"
                                :alt="event.title"
                                class="h-14 w-14 shrink-0 rounded-md object-cover"
                            />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold">{{ event.title }}</p>
                                <p class="flex items-center gap-1 truncate text-xs text-muted-foreground">
                                    <MapPin class="size-3 shrink-0 text-primary" />
                                    <span class="truncate">{{ event.location?.label }}</span>
                                </p>
                                <p class="flex items-center gap-1 truncate text-xs text-muted-foreground">
                                    <CalendarDays class="size-3 shrink-0 text-primary" />
                                    {{ formatDate(event.date_time) }}
                                </p>
                            </div>
                        </div>
                    </li>
                </ul>
            </aside>
        </div>
    </div>

    <RegisterDialog :event="selected" @close="selected = null" />
    <CreateEventDialog :open="showCreate" @close="showCreate = false" @created="onApply" />
</template>

<style>
/* divIcon markers render outside component scope, so this is intentionally global. */
.event-pin {
    background: transparent;
    border: none;
}
</style>
