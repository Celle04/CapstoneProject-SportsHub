@extends('layouts.portal')

@section('content')
<div class="module-header">
    <div>
        <h1>Event Details</h1>
        <p class="page-subtitle">Review the complete event record and notify affected athletes</p>
    </div>
    <div class="event-header-actions">
        <a class="button button-secondary button-icon-only" href="{{ route('events.index') }}" aria-label="Back to Events" title="Back to Events"><svg class="button-icon" aria-hidden="true"><use href="#icon-arrow-left"></use></svg></a>
        <a class="button" href="{{ route('events.edit', $event) }}">Edit Event</a>
    </div>
</div>

@if (session('success'))
    <div class="notice" role="status">{{ session('success') }}</div>
@endif
@if ($errors->any())
    <div class="notice notice-error" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card event-detail-card">
    <div class="event-banner">
        <span class="event-banner-icon" aria-hidden="true"><svg><use href="#icon-calendar"></use></svg></span>
        <div class="event-banner-copy">
            <span class="admin-dashboard-kicker">Event Record</span>
            <h2>{{ $event->title }}</h2>
            <p>
                <span class="event-banner-meta">{{ $event->sport?->name ?? 'All sports' }} &middot; {{ $event->event_type }}</span>
                <span class="badge">{{ $event->status }}</span>
            </p>
        </div>
        <div class="event-banner-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
            <a class="button" href="{{ route('admin.messages.create', array_filter([
                'event_id' => $event->id,
                'subject' => $event->title,
                'recipient_type' => $event->sport_id ? 'sport' : 'all',
                'sport_id' => $event->sport_id,
                'message_type' => in_array($event->event_type, ['Competition', 'Tournament', 'Friendly Match', 'District Meet', 'Regional Meet'], true) ? 'competition' : 'sports_event',
                'content' => implode("\n", array_filter([
                    'Event: '.$event->title,
                    'Type: '.$event->event_type,
                    $event->starts_at ? 'Date/Time: '.$event->starts_at->format('F j, Y g:i A').($event->ends_at ? ' - '.$event->ends_at->format('g:i A') : '') : null,
                    $event->venue ? 'Venue: '.$event->venue : null,
                    $event->sport?->name ? 'Sport: '.$event->sport->name : null,
                    $event->description ? 'Description: '.$event->description : null,
                ])),
            ])) }}">Send Notification</a>
            <a class="button button-secondary" href="{{ route('admin.attendance', ['event_id' => $event->id]) }}">View Attendance</a>
        </div>
    </div>

    <div class="event-detail-facts">
        <div class="medical-fact"><span>Sport</span><strong>{{ $event->sport?->name ?? 'Not assigned' }}</strong></div>
        <div class="medical-fact"><span>Event type</span><strong>{{ $event->event_type }}</strong></div>
        <div class="medical-fact"><span>Date</span><strong>{{ $event->starts_at->format('F j, Y') }}</strong></div>
        <div class="medical-fact"><span>Time</span><strong>{{ $event->starts_at->format('g:i A') }} - {{ $event->ends_at->format('g:i A') }}</strong></div>
        <div class="medical-fact"><span>Venue</span><strong>{{ $event->venue }}</strong></div>
        <div class="medical-fact"><span>Coach</span><strong>{{ $event->coach?->name ?? 'Not assigned' }}</strong></div>
        <div class="medical-fact"><span>Team</span><strong>{{ $event->team_name ?: 'Not assigned' }}</strong></div>
        <div class="medical-fact"><span>Participants</span><strong>{{ $event->attendance_records_count }}{{ $event->max_participants ? ' / '.$event->max_participants : '' }}</strong></div>
    </div>

    @if ($event->description || $event->notes)
    <div class="event-detail-copy">
        @if ($event->description)
            <strong>Description</strong>
            <p>{{ $event->description }}</p>
        @endif
        @if ($event->notes)
            <strong>Notes</strong>
            <p>{{ $event->notes }}</p>
        @endif
    </div>
    @endif

    @if ($event->attendance_records_count > 0)
    <div class="event-detail-copy">
        <strong>Attendance</strong>
        <p>{{ $event->attendance_records_count }} record{{ $event->attendance_records_count === 1 ? '' : 's' }} on file. <a class="text-link" href="{{ route('admin.attendance', ['event_id' => $event->id]) }}">View all attendance records</a></p>
    </div>
    @endif

    @if ($event->status !== 'Cancelled' && $event->status !== 'Completed')
        <details class="card" style="margin-top: 20px; background: var(--color-surface-muted);">
            <summary style="cursor:pointer; font-weight:700;">Cancel Event</summary>
            <form method="POST" action="{{ route('events.cancel', $event) }}" style="margin-top:14px;">
                @csrf
                <p class="meta">Cancelling preserves event history. You can optionally notify affected athletes with the event name and cancellation information.</p>
                <label style="display:flex; align-items:center; gap:10px; margin:12px 0; cursor:pointer;">
                    <input type="checkbox" name="notify_athletes" value="1" checked>
                    Notify affected athletes about this cancellation
                </label>
                <button class="button button-danger" type="submit">Confirm Cancellation</button>
            </form>
        </details>
    @endif

    <div class="event-actions" style="margin-top: 30px;">
        <button class="button button-danger" type="button" data-confirm-dialog data-confirm-title="Delete Event?" data-confirm-message="Are you sure you want to delete this event? Deleting removes the event record. Use Cancel Event if you need to preserve history." data-confirm-label="Delete Event" data-confirm-method="DELETE" data-confirm-url="{{ route('events.destroy', $event) }}">Delete Event</button>
    </div>
</div>

<section class="card" style="margin-top: 18px;">
    <div class="section-heading">
        <div>
            <span class="admin-dashboard-kicker">NOTIFICATION HISTORY</span>
            <h2>Event Notifications</h2>
            <p>Messages sent for this event, including recipient counts and read status.</p>
        </div>
        <a class="text-link" href="{{ route('admin.messages', ['event_id' => $event->id]) }}">Open in Messages</a>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Subject</th><th>Category</th><th>Recipients</th><th>Read</th><th>Unread</th><th>Date Sent</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($eventMessages ?? [] as $row)
                    <tr>
                        <td><a class="text-link" href="{{ route('admin.messages.show', $row) }}">{{ $row->subject ?: 'Untitled' }}</a></td>
                        <td>{{ \App\Models\Message::categoryLabel($row->message_type) }}</td>
                        <td>{{ $row->recipients_count }}</td>
                        <td>{{ $row->status === 'sent' ? $row->read_recipients_count : '—' }}</td>
                        <td>{{ $row->status === 'sent' ? $row->recipients_count - $row->read_recipients_count : '—' }}</td>
                        <td>{{ $row->sent_at?->format('M j, Y g:i A') ?? '—' }}</td>
                        <td><span class="badge {{ $row->status === 'sent' ? 'status-approved' : 'status-pending' }}">{{ ucfirst($row->status) }}</span></td>
                    </tr>
                @empty
                    <tr><td class="empty-cell" colspan="7">No notifications have been sent for this event yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
