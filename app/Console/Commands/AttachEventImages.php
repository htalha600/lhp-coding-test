<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Attaches local placeholder images to every event (idempotent).
 *
 * Each event gets two local placeholder images served from the "public" disk,
 * satisfying the "two or more images per event" requirement. We reuse a small
 * set of placeholder files rather than generating thousands of unique images.
 */
class AttachEventImages extends Command
{
    protected $signature = 'events:attach-images {--fresh : Wipe existing event images first}';

    protected $description = 'Attach local placeholder images to events';

    /** Local placeholder files reused across all events. */
    private const IMAGES = ['placeholder', 'placeholder-2'];

    public function handle(): int
    {
        if ($this->option('fresh')) {
            EventImage::query()->delete();
            $this->info('Cleared existing event images.');
        }

        $existing = EventImage::query()->distinct()->pluck('event_id')->flip();
        $now = now();
        $attached = 0;

        Event::query()
            ->select('id')
            ->whereNotIn('id', $existing->keys())
            ->chunkById(2000, function ($events) use (&$attached, $now) {
                $rows = [];

                foreach ($events as $event) {
                    foreach (self::IMAGES as $position => $name) {
                        $rows[] = [
                            'event_id' => $event->id,
                            'path' => "events/{$name}.svg",
                            'alt' => 'Event image',
                            'position' => $position,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    $attached++;
                }

                // SQLite caps bound variables (~32k); insert in small flushes.
                foreach (array_chunk($rows, 400) as $batch) {
                    DB::table('event_images')->insert($batch);
                }
            });

        $this->info("Attached images to {$attached} events.");

        return self::SUCCESS;
    }
}
