<?php

namespace App\Models;

use App\Support\CityGeocoder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Event extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    public function images(): HasMany
    {
        return $this->hasMany(EventImage::class)->orderBy('position');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }

    // --- payload helpers ----------------------------------------------------

    public function title(): string
    {
        return $this->payload['name'] ?? 'Untitled Event';
    }

    public function description(): string
    {
        return $this->payload['description'] ?? '';
    }

    /** The event date/time, stored as a unix timestamp (UTC). */
    public function dateTime(): ?Carbon
    {
        return $this->event_time ? Carbon::createFromTimestampUTC((int) $this->event_time) : null;
    }

    /** Cached location label, falling back to the offline nearest-city lookup. */
    public function location(): ?array
    {
        if (! empty($this->location_label)) {
            return ['label' => $this->location_label];
        }

        $near = CityGeocoder::nearest($this->latitude, $this->longitude);

        return $near ? ['label' => $near['label']] : null;
    }

    /**
     * Flat representation for the frontend.
     *
     * @return array<string, mixed>
     */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title(),
            'description' => $this->description(),
            'date_time' => $this->dateTime()?->toIso8601String(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'location' => $this->location(),
            'images' => $this->relationLoaded('images')
                ? $this->images->map(fn (EventImage $i) => ['url' => $i->url, 'alt' => $i->alt])->all()
                : [],
        ];
    }
}
