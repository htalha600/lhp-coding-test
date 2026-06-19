<?php

namespace App\Console\Commands;

use App\Mail\EventReminder;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends "event is approaching" reminders to attendees.
 *
 * Two windows are handled: 3 days before and 24 hours before. Each attendee
 * receives each reminder at most once (tracked via reminded_3d_at /
 * reminded_24h_at). Designed to be run frequently (e.g. hourly) by the
 * scheduler — it only emails attendees whose event falls inside the window.
 */
class SendEventReminders extends Command
{
    protected $signature = 'events:send-reminders';

    protected $description = 'Email attendees reminders 3 days and 24 hours before their event';

    public function handle(): int
    {
        $now = now();

        $sent = 0;
        // 3-day reminder: event is between 24 hours and 3 days away.
        $sent += $this->process($now->copy()->addDay()->timestamp, $now->copy()->addDays(3)->timestamp, 'reminded_3d_at', '3 days');
        // 24-hour reminder: event is within the next 24 hours.
        $sent += $this->process($now->timestamp, $now->copy()->addDay()->timestamp, 'reminded_24h_at', '24 hours');

        $this->info("Queued {$sent} reminder email(s).");

        return self::SUCCESS;
    }

    /**
     * Queue reminders for events whose time falls in [$fromTs, $toTs] and whose
     * attendees have not yet received this window's reminder.
     */
    private function process(int $fromTs, int $toTs, string $column, string $window): int
    {
        $now = now();
        $sent = 0;

        $events = Event::query()
            ->whereBetween('event_time', [$fromTs, $toTs])
            ->whereHas('attendees', fn ($q) => $q->whereNull($column))
            ->with(['attendees' => fn ($q) => $q->whereNull($column)])
            ->get();

        foreach ($events as $event) {
            foreach ($event->attendees as $attendee) {
                Mail::to($attendee->email)->queue(new EventReminder($attendee, $event, $window));
                $attendee->forceFill([$column => $now])->save();
                $sent++;
            }
        }

        return $sent;
    }
}
