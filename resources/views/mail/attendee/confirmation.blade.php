@component('mail::message')
# You're on the list! 🎉

Hi {{ $attendee->name }},

Thanks for registering your {{ $attendee->status === 'interested' ? 'interest in' : 'attendance for' }}:

## {{ $event->title() }}

@if($dateTime)
**When:** {{ $dateTime->format('l, j F Y · H:i') }} (UTC)
@endif
@if($location)
**Where:** {{ $location['label'] }}
@endif

{{ $event->description() }}

We'll send you reminders as the event approaches.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
