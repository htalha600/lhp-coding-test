<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $lat = fake()->latitude();
        $lng = fake()->longitude();
        $eventTime = fake()->numberBetween(strtotime('-1 year'), strtotime('+1 year'));
        $name = ucwords(fake()->words(3, true));

        return [
            'event_time' => $eventTime,
            'latitude' => $lat,
            'longitude' => $lng,
            'payload' => [
                'name' => $name,
                'description' => "Join us for {$name} — an event you won't want to miss.",
                'type' => fake()->randomElement(Event::TYPES),
                'status' => fake()->randomElement(Event::STATUSES),
                'organizer' => fake()->company(),
                'venue' => fake()->randomElement(['The Grand', 'Riverside', 'Downtown', 'Skyline']).' '.fake()->randomElement(['Hall', 'Arena', 'Pavilion', 'Theatre']),
                'capacity' => fake()->numberBetween(20, 50000),
                'price' => fake()->randomElement([0, 0, 25, 49.99, 120, 250]),
                'lat' => (string) $lat,
                'lng' => (string) $lng,
            ],
        ];
    }
}
