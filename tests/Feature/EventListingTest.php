<?php

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the events landing page', function () {
    $this->get(route('events.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/Index')
            ->has('cities')
        );
});

it('returns a json page of events with only the spec fields', function () {
    Event::factory()->create([
        'event_time' => 1_700_000_000,
        'latitude' => 51.5074,
        'longitude' => -0.1278,
        'payload' => ['name' => 'Global Tech Summit', 'description' => 'A great event', 'lat' => '51.5074', 'lng' => '-0.1278'],
    ]);

    $this->getJson(route('events.data'))
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'title', 'description', 'date_time', 'latitude', 'longitude', 'location', 'images']],
            'current_page',
            'last_page',
            'total',
            'stats' => ['ms', 'bytes'],
        ])
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.title', 'Global Tech Summit')
        ->assertJsonPath('data.0.location.label', 'London, UK');
});

it('filters the data endpoint by date', function () {
    Event::factory()->create(['event_time' => strtotime('2025-01-01')]);
    Event::factory()->create(['event_time' => strtotime('2025-12-01')]);

    $this->getJson(route('events.data', ['from' => '2025-06-01']))
        ->assertOk()
        ->assertJsonPath('total', 1);
});

it('filters the data endpoint by location', function () {
    // London
    Event::factory()->create(['latitude' => 51.5074, 'longitude' => -0.1278]);
    // Tokyo
    Event::factory()->create(['latitude' => 35.6762, 'longitude' => 139.6503]);

    $this->getJson(route('events.data', ['city' => 'London, UK']))
        ->assertOk()
        ->assertJsonPath('total', 1);
});

it('creates an event from the spec fields', function () {
    $this->post(route('events.store'), [
        'title' => 'My New Event',
        'description' => 'Something fun',
        'date_time' => now()->addDays(5)->toDateTimeString(),
        'latitude' => 51.5074,
        'longitude' => -0.1278,
    ])->assertRedirect();

    $event = Event::firstWhere('payload->name', 'My New Event');
    expect($event)->not->toBeNull();
    expect($event->title())->toBe('My New Event');
    expect($event->images()->count())->toBeGreaterThanOrEqual(1);
});

it('shows an event detail page', function () {
    $event = Event::factory()->create([
        'payload' => ['name' => 'Detail Event', 'description' => 'desc', 'lat' => '1.5', 'lng' => '2.5'],
    ]);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/Show')
            ->where('event.id', $event->id)
            ->where('event.title', 'Detail Event')
        );
});

it('renders the two visualization pages', function () {
    $this->get(route('events.visual1'))->assertOk();
    $this->get(route('events.visual2'))->assertOk();
});
