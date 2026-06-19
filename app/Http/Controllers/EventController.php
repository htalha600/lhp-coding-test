<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventRequest;
use App\Models\Event;
use App\Support\CityGeocoder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function store(StoreEventRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $dateTime = Carbon::parse($data['date_time']);

        $event = Event::create([
            'event_time' => $dateTime->timestamp,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'payload' => [
                'name' => $data['title'],
                'description' => $data['description'] ?? '',
                'lat' => (string) $data['latitude'],
                'lng' => (string) $data['longitude'],
            ],
        ]);

        $this->storeImages($event, $request);

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
     * @return array{0: LengthAwarePaginator, 1: array{ms: int, bytes: int}}
     */
    private function loadListing(Request $request): array
    {
        $start = microtime(true);

        $query = Event::with('images');

        // Filter by date against the indexed event_time column.
        if ($from = $request->input('from')) {
            $query->where('event_time', '>=', Carbon::parse($from)->startOfDay()->timestamp);
        }
        if ($to = $request->input('to')) {
            $query->where('event_time', '<=', Carbon::parse($to)->endOfDay()->timestamp);
        }

        // Filter by location via a coordinate bounding box for the chosen city.
        if ($city = $request->input('city')) {
            if ($box = CityGeocoder::boundingBox($city)) {
                $query->whereBetween('latitude', [$box['minLat'], $box['maxLat']])
                    ->whereBetween('longitude', [$box['minLng'], $box['maxLng']]);
            }
        }

        // Newest-added events first.
        $events = $query->latest('created_at')->latest('id')->paginate(24)->withQueryString();

        $stats = [
            'ms' => (int) round((microtime(true) - $start) * 1000),
            'bytes' => strlen((string) json_encode($events->items())),
        ];

        return [$events, $stats];
    }
}
