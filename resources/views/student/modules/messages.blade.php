@php($messageView = $messageView ?? 'index')

@if ($messageView === 'show' && isset($message))
    @php($event = $message->event)
    @php($category = \App\Models\Message::categoryLabel($message->message_type))
    <section class="card student-message-detail">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">{{ strtoupper($category) }}</span>
                <h2>{{ $message->subject }}</h2>
                <p>From {{ $message->sender?->name ?? 'Sports Office' }} · {{ $message->sent_at?->format('M j, Y g:i A') }}</p>
            </div>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <span class="badge">{{ $category }}</span>
                @if ($message->priority !== 'Normal')<span class="badge status-pending">{{ $message->priority }}</span>@endif
            </div>
        </div>

        @if ($event)
            <div class="message-reminder-schedule">
                <strong>Event details</strong>
                <span>{{ $event->title }}</span>
                <span>{{ $event->starts_at?->format('F j, Y g:i A') ?? 'Date/time not specified' }}@if ($event->ends_at) - {{ $event->ends_at->format('g:i A') }}@endif</span>
                @if ($event->venue)<span>{{ $event->venue }}</span>@endif
                <span>Status: {{ $event->status }}</span>
            </div>
        @endif

        @if ($message->message_type === 'medical_reminder')
            <div class="message-reminder-schedule">
                <strong>Your checkup schedule</strong>
                <span>{{ $message->checkup_date?->format('F j, Y') ?? 'Date not specified' }}@if ($message->checkup_time) · {{ \Illuminate\Support\Carbon::parse($message->checkup_time)->format('g:i A') }}@endif</span>
                @if ($message->location)<span>{{ $message->location }}</span>@endif
            </div>
        @endif

        <div class="message-content">{{ $message->content }}</div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:16px;">
            @if ($event)
                <a class="button" href="{{ route('student.events.show', $event) }}">View Event Details</a>
            @endif
            <a class="button button-secondary" href="{{ route('student.messages') }}">Back to Messages</a>
        </div>
    </section>
@else
    <section class="card student-message-list">
        <div class="section-heading">
            <div><span class="admin-dashboard-kicker">YOUR INBOX</span><h2>Messages & Notifications</h2></div>
        </div>
        <div class="student-message-items">
            @forelse ($messages as $delivery)
                @php($message = $delivery->message)
                @php($category = \App\Models\Message::categoryLabel($message->message_type))
                <a class="student-message-item {{ $delivery->is_read ? '' : 'is-unread' }}" href="{{ route('student.messages.show', $message) }}">
                    <span class="student-message-icon" aria-hidden="true">{{ $message->event_id ? 'E' : ($message->message_type === 'medical_reminder' ? 'M' : 'S') }}</span>
                    <span class="student-message-copy">
                        <strong>{{ $message->subject }}</strong>
                        <small>{{ $category }} · From {{ $message->sender?->name ?? 'Sports Office' }} · {{ $message->sent_at?->format('M j, Y g:i A') }}</small>
                        @if ($message->event)
                            <small>{{ $message->event->starts_at?->format('M j, Y g:i A') }} @if ($message->event->venue)· {{ $message->event->venue }}@endif</small>
                        @endif
                        <span>{{ \Illuminate\Support\Str::limit($message->content, 150) }}</span>
                    </span>
                    <span class="student-message-status">
                        @if ($message->priority !== 'Normal')<em>{{ $message->priority }}</em>@endif
                        <small>{{ $delivery->is_read ? 'Read' : 'Unread' }}</small>
                    </span>
                </a>
            @empty
                <div class="empty-state"><strong>You're all caught up</strong><p>Messages and event notifications from the Sports Office will appear here.</p></div>
            @endforelse
        </div>
        {{ $messages->links() }}
    </section>
@endif
