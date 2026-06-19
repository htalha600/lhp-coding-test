<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventRequest;
use App\Models\Event;
use App\Support\CityGeocoder;
use App\Support\Geocoder;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Events/Index', [
            'filters' => $this->filterState($request),
            'cities' => CityGeocoder::labelsForEvents(),
        ]);
    }

    /** Card-grid page (Visual 1). */
    public function visualOne(Request $request): Response
    {
        return Inertia::render('Events/VisualOne', [
            'filters' => $this->filterState($request),
            'cities' => CityGeocoder::labelsForEvents(),
        ]);
    }

    /** Map page (Visual 2). */
    public function visualTwo(Request $request): Response
    {
        return Inertia::render('Events/VisualTwo', [
            'filters' => $this->filterState($request),
            'cities' => CityGeocoder::labelsForEvents(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        [$events, $stats] = $this->loadListing($request);

        return response()->json([
            'data' => collect($events->items())->map->toCard()->all(),
            'current_page' => $events->currentPage(),
            'last_page' => $events->lastPage(),
            'total' => $events->total(),
            'stats' => $stats,
        ]);
    }

    /**
     * Lightweight markers within the current map viewport (bounding box),
     * capped so the browser never receives more than it can plot. Reuses the
     * same date/location filters as the listing.
     */
    public function mapData(Request $request): JsonResponse
    {
        $cap = 2000;

        $query = Event::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        $this->applyFilters($query, $request);
        $this->applyViewportBounds($query, $request);

        $total = (clone $query)->count();

        $markers = $query
            ->limit($cap)
            ->get(['id', 'latitude', 'longitude', 'location_label', 'event_time', 'payload'])
            ->map(fn (Event $e) => [
                'id' => $e->id,
                'latitude' => $e->latitude,
                'longitude' => $e->longitude,
                'title' => $e->title(),
                'type' => $e->type(),
                'price' => $e->price(),
                'location' => $e->location(),
                'date_time' => $e->dateTime()?->toIso8601String(),
            ]);

        return response()->json([
            'markers' => $markers,
            'returned' => $markers->count(),
            'total' => $total,
            'capped' => $total > $cap,
        ]);
    }

    /**
     * Server-side clustering for the map. Instead of shipping raw points (which
     * crashes the browser at scale), we snap every matching event to a grid cell
     * sized to the current zoom and aggregate per cell in SQL. The browser then
     * plots at most a few hundred objects — bounded regardless of table size.
     *
     * As the user zooms in, the grid gets finer, so big clusters split into
     * smaller clusters. Once a cell holds few enough events (<= $expandAt), we
     * return those events individually so leaf pins appear at high zoom.
     */
    public function mapClusters(Request $request): JsonResponse
    {
        // The aggregate scan is identical for every user viewing the same area
        // with the same filters, and only changes when events are added/removed.
        // Cache it briefly so coarse, full-table low-zoom views (the expensive
        // ones) are computed once and served to everyone for the next minute.
        // Version prefix lets us invalidate every cached viewport at once (e.g.
        // when an event is created) without enumerating keys — works on any driver.
        $version = Cache::get('map-clusters:version', 1);
        $cacheKey = "map-clusters:{$version}:".md5($request->getQueryString() ?? '');

        $payload = Cache::remember($cacheKey, now()->addSeconds(60), function () use ($request) {
            return $this->computeMapClusters($request);
        });

        return response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeMapClusters(Request $request): array
    {
        // Per-cell count at/below which a cell is *eligible* to become individual
        // pins. Kept small so single bubbles never hide large groups.
        $expandAt = 8;

        // Hard ceiling on individual pins we'll ever return. Beyond this the
        // browser starts to chug, so we keep the rest as clusters no matter what.
        $maxMarkers = 300;

        $zoom = max(0, min(18, (int) $request->input('zoom', 2)));

        // Size each grid cell to a fixed *screen* size (~one bubble + gap) rather
        // than a fixed number of degrees. At Web-Mercator zoom Z the world spans
        // 256 * 2^Z px for 360°, so degrees-per-pixel = 360 / (256 * 2^Z). Cells
        // this big keep cluster bubbles ~$cellPx apart at every zoom, so they
        // spread out and stop overlapping instead of piling up at low zoom.
        $cellPx = 90;
        $cell = $cellPx * 360.0 / (256.0 * pow(2, $zoom));
        $cell = max(0.0008, min(120.0, $cell));

        $query = Event::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        $this->applyFilters($query, $request);
        $this->applyViewportBounds($query, $request);

        // SQLite lacks FLOOR, and CAST(x AS INTEGER) truncates toward zero (wrong
        // for negative coords). This expression floors portably across drivers.
        $gx = $this->floorDiv('latitude', $cell);
        $gy = $this->floorDiv('longitude', $cell);

        // Cap on cells returned so total plotted objects stay browser-friendly.
        $cellLimit = 200;

        // Aggregate into grid cells entirely in SQL. We bucket by floor(coord/cell)
        // and average the real coordinates so each cluster sits on its centroid.
        // One pass over the index gives us both the buckets and (via their summed
        // counts) the grand total — so we avoid a second full COUNT(*) scan.
        $buckets = (clone $query)
            ->selectRaw("$gx AS gx")
            ->selectRaw("$gy AS gy")
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('AVG(latitude) AS clat')
            ->selectRaw('AVG(longitude) AS clng')
            ->groupBy('gx', 'gy')
            ->orderByDesc('cnt')
            // Fetch one extra cell so we can tell whether the grid was truncated.
            ->limit($cellLimit + 1)
            ->get();

        $truncated = $buckets->count() > $cellLimit;
        $buckets = $buckets->take($cellLimit);

        // If every cell fit, the summed bucket counts ARE the exact total — no
        // extra query needed. Only when truncated do we pay for a real COUNT(*).
        $total = $truncated
            ? (clone $query)->count()
            : (int) $buckets->sum('cnt');

        $clusters = [];
        $leafCellKeys = [];
        $markerBudget = $maxMarkers;

        foreach ($buckets as $b) {
            $cnt = (int) $b->cnt;

            // Expand a cell to individual pins only if it's small AND there's room
            // left in the global marker budget. Otherwise it stays a cluster, so
            // the browser is never handed more than $maxMarkers pins.
            if ($cnt <= $expandAt && $cnt <= $markerBudget) {
                $leafCellKeys[] = [(int) $b->gx, (int) $b->gy];
                $markerBudget -= $cnt;

                continue;
            }

            $clusters[] = [
                'type' => 'cluster',
                'latitude' => (float) $b->clat,
                'longitude' => (float) $b->clng,
                'count' => $cnt,
            ];
        }

        // Fetch the actual events for the small cells in one query, so they render
        // as individual, clickable pins.
        $markers = [];

        if (! empty($leafCellKeys)) {
            // Bound the leaf fetch by the lat/lng box enclosing the chosen cells.
            // Range predicates on latitude/longitude hit the composite index,
            // unlike a function-on-column (floor) filter which forces a scan.
            $gxs = array_column($leafCellKeys, 0);
            $gys = array_column($leafCellKeys, 1);
            $minLat = min($gxs) * $cell;
            $maxLat = (max($gxs) + 1) * $cell;
            $minLng = min($gys) * $cell;
            $maxLng = (max($gys) + 1) * $cell;

            $markers = (clone $query)
                ->whereBetween('latitude', [$minLat, $maxLat])
                ->whereBetween('longitude', [$minLng, $maxLng])
                ->limit($maxMarkers)
                ->get(['id', 'latitude', 'longitude', 'location_label', 'event_time', 'payload'])
                ->map(fn (Event $e) => [
                    'type' => 'marker',
                    'id' => $e->id,
                    'latitude' => $e->latitude,
                    'longitude' => $e->longitude,
                    'title' => $e->title(),
                    'location' => $e->location(),
                    'date_time' => $e->dateTime()?->toIso8601String(),
                ])
                ->all();
        }

        return [
            'clusters' => $clusters,
            'markers' => $markers,
            'total' => $total,
            'returned' => count($markers),
        ];
    }

    public function store(StoreEventRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $dateTime = Carbon::parse($data['date_time']);

        $event = Event::create([
            'event_time' => $dateTime->timestamp,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'location_label' => Geocoder::resolve((float) $data['latitude'], (float) $data['longitude']),
            'payload' => array_filter([
                'name' => $data['title'],
                'description' => $data['description'] ?? '',
                'type' => $data['type'] ?? null,
                'status' => $data['status'] ?? 'published',
                'organizer' => $data['organizer'] ?? null,
                'venue' => $data['venue'] ?? null,
                'capacity' => isset($data['capacity']) ? (int) $data['capacity'] : null,
                'price' => isset($data['price']) ? (float) $data['price'] : null,
                'lat' => (string) $data['latitude'],
                'lng' => (string) $data['longitude'],
            ], fn ($v) => $v !== null),
        ]);

        $this->storeImages($event, $request);

        // Invalidate cached map clusters so the new event shows up immediately.
        Cache::forever('map-clusters:version', Cache::get('map-clusters:version', 1) + 1);

        return back()->with('success', 'Event created.');
    }

    public function show(Event $event): Response
    {
        $event->load(['images']);

        return Inertia::render('Events/Show', [
            'event' => array_merge($event->toCard(), [
                'attendees_count' => $event->attendees()->count(),
            ]),
        ]);
    }

    /** Persist uploaded images locally, or attach a placeholder if none. */
    private function storeImages(Event $event, Request $request): void
    {
        $files = $request->file('images', []);

        if (empty($files)) {
            $event->images()->create([
                'path' => 'events/placeholder.svg',
                'alt' => $event->title(),
                'position' => 0,
            ]);

            return;
        }

        foreach ($files as $position => $file) {
            $path = $file->store('events/uploads', 'public');
            $event->images()->create([
                'path' => $path,
                'alt' => $event->title(),
                'position' => $position,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function filterState(Request $request): array
    {
        return [
            'city' => $request->input('city'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ];
    }

    /**
     * A portable floor(column / cell) SQL expression. CAST(x AS INTEGER) truncates
     * toward zero on SQLite/MySQL, so we subtract 1 for negative non-integers to
     * get true floor — keeping grid cells correct across the equator/meridian.
     * $cell is a clamped float derived from an int, so inlining it is injection-safe.
     */
    private function floorDiv(string $column, float $cell): string
    {
        $div = "($column / $cell)";

        return "(CAST($div AS INTEGER) - (CASE WHEN $div < 0 AND $div <> CAST($div AS INTEGER) THEN 1 ELSE 0 END))";
    }

    /** Restrict a query to the visible map bounds when north/south/east/west are present. */
    private function applyViewportBounds(Builder $query, Request $request): void
    {
        if (! $request->filled(['north', 'south', 'east', 'west'])) {
            return;
        }

        $north = (float) $request->input('north');
        $south = (float) $request->input('south');
        $east = (float) $request->input('east');
        $west = (float) $request->input('west');

        $query->whereBetween('latitude', [min($south, $north), max($south, $north)]);

        // Handle a viewport that crosses the antimeridian (west > east).
        if ($west <= $east) {
            $query->whereBetween('longitude', [$west, $east]);
        } else {
            $query->where(fn ($q) => $q->where('longitude', '>=', $west)->orWhere('longitude', '<=', $east));
        }
    }

    /** Apply the shared date + location filters to an event query. */
    private function applyFilters(Builder $query, Request $request): void
    {
        // Filter by date against the indexed event_time column.
        if ($from = $request->input('from')) {
            $query->where('event_time', '>=', Carbon::parse($from)->startOfDay()->timestamp);
        }
        if ($to = $request->input('to')) {
            $query->where('event_time', '<=', Carbon::parse($to)->endOfDay()->timestamp);
        }

        if ($city = $request->input('city')) {
            $box = CityGeocoder::boundingBox($city);
            $query->where(function ($q) use ($city, $box) {
                $q->where('location_label', $city);
                if ($box) {
                    $q->orWhere(function ($inner) use ($box) {
                        $inner->whereNull('location_label')
                            ->whereBetween('latitude', [$box['minLat'], $box['maxLat']])
                            ->whereBetween('longitude', [$box['minLng'], $box['maxLng']]);
                    });
                }
            });
        }
    }

    /**
     * @return array{0: LengthAwarePaginator, 1: array{ms: int, bytes: int}}
     */
    private function loadListing(Request $request): array
    {
        $start = microtime(true);

        $query = Event::with('images');
        $this->applyFilters($query, $request);

        // Newest-added events first.
        $events = $query->latest('created_at')->latest('id')->paginate(24)->withQueryString();

        $stats = [
            'ms' => (int) round((microtime(true) - $start) * 1000),
            'bytes' => strlen((string) json_encode($events->items())),
        ];

        return [$events, $stats];
    }
}
