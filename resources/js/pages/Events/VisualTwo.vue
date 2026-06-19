<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarDays, MapPin, Plus } from '@lucide/vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import CreateEventDialog from '@/components/events/CreateEventDialog.vue';
import EventFilterBar from '@/components/events/EventFilterBar.vue';
import RegisterDialog from '@/components/events/RegisterDialog.vue';
import { Button } from '@/components/ui/button';
import type { EventCard, EventFilters } from '@/composables/useEvents';

interface MapMarker {
    id: string;
    latitude: number;
    longitude: number;
    title: string;
    location: { label: string } | null;
    date_time: string | null;
}

interface MapCluster {
    latitude: number;
    longitude: number;
    count: number;
}

// Self-contained SVG pin so we don't depend on Leaflet's bundled PNG icons.
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

// A cluster bubble whose size + colour scale with the event count. The count is
// formatted compactly (1.2k, 34k…) so even huge buckets stay legible.
function clusterIcon(count: number): L.DivIcon {
    const size = count < 100 ? 40 : count < 1000 ? 52 : count < 10000 ? 64 : 76;
    const bg = count < 100 ? '#6366f1' : count < 1000 ? '#4f46e5' : count < 10000 ? '#4338ca' : '#3730a3';
    const label =
        count < 1000
            ? String(count)
            : count < 1_000_000
              ? `${(count / 1000).toFixed(count < 10000 ? 1 : 0)}k`.replace('.0', '')
              : `${(count / 1_000_000).toFixed(1)}M`.replace('.0', '');

    return L.divIcon({
        className: 'event-cluster',
        html: `<div class="event-cluster__bubble" style="width:${size}px;height:${size}px;background:${bg}">
            <span>${label}</span>
        </div>`,
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
    });
}

const props = defineProps<{
    filters: EventFilters;
    cities: string[];
}>();

