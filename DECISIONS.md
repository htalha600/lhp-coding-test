# Implementation Notes — Event Visuals

A short walkthrough of what I built and the decisions behind it.

## Two layouts

- **Visual 1 — Card grid** (`/events-visual-1`): an animated, infinite-scrolling
  responsive grid. Cards fade in on load, lift on hover, and cycle through their
  images on hover. Filters live in a sticky bar.
- **Visual 2 — Map** (`/events-visual-2`): an interactive Leaflet/OpenStreetMap
  view with a marker per event, click-through popups (with a Register button),
  and a synced side list. Deliberately a completely different browsing model from
  the grid.

Both share one data layer (`useEvents` composable) and one `EventFilterBar`, so
behaviour stays consistent while the presentation differs.

## Data shape

The model is kept to exactly what the spec lists for an event: **title,
description, location, date/time, and images** — nothing else (no type, status,
price, venue, organizer, etc.). The `events` table carries `event_time` (unix
timestamp), `latitude`, `longitude` and a small `payload` JSON holding the title
and description; the migration, seeder and factory were trimmed to match. The
`Event` model exposes typed accessors (`title()`, `description()`, `dateTime()`,
`location()`) and a `toCard()` method returning one flat object with only those
fields, so the frontend never sees raw payload.

## Addresses (lat/long → readable location)

The seeder generates every event by jittering one of ~75 fixed city anchors. So
instead of calling a live reverse-geocoding API (network, rate limits, slow over a
large dataset), `App\Support\CityGeocoder` does an **offline nearest-city lookup** —
instant, deterministic, and a natural fit for how the data was produced.

That same anchor knowledge powers **location filtering**: a selected city maps back
to a coordinate **bounding box**, so filtering happens in SQL on the indexed
`latitude`/`longitude` columns and stays fast even at 1.25M rows — no per-row PHP
scan.

## Date & time

Event times are stored as UNIX timestamps and are global, so they're treated as
**UTC on the server** (`Carbon::createFromTimestampUTC`) and sent as ISO-8601.
The browser renders them in the **viewer's local timezone** via
`toLocaleString`, which also surfaces the zone label. Date filtering uses the
indexed `created_time` column (equal to `starts_at`) rather than the JSON payload.

## Images

Events had none, so I added an `event_images` table (2+ per event) and an
idempotent `events:attach-images` command. Images are **served locally** from the
`public` disk via `storage:link` — no external/hotlinked URLs. I reuse a small set
of generated SVG placeholders (a per-category cover plus generic stage/crowd/venue
shots) rather than storing thousands of unique files; each event gets 4 images.

## Attendees & emails

- `event_attendees` table; registering is **idempotent** (`updateOrCreate` keyed on
  event + email) so re-submitting just updates status.
- A queued `AttendeeConfirmation` mailable is sent the first time someone joins.
- `events:send-reminders` (scheduled hourly, `withoutOverlapping`) queues **3-day**
  and **24-hour** reminders. Each window is sent at most once per attendee, tracked
  by `reminded_3d_at` / `reminded_24h_at`, so the command is safe to run repeatedly.
- Mail uses the `log` driver, so sent mail is visible in `storage/logs/laravel.log`.
  Because mail is queued, the queue worker must be running (`php artisan queue:work`).

## Trade-offs / notes

- **SQLite bound-variable limit:** large bulk inserts exceed SQLite's variable cap,
  so the seeder chunk was lowered and image inserts are flushed in small batches.
- The map loads a few pages of pins rather than all 1.25M markers — a clustering
  layer would be the next step for the full dataset.
- Text search is applied client-side on top of server-side filters to keep the
  hot path (date/location/type/status) in indexed SQL.

## Running locally

```bash
composer install
php artisan migrate --seed        # seed data (SEED_ROWS env controls volume)
php artisan storage:link
php artisan events:attach-images  # attach local images to events
npm install && npm run dev        # Vite
php artisan serve                 # app on http://127.0.0.1:8000
php artisan queue:work            # process confirmation/reminder emails
```
