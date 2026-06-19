import { reactive, ref } from 'vue';

export interface EventImage {
    url: string;
    alt: string | null;
}

export interface EventLocation {
    city: string;
    country: string;
    label: string;
}

export interface EventCard {
    id: string;
    title: string;
    description: string;
    type: string | null;
    status: string | null;
    organizer: string | null;
    venue: string | null;
    capacity: number | null;
    price: number | null;
    date_time: string | null;
    latitude: number | null;
    longitude: number | null;
    location: EventLocation | null;
    images: EventImage[];
}

export interface EventFilters {
    city?: string | null;
    from?: string | null;
    to?: string | null;
}

/**
 * Shared client-side data layer for the event pages: holds the filter form,
 * paginates against /events/data, and exposes loading state. Both Visual 1
 * and Visual 2 build their own UI on top of this.
 */
export function useEvents(initial: EventFilters = {}) {
    const form = reactive<EventFilters>({
        city: initial.city ?? '',
        from: initial.from ?? '',
        to: initial.to ?? '',
    });

    const events = ref<EventCard[]>([]);
    const page = ref(0);
    const lastPage = ref<number | null>(null);
    const total = ref<number | null>(null);
    const loading = ref(false);
    const loadedOnce = ref(false);

    function hasMore() {
        return lastPage.value === null || page.value < lastPage.value;
    }

    function buildParams(nextPage: number): URLSearchParams {
        const params = new URLSearchParams({ page: String(nextPage) });

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

    async function loadMore() {
        if (loading.value || !hasMore()) {
return;
}

        loading.value = true;

        try {
            const res = await fetch(`/events/data?${buildParams(page.value + 1)}`, {
                headers: { Accept: 'application/json' },
            });
            const payload = await res.json();
            const rows: EventCard[] = payload.data;

            events.value.push(...rows);
            page.value = payload.current_page;
            lastPage.value = payload.last_page;
            total.value = payload.total;
            loadedOnce.value = true;
        } finally {
            loading.value = false;
        }
    }

    function reset() {
        events.value = [];
        page.value = 0;
        lastPage.value = null;
        total.value = null;
        loadedOnce.value = false;
    }

    async function applyFilters() {
        reset();
        await loadMore();
    }

    return { form, events, page, lastPage, total, loading, loadedOnce, hasMore, loadMore, applyFilters, reset };
}
