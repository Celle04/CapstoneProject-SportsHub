@php($messageView = $messageView ?? 'index')

@if ($messageView === 'show' && isset($message))
    <section class="card student-message-detail">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">{{ $message->message_type === 'medical_reminder' ? 'MEDICAL CHECKUP REMINDER' : 'SPORTSHUB MESSAGE' }}</span>
                <h2>{{ $message->subject }}</h2>
                <p>From {{ $message->sender?->name ?? 'Sports Office' }} · {{ $message->sent_at?->format('M j, Y g:i A') }}</p>
            </div>
            @if ($message->priority !== 'Normal')<span class="badge status-pending">{{ $message->priority }}</span>@endif
        </div>
        @if ($message->message_type === 'medical_reminder')
            <div class="message-reminder-schedule">
                <strong>Your checkup schedule</strong>
                <span>{{ $message->checkup_date?->format('F j, Y') ?? 'Date not specified' }}@if ($message->checkup_time) · {{ \Illuminate\Support\Carbon::parse($message->checkup_time)->format('g:i A') }}@endif</span>
                @if ($message->location)<span>{{ $message->location }}</span>@endif
            </div>
        @endif
        <div class="message-content">{{ $message->content }}</div>
        <a class="button button-secondary" href="{{ route('student.messages') }}">Back to Messages</a>
    </section>
@else
    <section class="card student-message-list">
        <div class="section-heading">
            <div><span class="admin-dashboard-kicker">YOUR INBOX</span><h2>Messages & Checkup Reminders</h2></div>
        </div>
        <div class="student-message-items">
            @forelse ($messages as $delivery)
                @php($message = $delivery->message)
                <a class="student-message-item {{ $delivery->is_read ? '' : 'is-unread' }}" href="{{ route('student.messages.show', $message) }}">
                    <span class="student-message-icon" aria-hidden="true">{{ $message->message_type === 'medical_reminder' ? 'M' : 'S' }}</span>
                    <span class="student-message-copy">
                        <strong>{{ $message->subject }}</strong>
                        <small>From {{ $message->sender?->name ?? 'Sports Office' }} · {{ $message->sent_at?->format('M j, Y g:i A') }}</small>
                        <span>{{ \Illuminate\Support\Str::limit($message->content, 150) }}</span>
                    </span>
                    <span class="student-message-status">
                        @if ($message->priority !== 'Normal')<em>{{ $message->priority }}</em>@endif
                        <small>{{ $delivery->is_read ? 'Read' : 'Unread' }}</small>
                    </span>
                </a>
            @empty
                <div class="empty-state"><strong>You’re all caught up</strong><p>Messages and medical checkup reminders from the Sports Office will appear here.</p></div>
            @endforelse
        </div>
        {{ $messages->links() }}
    </section>
@endif
