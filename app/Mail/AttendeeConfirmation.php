<?php

namespace App\Mail;

use App\Models\Event;
use App\Models\EventAttendee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AttendeeConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public EventAttendee $attendee,
        public Event $event,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're on the list: {$this->event->title()}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.attendee.confirmation',
            with: [
                'attendee' => $this->attendee,
                'event' => $this->event,
                'dateTime' => $this->event->dateTime(),
                'location' => $this->event->location(),
            ],
        );
    }
}
