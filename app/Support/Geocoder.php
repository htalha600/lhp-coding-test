<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Reverse-geocodes coordinates via Nominatim, falling back to the offline lookup. */
class Geocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

    /** Resolve coordinates to a "City, Country" label. */
    public static function resolve(?float $lat, ?float $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return self::fromNominatim($lat, $lng) ?? self::fromOffline($lat, $lng);
    }

    private static function fromNominatim(float $lat, float $lng): ?string
    {
        try {
            $response = Http::timeout(5)
                // Nominatim's usage policy requires a descriptive User-Agent.
                ->withHeaders(['User-Agent' => 'EventVisuals/1.0 (reverse-geocode)'])
                ->get(self::ENDPOINT, [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    'zoom' => 10, // city level
                    'addressdetails' => 1,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $address = $response->json('address') ?? [];

            $city = $address['city']
                ?? $address['town']
                ?? $address['village']
                ?? $address['state']
                ?? $address['county']
                ?? null;
            $country = $address['country'] ?? null;

            if ($city && $country) {
                return "{$city}, {$country}";
            }

            // Fall back to Nominatim's own display name if structured parse failed.
            return $response->json('display_name') ?: null;
        } catch (\Throwable $e) {
            Log::warning('Nominatim reverse-geocode failed: '.$e->getMessage());

            return null;
        }
    }

    private static function fromOffline(float $lat, float $lng): ?string
    {
        return CityGeocoder::nearest($lat, $lng)['label'] ?? null;
    }
}
