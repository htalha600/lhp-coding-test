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

    /** @return array{city: string, country: string, label: string}|null */
    public function location(): ?array
    {
        return CityGeocoder::nearest($this->latitude, $this->longitude);
    }

    /**
     * Flat, frontend-friendly representation containing exactly the fields the
     * UI needs: title, description, location, date/time and images.
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
