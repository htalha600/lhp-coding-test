<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendeeRequest;
use App\Mail\AttendeeConfirmation;
use App\Models\Event;
use App\Models\EventAttendee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

class AttendeeController extends Controller
{
    public function store(StoreAttendeeRequest $request, Event $event): RedirectResponse
    {
        $data = $request->validated();

        // Idempotent: re-registering the same email just updates their status.
        $attendee = EventAttendee::updateOrCreate(
            ['event_id' => $event->id, 'email' => $data['email']],
            ['name' => $data['name'], 'status' => $data['status'] ?? 'attending'],
        );

        // Only send a confirmation the first time they join the list.
        if ($attendee->wasRecentlyCreated) {
            Mail::to($attendee->email)->queue(new AttendeeConfirmation($attendee, $event));
        }

        return back()->with('success', "You're on the list — a confirmation email is on its way.");
    }
}