const form = reactive<EventFilters>({
    city: props.filters.city ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const selected = ref<EventCard | null>(null);
const showCreate = ref(false);
const loading = ref(false);
const total = ref<number | null>(null); // total matching current filters (whole DB)
const inView = ref(0); // markers in the current viewport
const capped = ref(false);
const visible = ref<MapMarker[]>([]); // backs the reactive side list

const mapEl = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
// A single layer group holds both cluster bubbles and leaf pins; we clear and
// repopulate it on every viewport change so the DOM never grows unbounded.
let layer: L.LayerGroup | null = null;
let markersById = new Map<string, MapMarker>();
let debounce: ReturnType<typeof setTimeout> | null = null;

function formatDate(iso: string | null): string {
    if (!iso) {
        return 'Date TBA';
    }

    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function buildParams(): URLSearchParams {
    const params = new URLSearchParams();

    if (map) {
        const b = map.getBounds();
        params.set('north', String(b.getNorth()));
        params.set('south', String(b.getSouth()));
        params.set('east', String(b.getEast()));
        params.set('west', String(b.getWest()));
        params.set('zoom', String(Math.round(map.getZoom())));
    }

    if (form.city) {
        params.set('city', form.city);
    }

    if (form.from) {
        params.set('from', form.from);
    }

    if (form.to) {
        params.set('to', form.to);
    }

    return params;
}

// One bounded request per viewport change. The server returns aggregated
// clusters (centroid + count) plus individual leaf markers for sparse cells, so
// the number of objects we plot is small no matter how many events exist.
async function fetchMarkers() {
    if (!map || !layer) {
        return;
    }

    loading.value = true;

    try {
        const res = await fetch(`/events/map-clusters?${buildParams()}`, {
            headers: { Accept: 'application/json' },
        });
        const payload = await res.json();
        const clusters: MapCluster[] = payload.clusters ?? [];
        const markers: MapMarker[] = payload.markers ?? [];

        layer.clearLayers();
        markersById = new Map();

        // Cluster bubbles: clicking one zooms in, which makes the server split it
        // into finer clusters (and eventually individual pins).
        for (const c of clusters) {
            const bubble = L.marker([c.latitude, c.longitude], {
                icon: clusterIcon(c.count),
            });
            bubble.on('click', () => {
                if (!map) {
                    return;
                }
                const next = Math.min(map.getZoom() + 2, map.getMaxZoom());
                map.flyTo([c.latitude, c.longitude], next, { duration: 0.4 });
            });
            layer.addLayer(bubble);
        }

        // Leaf pins: real events, clickable to register.
        for (const m of markers) {
            markersById.set(m.id, m);
            const marker = L.marker([m.latitude, m.longitude], { icon: pinIcon });
            marker.bindPopup(
                `<div style="min-width:180px">
                    <strong>${m.title}</strong><br/>
                    <span style="color:#666">${m.location?.label ?? ''}</span><br/>
                    <span style="color:#666">${formatDate(m.date_time)}</span><br/>
                    <button data-event="${m.id}" class="map-register"
                        style="margin-top:6px;padding:4px 10px;border-radius:6px;background:#6366f1;color:#fff;border:none;cursor:pointer">
                        Register
                    </button>
                </div>`,
            );
            layer.addLayer(marker);
        }

        visible.value = markers;
        inView.value = markers.length;
        total.value = payload.total ?? null;
        // "Capped" now means there are still clustered events not shown as pins.
        capped.value = clusters.length > 0;
    } finally {
        loading.value = false;
    }
}

function scheduleFetch() {
    if (debounce) {
        clearTimeout(debounce);
    }

    debounce = setTimeout(fetchMarkers, 250);
}

// The popup Register button opens the dialog. Markers carry only the light map
// payload, so we hand the dialog a minimal EventCard built from it.
function onPopupClick(e: Event) {
    const target = e.target as HTMLElement;

    if (target.classList.contains('map-register')) {
        const id = target.getAttribute('data-event');
        const m = id ? markersById.get(id) : null;

        if (m) {
            selected.value = {
                id: m.id,
                title: m.title,
                description: '',
                date_time: m.date_time,
                latitude: m.latitude,
                longitude: m.longitude,
                location: m.location ? { city: '', country: '', label: m.location.label } : null,
                images: [],
            };
        }
    }
}

function onApply() {
    // Filters changed — refetch the current viewport immediately.
    if (debounce) {
        clearTimeout(debounce);
    }

    fetchMarkers();
}

onMounted(() => {
    if (!mapEl.value) {
        return;
    }

    map = L.map(mapEl.value, {
        worldCopyJump: true,
        scrollWheelZoom: true,
        zoomSnap: 0,
        zoomDelta: 1,
        wheelPxPerZoomLevel: 30,
        wheelDebounceTime: 5,
    }).setView([30, 0], 2);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 18,
    }).addTo(map);

    layer = L.layerGroup();
    map.addLayer(layer);

    mapEl.value.addEventListener('click', onPopupClick);
    // Refetch whenever the user pans or zooms (debounced).
    map.on('moveend', scheduleFetch);

    fetchMarkers();
});

onBeforeUnmount(() => {
    if (debounce) {
        clearTimeout(debounce);
    }

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

            <!-- Side list of markers currently in view -->
            <aside class="hidden w-80 shrink-0 overflow-y-auto border-l bg-card/60 md:block">
                <div class="sticky top-0 border-b bg-card/90 p-3 text-sm font-medium backdrop-blur">
                    <template v-if="total !== null">{{ total.toLocaleString() }} in this area</template>
                    <span v-if="capped" class="text-muted-foreground"> · zoom in to expand clusters</span>
                    <span v-else-if="inView" class="text-muted-foreground"> · {{ inView.toLocaleString() }} shown</span>
                    <span v-if="loading" class="text-muted-foreground"> · loading…</span>
                </div>
                <p v-if="!visible.length && !loading" class="p-4 text-sm text-muted-foreground">
                    {{ capped ? 'Zoom in or tap a cluster to reveal individual events.' : 'No events in this area.' }}
                </p>
                <ul>
                    <li
                        v-for="m in visible"
                        :key="m.id"
                        class="cursor-pointer border-b p-3 transition hover:bg-accent"
                        @click="map?.setView([m.latitude, m.longitude], Math.max(map.getZoom(), 10))"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ m.title }}</p>
                            <p class="flex items-center gap-1 truncate text-xs text-muted-foreground">
                                <MapPin class="size-3 shrink-0 text-primary" />
                                <span class="truncate">{{ m.location?.label }}</span>
                            </p>
                            <p class="flex items-center gap-1 truncate text-xs text-muted-foreground">
                                <CalendarDays class="size-3 shrink-0 text-primary" />
                                {{ formatDate(m.date_time) }}
                            </p>
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

.event-cluster {
    background: transparent;
    border: none;
}

.event-cluster__bubble {
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    color: #fff;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    line-height: 1;
    box-shadow:
        0 0 0 4px rgba(99, 102, 241, 0.25),
        0 2px 8px rgba(0, 0, 0, 0.3);
    cursor: pointer;
    transition:
        transform 0.15s ease,
        box-shadow 0.15s ease;
}

.event-cluster__bubble span {
    font-size: 13px;
}

.event-cluster__bubble:hover {
    transform: scale(1.08);
    box-shadow:
        0 0 0 6px rgba(99, 102, 241, 0.3),
        0 4px 12px rgba(0, 0, 0, 0.35);
}
</style>
