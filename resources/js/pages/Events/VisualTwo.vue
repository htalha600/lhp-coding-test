<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarDays, MapPin, Plus } from '@lucide/vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
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
let cluster: L.MarkerClusterGroup | null = null;
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

async function fetchMarkers() {
    if (!map || !cluster) {
        return;
    }

    loading.value = true;

    try {
        const res = await fetch(`/events/map-data?${buildParams()}`, {
            headers: { Accept: 'application/json' },
        });
        const payload = await res.json();
        const markers: MapMarker[] = payload.markers ?? [];

        cluster.clearLayers();
        markersById = new Map();

        const layers = markers.map((m) => {
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

            return marker;
        });

        cluster.addLayers(layers);
        visible.value = markers;
        inView.value = payload.returned ?? markers.length;
        total.value = payload.total ?? null;
        capped.value = Boolean(payload.capped);
    } finally {
        loading.value = false;
    }
}

function scheduleFetch() {
    if (debounce) {
        clearTimeout(debounce);
    }

    debounce = setTimeout(fetchMarkers, 300);
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

    cluster = L.markerClusterGroup({ chunkedLoading: true, maxClusterRadius: 60 });
    map.addLayer(cluster);

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
                    {{ inView.toLocaleString() }} in view
                    <span v-if="capped" class="text-muted-foreground"> · zoom in to see all</span>
                    <span v-if="loading" class="text-muted-foreground"> · loading…</span>
                </div>
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
</style>
