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
                'lat' => (string) $lat,
                'lng' => (string) $lng,
            ],
        ];
    }
}
