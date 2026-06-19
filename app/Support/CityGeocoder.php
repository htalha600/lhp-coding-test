<?php

namespace App\Support;

use App\Models\Event;

/** Offline reverse-geocoder: maps coordinates to the nearest known city. */
class CityGeocoder
{
    /**
     * @var array<int, array{0: float, 1: float, 2: string, 3: string}>
     *                                                                  [latitude, longitude, city, country]
     */
    private const CITIES = [
        // United States
        [40.7128, -74.0060, 'New York', 'USA'], [34.0522, -118.2437, 'Los Angeles', 'USA'],
        [41.8781, -87.6298, 'Chicago', 'USA'], [29.7604, -95.3698, 'Houston', 'USA'],
        [33.4484, -112.0740, 'Phoenix', 'USA'], [39.9526, -75.1652, 'Philadelphia', 'USA'],
        [29.4241, -98.4936, 'San Antonio', 'USA'], [32.7157, -117.1611, 'San Diego', 'USA'],
        [32.7767, -96.7970, 'Dallas', 'USA'], [37.3382, -121.8863, 'San Jose', 'USA'],
        [30.2672, -97.7431, 'Austin', 'USA'], [37.7749, -122.4194, 'San Francisco', 'USA'],
        [47.6062, -122.3321, 'Seattle', 'USA'], [39.7392, -104.9903, 'Denver', 'USA'],
        [42.3601, -71.0589, 'Boston', 'USA'], [36.1699, -115.1398, 'Las Vegas', 'USA'],
        [25.7617, -80.1918, 'Miami', 'USA'], [33.7490, -84.3880, 'Atlanta', 'USA'],
        [38.9072, -77.0369, 'Washington, D.C.', 'USA'], [36.1627, -86.7816, 'Nashville', 'USA'],
        [45.5152, -122.6784, 'Portland', 'USA'], [29.9511, -90.0715, 'New Orleans', 'USA'],
        // Canada
        [43.6532, -79.3832, 'Toronto', 'Canada'], [45.5019, -73.5674, 'Montreal', 'Canada'],
        [49.2827, -123.1207, 'Vancouver', 'Canada'], [51.0447, -114.0719, 'Calgary', 'Canada'],
        [45.4215, -75.6972, 'Ottawa', 'Canada'], [53.5461, -113.4938, 'Edmonton', 'Canada'],
        [46.8139, -71.2080, 'Quebec City', 'Canada'], [49.8951, -97.1384, 'Winnipeg', 'Canada'],
        // Mexico
        [19.4326, -99.1332, 'Mexico City', 'Mexico'], [20.6597, -103.3496, 'Guadalajara', 'Mexico'],
        [25.6866, -100.3161, 'Monterrey', 'Mexico'], [19.0414, -98.2063, 'Puebla', 'Mexico'],
        [32.5149, -117.0382, 'Tijuana', 'Mexico'], [21.1619, -86.8515, 'Cancún', 'Mexico'],
        [20.9674, -89.5926, 'Mérida', 'Mexico'],
        // Europe
        [51.5074, -0.1278, 'London', 'UK'], [48.8566, 2.3522, 'Paris', 'France'],
        [52.5200, 13.4050, 'Berlin', 'Germany'], [40.4168, -3.7038, 'Madrid', 'Spain'],
        [41.9028, 12.4964, 'Rome', 'Italy'], [52.3676, 4.9041, 'Amsterdam', 'Netherlands'],
        [41.3851, 2.1734, 'Barcelona', 'Spain'], [48.1351, 11.5820, 'Munich', 'Germany'],
        [45.4642, 9.1900, 'Milan', 'Italy'], [48.2082, 16.3738, 'Vienna', 'Austria'],
        [50.0755, 14.4378, 'Prague', 'Czechia'], [38.7223, -9.1393, 'Lisbon', 'Portugal'],
        [53.3498, -6.2603, 'Dublin', 'Ireland'], [55.6761, 12.5683, 'Copenhagen', 'Denmark'],
        [59.3293, 18.0686, 'Stockholm', 'Sweden'], [59.9139, 10.7522, 'Oslo', 'Norway'],
        [60.1699, 24.9384, 'Helsinki', 'Finland'], [50.8503, 4.3517, 'Brussels', 'Belgium'],
        [47.3769, 8.5417, 'Zurich', 'Switzerland'], [52.2297, 21.0122, 'Warsaw', 'Poland'],
        [47.4979, 19.0402, 'Budapest', 'Hungary'], [37.9838, 23.7275, 'Athens', 'Greece'],
        [45.7640, 4.8357, 'Lyon', 'France'], [53.5511, 9.9937, 'Hamburg', 'Germany'],
        [53.4808, -2.2426, 'Manchester', 'UK'], [55.9533, -3.1883, 'Edinburgh', 'UK'],
        [50.1109, 8.6821, 'Frankfurt', 'Germany'], [50.0647, 19.9450, 'Kraków', 'Poland'],
        [41.1579, -8.6291, 'Porto', 'Portugal'], [40.8518, 14.2681, 'Naples', 'Italy'],
        // Global hubs
        [35.6762, 139.6503, 'Tokyo', 'Japan'], [37.5665, 126.9780, 'Seoul', 'South Korea'],
        [1.3521, 103.8198, 'Singapore', 'Singapore'], [-33.8688, 151.2093, 'Sydney', 'Australia'],
        [-37.8136, 144.9631, 'Melbourne', 'Australia'], [25.2048, 55.2708, 'Dubai', 'UAE'],
        [-23.5505, -46.6333, 'São Paulo', 'Brazil'], [-34.6037, -58.3816, 'Buenos Aires', 'Argentina'],
        // South Asia
        [33.6844, 73.0479, 'Islamabad', 'Pakistan'], [24.8607, 67.0011, 'Karachi', 'Pakistan'],
        [31.5204, 74.3587, 'Lahore', 'Pakistan'], [28.6139, 77.2090, 'New Delhi', 'India'],
        [19.0760, 72.8777, 'Mumbai', 'India'], [12.9716, 77.5946, 'Bengaluru', 'India'],
        [23.8103, 90.4125, 'Dhaka', 'Bangladesh'], [27.7172, 85.3240, 'Kathmandu', 'Nepal'],
        [6.9271, 79.8612, 'Colombo', 'Sri Lanka'],
        // Middle East & Africa
        [24.7136, 46.6753, 'Riyadh', 'Saudi Arabia'], [25.2854, 51.5310, 'Doha', 'Qatar'],
        [29.3759, 47.9774, 'Kuwait City', 'Kuwait'], [35.6892, 51.3890, 'Tehran', 'Iran'],
        [41.0082, 28.9784, 'Istanbul', 'Turkey'], [30.0444, 31.2357, 'Cairo', 'Egypt'],
        [-26.2041, 28.0473, 'Johannesburg', 'South Africa'], [6.5244, 3.3792, 'Lagos', 'Nigeria'],
        [-1.2921, 36.8219, 'Nairobi', 'Kenya'],
        // East & SE Asia
        [31.2304, 121.4737, 'Shanghai', 'China'], [39.9042, 116.4074, 'Beijing', 'China'],
        [22.3193, 114.1694, 'Hong Kong', 'China'], [13.7563, 100.5018, 'Bangkok', 'Thailand'],
        [3.1390, 101.6869, 'Kuala Lumpur', 'Malaysia'], [-6.2088, 106.8456, 'Jakarta', 'Indonesia'],
        [14.5995, 120.9842, 'Manila', 'Philippines'], [21.0278, 105.8342, 'Hanoi', 'Vietnam'],
        // Oceania
        [-36.8485, 174.7633, 'Auckland', 'New Zealand'],
    ];

