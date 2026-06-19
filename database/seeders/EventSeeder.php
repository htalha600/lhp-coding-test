<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EventSeeder extends Seeder
{
    private const CHUNK = 2500;

    /** Local placeholder images attached to every seeded event. */
    private const PLACEHOLDER_IMAGES = ['placeholder', 'placeholder-2'];

    private const NAME_ADJECTIVES = ['Annual', 'Global', 'Summer', 'Winter', 'Underground', 'Open', 'International', 'Live', 'Midnight', 'Sunset', 'Urban', 'Indie', 'Grand', 'Pop-up', 'Virtual'];

    private const NAME_THEMES = ['Synthwave', 'Founders', 'Jazz', 'Tech', 'Food & Wine', 'Yoga', 'Startup', 'Design', 'Climate', 'Gaming', 'Film', 'Book', 'Marathon', 'Comedy', 'Art'];

    private const NAME_FORMATS = ['Festival', 'Meetup', 'Conference', 'Summit', 'Workshop', 'Expo', 'Showcase', 'Gala', 'Jam', 'Retreat', 'Fair', 'Night', 'Tour', 'Symposium', 'Block Party'];

    /**
     * Anchor coordinates [lat, lng] for major cities across the US, Canada,
     * Mexico and Europe, plus a few global hubs. Each row is jittered around
     * one of these anchors so events cluster around real cities.
     */
    private const CITY_ANCHORS = [
        // United States
        [40.7128, -74.0060], [34.0522, -118.2437], [41.8781, -87.6298], [29.7604, -95.3698],
        [33.4484, -112.0740], [39.9526, -75.1652], [29.4241, -98.4936], [32.7157, -117.1611],
        [32.7767, -96.7970], [37.3382, -121.8863], [30.2672, -97.7431], [37.7749, -122.4194],
        [47.6062, -122.3321], [39.7392, -104.9903], [42.3601, -71.0589], [36.1699, -115.1398],
        [25.7617, -80.1918], [33.7490, -84.3880], [38.9072, -77.0369], [36.1627, -86.7816],
        [45.5152, -122.6784], [29.9511, -90.0715],
        // Canada
        [43.6532, -79.3832], [45.5019, -73.5674], [49.2827, -123.1207], [51.0447, -114.0719],
        [45.4215, -75.6972], [53.5461, -113.4938], [46.8139, -71.2080], [49.8951, -97.1384],
        // Mexico
        [19.4326, -99.1332], [20.6597, -103.3496], [25.6866, -100.3161], [19.0414, -98.2063],
        [32.5149, -117.0382], [21.1619, -86.8515], [20.9674, -89.5926],
        // Europe
        [51.5074, -0.1278], [48.8566, 2.3522], [52.5200, 13.4050], [40.4168, -3.7038],
        [41.9028, 12.4964], [52.3676, 4.9041], [41.3851, 2.1734], [48.1351, 11.5820],
        [45.4642, 9.1900], [48.2082, 16.3738], [50.0755, 14.4378], [38.7223, -9.1393],
        [53.3498, -6.2603], [55.6761, 12.5683], [59.3293, 18.0686], [59.9139, 10.7522],
        [60.1699, 24.9384], [50.8503, 4.3517], [47.3769, 8.5417], [52.2297, 21.0122],
        [47.4979, 19.0402], [37.9838, 23.7275], [45.7640, 4.8357], [53.5511, 9.9937],
        [53.4808, -2.2426], [55.9533, -3.1883], [50.1109, 8.6821], [50.0647, 19.9450],
        [41.1579, -8.6291], [40.8518, 14.2681],
        // A few global hubs
        [35.6762, 139.6503], [37.5665, 126.9780], [1.3521, 103.8198], [-33.8688, 151.2093],
        [-37.8136, 144.9631], [25.2048, 55.2708], [-23.5505, -46.6333], [-34.6037, -58.3816],
    ];

    public function run(): void
    {
        $rows = (int) (env('SEED_ROWS', 200));

        $this->command?->info("Seeding {$rows} events...");

        $this->withSeedingPragmas(function () use ($rows) {
            $this->insertEvents($rows);
        });

        $this->command?->info("Done. {$rows} events.");
    }

    /**
     * Bulk-insert $count event rows. Each event carries only the fields the
     * app needs: a title, a description, coordinates and a date/time.
     */
    public function insertEvents(int $count): void
    {
        DB::connection()->disableQueryLog();

        $now = date('Y-m-d H:i:s');

        $year = 365 * 24 * 60 * 60;
        $nowTs = time();
        // Event times span roughly one year in the past to one year out.
        $minTime = $nowTs - $year;
        $maxTime = $nowTs + $year;

        $anchorCount = count(self::CITY_ANCHORS);

        $remaining = $count;
        $done = 0;

        while ($remaining > 0) {
            $batchSize = min(self::CHUNK, $remaining);
            $batch = [];
            $imageBatch = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $eventTime = mt_rand($minTime, $maxTime);

                $anchor = self::CITY_ANCHORS[mt_rand(0, $anchorCount - 1)];
                $latitude = round($anchor[0] + (mt_rand(-500, 500) / 1000), 7);
                $longitude = round($anchor[1] + (mt_rand(-500, 500) / 1000), 7);

                $name = self::NAME_ADJECTIVES[array_rand(self::NAME_ADJECTIVES)]
                    .' '.self::NAME_THEMES[array_rand(self::NAME_THEMES)]
                    .' '.self::NAME_FORMATS[array_rand(self::NAME_FORMATS)];

                $id = $this->uuidv4();

                $batch[] = [
                    'id' => $id,
                    'event_time' => $eventTime,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'payload' => json_encode([
                        'name' => $name,
                        'description' => "Join us for {$name} — an event you won't want to miss.",
                        'lat' => (string) $latitude,
                        'lng' => (string) $longitude,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Every seeded event gets two local placeholder images.
                foreach (self::PLACEHOLDER_IMAGES as $position => $file) {
                    $imageBatch[] = [
                        'event_id' => $id,
                        'path' => "events/{$file}.svg",
                        'alt' => 'Event image',
                        'position' => $position,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::transaction(function () use ($batch, $imageBatch) {
                DB::table('events')->insert($batch);
                // SQLite caps bound variables (~32k); flush images in chunks.
                foreach (array_chunk($imageBatch, 400) as $chunk) {
                    DB::table('event_images')->insert($chunk);
                }
            });

            $done += $batchSize;
            $remaining -= $batchSize;

            if ($done % (self::CHUNK * 25) === 0 || $remaining === 0) {
                $this->command?->getOutput()?->writeln("  inserted {$done}/{$count}");
            }
        }
    }

    private function uuidv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function withSeedingPragmas(callable $callback): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            $callback();

            return;
        }

        DB::statement('PRAGMA journal_mode = MEMORY');
        DB::statement('PRAGMA synchronous = OFF');
        DB::statement('PRAGMA temp_store = MEMORY');
        DB::statement('PRAGMA cache_size = -64000');

        try {
            $callback();
        } finally {
            DB::statement('PRAGMA journal_mode = WAL');
            DB::statement('PRAGMA synchronous = NORMAL');
        }
    }
}
