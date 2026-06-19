@component('mail::message')
# {{ $event->title() }} is in {{ $window }} ⏰

Hi {{ $attendee->name }},

Just a reminder that the event you registered for is coming up in **{{ $window }}**.

@if($dateTime)
**When:** {{ $dateTime->format('l, j F Y · H:i') }} (UTC)
@endif
@if($location)
**Where:** {{ $location['label'] }}
@endif

See you there!<br>
{{ config('app.name') }}
@endcomponent