    /** @return array{city: string, country: string, label: string}|null */
    public static function nearest(?float $lat, ?float $lng): ?array
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        $best = null;
        $bestDist = INF;

        foreach (self::CITIES as [$cLat, $cLng, $city, $country]) {
            // Squared euclidean distance is enough for "nearest" ranking.
            $d = ($lat - $cLat) ** 2 + ($lng - $cLng) ** 2;
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = ['city' => $city, 'country' => $country, 'label' => "{$city}, {$country}"];
            }
        }

        return $best;
    }

    /** @return list<string> */
    public static function labels(): array
    {
        $labels = array_map(fn ($c) => "{$c[2]}, {$c[3]}", self::CITIES);
        $labels = array_values(array_unique($labels));
        sort($labels);

        return $labels;
    }

    /**
     * Distinct location labels present across all events (for filter dropdowns).
     *
     * @return list<string>
     */
    public static function labelsForEvents(): array
    {
        $rows = Event::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->distinct()
            ->get(['latitude', 'longitude', 'location_label']);

        $found = [];
        foreach ($rows as $row) {
            // Prefer the cached, reverse-geocoded label; fall back to offline.
            $label = $row->location_label ?: (self::nearest((float) $row->latitude, (float) $row->longitude)['label'] ?? null);
            if ($label) {
                $found[$label] = true;
            }
        }

        $result = array_keys($found);
        sort($result);

        return $result;
    }

    /**
     * Coordinate bounding box around a known city label (null if unknown).
     *
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}|null
     */
    public static function boundingBox(string $label, float $pad = 0.6): ?array
    {
        foreach (self::CITIES as [$lat, $lng, $city, $country]) {
            if ("{$city}, {$country}" === $label) {
                return [
                    'minLat' => $lat - $pad,
                    'maxLat' => $lat + $pad,
                    'minLng' => $lng - $pad,
                    'maxLng' => $lng + $pad,
                ];
            }
        }

        return null;
    }
}
