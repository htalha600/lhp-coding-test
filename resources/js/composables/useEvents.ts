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

export interface UseEventsOptions {
    /** Keep at most this many events in memory (sliding window). 0 = unbounded. */
    maxItems?: number;
}

/**
 * Shared client-side data layer for the event pages: holds the filter form and
 * paginates against /events/data. When `maxItems` is set it keeps a BIDIRECTIONAL
 * sliding window — a contiguous range of pages [pageLo..pageHi] is held in memory.
 * Scrolling down loads the next page and trims the oldest; scrolling up loads the
 * previous page and trims the newest. Memory stays bounded and scroll works both
 * ways. `droppedCount` (front trims) and `prependedCount` (front inserts) let the
 * UI compensate scroll position so trimming/prepending is invisible.
 */
export function useEvents(initial: EventFilters = {}, options: UseEventsOptions = {}) {
    const maxItems = options.maxItems ?? 0;

    const form = reactive<EventFilters>({
        city: initial.city ?? '',
        from: initial.from ?? '',
        to: initial.to ?? '',
    });

    const events = ref<EventCard[]>([]);
    const total = ref<number | null>(null); // total matching rows (whole DB)
    const totalPages = ref<number | null>(null); // last page number from the API
    const loading = ref(false);
    const loadedOnce = ref(false);

    // The contiguous page range currently held in memory. 0 = nothing loaded.
    const pageLo = ref(0);
    const pageHi = ref(0);

    // Net events trimmed off / inserted at the FRONT, for scroll compensation.
    const droppedCount = ref(0);
    const prependedCount = ref(0);

    function hasMore() {
        return totalPages.value === null || pageHi.value < totalPages.value;
    }

    function hasPrev() {
        return pageLo.value > 1;
    }

    function buildParams(targetPage: number): URLSearchParams {
        const params = new URLSearchParams({ page: String(targetPage) });

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

    async function fetchPage(targetPage: number): Promise<EventCard[]> {
        const res = await fetch(`/events/data?${buildParams(targetPage)}`, {
            headers: { Accept: 'application/json' },
        });
        const payload = await res.json();

        total.value = payload.total;
        totalPages.value = payload.last_page;
        loadedOnce.value = true;

        return payload.data as EventCard[];
    }

    /** Load the next page (append), trimming the oldest page if over the cap. */
    async function loadMore() {
        if (loading.value || !hasMore()) {
            return;
        }

        loading.value = true;

        try {
            const next = pageHi.value + 1;
            const rows = await fetchPage(next);

            events.value.push(...rows);
            pageHi.value = next;

            if (pageLo.value === 0) {
                pageLo.value = next;
            }

            // Trim the oldest page off the front when over the cap.
            if (maxItems > 0 && events.value.length > maxItems && pageHi.value > pageLo.value) {
                const drop = events.value.length - maxItems;

                if (drop > 0) {
                    events.value.splice(0, drop);
                    droppedCount.value += drop;
                    pageLo.value += 1;
                }
            }
        } finally {
            loading.value = false;
        }
    }

    /** Load the previous page (prepend), trimming the newest page if over the cap. */
    async function loadPrev() {
        if (loading.value || !hasPrev()) {
            return;
        }

        loading.value = true;

        try {
            const prev = pageLo.value - 1;
            const rows = await fetchPage(prev);

            events.value.unshift(...rows);
            prependedCount.value += rows.length;
            pageLo.value = prev;

            // Trim the newest page off the back when over the cap.
            if (maxItems > 0 && events.value.length > maxItems && pageHi.value > pageLo.value) {
                const drop = events.value.length - maxItems;

                if (drop > 0) {
                    events.value.splice(events.value.length - drop, drop);
                    pageHi.value -= 1;
                }
            }
        } finally {
            loading.value = false;
        }
    }

    function reset() {
        events.value = [];
        pageLo.value = 0;
        pageHi.value = 0;
        total.value = null;
        totalPages.value = null;
        loadedOnce.value = false;
        droppedCount.value = 0;
        prependedCount.value = 0;
    }

    async function applyFilters() {
        reset();
        await loadMore();
    }

    return {
        form,
        events,
        total,
        loading,
        loadedOnce,
        droppedCount,
        prependedCount,
        pageLo,
        hasMore,
        hasPrev,
        loadMore,
        loadPrev,
        applyFilters,
        reset,
    };
}
