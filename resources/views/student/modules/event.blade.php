@php($event = $event ?? null)

@if (! $event)
    <div class="card"><p>The requested event could not be found.</p></div>
@else
    <section class="card">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">SPORTS EVENT</span>
                <h2>{{ $event->title }}</h2>
                <p>{{ $event->sport?->name ?? 'All sports' }} · {{ $event->event_type }}</p>
            </div>
            <span class="badge {{ $event->status === 'Cancelled' ? 'status-rejected' : ($event->status === 'Completed' ? 'status-approved' : 'status-pending') }}">{{ $event->status }}</span>
        </div>

        <div class="medical-detail-facts" style="margin-top: 12px;">
            <div class="medical-fact"><span>Date</span><strong>{{ $event->starts_at?->format('F j, Y') ?? '—' }}</strong></div>
            <div class="medical-fact"><span>Time</span><strong>{{ $event->starts_at?->format('g:i A') ?? '—' }}@if ($event->ends_at) - {{ $event->ends_at->format('g:i A') }}@endif</strong></div>
            <div class="medical-fact"><span>Venue</span><strong>{{ $event->venue ?: '—' }}</strong></div>
            <div class="medical-fact"><span>Sport</span><strong>{{ $event->sport?->name ?? 'All sports' }}</strong></div>
            <div class="medical-fact"><span>Team</span><strong>{{ $event->team_name ?: 'Not assigned' }}</strong></div>
            <div class="medical-fact"><span>Coach</span><strong>{{ $event->coach?->name ?? 'Not assigned' }}</strong></div>
        </div>

        @if ($event->description)
            <div class="message-content" style="margin-top:16px;">{{ $event->description }}</div>
        @endif
        @if ($event->notes)
            <div class="card" style="margin-top:12px; background: var(--color-surface-muted);">
                <strong>Notes</strong>
                <p style="margin:8px 0 0; white-space:pre-wrap;">{{ $event->notes }}</p>
            </div>
        @endif

        <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
            <a class="button button-secondary" href="{{ route('student.schedule') }}">Back to Schedule</a>
            <a class="button button-muted" href="{{ route('student.calendar') }}">Calendar</a>
        </div>
    </section>
@endif
