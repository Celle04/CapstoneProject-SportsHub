{{ config('app.name', 'SNNHS Sports Activity Hub') }}
{{ $reminder->category }}

{{ $reminder->subject }}

Dear {{ $athleteName }},

{{ $reminder->message }}
@if ($reminder->relevant_date || $reminder->relevant_time)

Schedule / Date: @if ($reminder->relevant_date){{ $reminder->relevant_date->format('F j, Y') }}@endif @if ($reminder->relevant_time){{ \Illuminate\Support\Carbon::parse($reminder->relevant_time)->format('g:i A') }}@endif
@endif

Sincerely,
School Administration
{{ config('app.name', 'SNNHS Sports Activity Hub') }}

This reminder was sent through {{ config('app.name', 'SNNHS Sports Activity Hub') }}.
