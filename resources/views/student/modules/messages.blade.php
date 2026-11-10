@php($messageView = $messageView ?? 'index')
@php($messageTab = $messageTab ?? 'all')
@php($messageSummary = $messageSummary ?? ['total' => 0, 'general' => 0, 'medical' => 0, 'unread' => 0])

@if ($messageView === 'show' && isset($message))
    @php($event = $message->event)
    @php($category = \App\Models\Message::categoryName($message->category))
    @php($followUp = $message->followUp)
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
                @if ($followUp)<span class="badge follow-up-status follow-up-status-{{ strtolower($followUp->effectiveStatus()) }}">{{ $followUp->effectiveStatus() }}</span>@endif
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

        @if ($followUp)
            <div class="message-reminder-schedule">
                <strong>Your medical follow-up</strong>
                <span>{{ $followUp->follow_up_type }}</span>
                <span>{{ $followUp->scheduleLabel() }}</span>
                @if ($followUp->additional_instructions)<span>{{ $followUp->additional_instructions }}</span>@endif
                @if ($followUp->response_required)
                    <span>Acknowledgment required.</span>
                @endif
                @if ($followUpRecipient)
                    <span>
                        Your status:
                        @if ($followUpRecipient->isCompleted())
                            Completed
                        @elseif ($followUpRecipient->isAcknowledged())
                            Acknowledged on {{ $followUpRecipient->acknowledged_at?->format('M j, Y g:i A') }}
                        @elseif ($followUpRecipient->isRead())
                            Read — not acknowledged yet
                        @else
                            Not read yet
                        @endif
                    </span>
                @endif
            </div>
        @endif

        <div class="message-content">{{ $message->content }}</div>

        @if ($followUp && $followUp->response_required && $followUpRecipient && ! $followUpRecipient->isAcknowledged() && ! $followUpRecipient->isCompleted())
            <form method="POST" action="{{ route('student.messages.acknowledge', $message) }}" class="student-follow-up-actions">
                @csrf
                <p class="meta">Confirm that you received this follow-up so the sports office knows it reached you.</p>
                <button class="button" type="submit">Acknowledge</button>
            </form>
        @endif

        @if ($followUp && ! $followUpRecipient?->isCompleted() && $followUp->status !== \App\Models\MedicalFollowUp::STATUS_CANCELLED)
            <form method="POST" action="{{ route('student.messages.reply', $message) }}" class="student-follow-up-actions">
                @csrf
                <label for="student-follow-up-reply">Reply to the sports office</label>
                <textarea class="form-control" id="student-follow-up-reply" name="content" rows="4" maxlength="5000" placeholder="Add an update for the sports office"></textarea>
                <button class="button button-secondary" type="submit">Send Reply</button>
            </form>
        @endif

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
            <div><span class="admin-dashboard-kicker">YOUR INBOX</span><h2>Messages &amp; Follow-ups</h2></div>
        </div>
        <nav class="message-filter-tabs" aria-label="Message filters">
            <a href="{{ route('student.messages', ['tab' => 'all']) }}" class="{{ $messageTab === 'all' ? 'is-active' : '' }}">All ({{ $messageSummary['total'] }})</a>
            <a href="{{ route('student.messages', ['tab' => 'general']) }}" class="{{ $messageTab === 'general' ? 'is-active' : '' }}">General Messages ({{ $messageSummary['general'] }})</a>
            <a href="{{ route('student.messages', ['tab' => 'medical']) }}" class="{{ $messageTab === 'medical' ? 'is-active' : '' }}">Medical Follow-ups ({{ $messageSummary['medical'] }})</a>
            <a href="{{ route('student.messages', ['tab' => 'unread']) }}" class="{{ $messageTab === 'unread' ? 'is-active' : '' }}">Unread ({{ $messageSummary['unread'] }})</a>
        </nav>
        <div class="student-message-items">
            @forelse ($messages as $delivery)
                @php($item = $delivery->message)
                @php($itemCategory = \App\Models\Message::categoryName($item->category))
                <a class="student-message-item {{ $delivery->is_read ? '' : 'is-unread' }}" href="{{ route('student.messages.show', $item) }}">
                    <span class="student-message-icon" aria-hidden="true">{{ $item->event_id ? 'E' : ($item->isMedicalFollowUp() ? 'M' : 'S') }}</span>
                    <span class="student-message-copy">
                        <strong>{{ $item->subject }}</strong>
                        <small>{{ $itemCategory }} · From {{ $item->sender?->name ?? 'Sports Office' }} · {{ $item->sent_at?->format('M j, Y g:i A') }}</small>
                        @if ($item->followUp)
                            <small>{{ $item->followUp->follow_up_type }} · {{ $item->followUp->scheduleLabel() }}</small>
                        @endif
                        @if ($item->event)
                            <small>{{ $item->event->starts_at?->format('M j, Y g:i A') }} @if ($item->event->venue)· {{ $item->event->venue }}@endif</small>
                        @endif
                        <span>{{ \Illuminate\Support\Str::limit($item->content, 150) }}</span>
                    </span>
                    <span class="student-message-status">
                        @if ($item->priority !== 'Normal')<em>{{ $item->priority }}</em>@endif
                        @if ($item->isMedicalFollowUp() && $item->followUp)
                            <em class="follow-up-status follow-up-status-{{ strtolower($item->followUp->effectiveStatus()) }}">{{ $item->followUp->effectiveStatus() }}</em>
                        @endif
                        <small>{{ $delivery->is_read ? 'Read' : 'Unread' }}</small>
                    </span>
                </a>
            @empty
                <div class="empty-state"><strong>You're all caught up</strong><p>Messages and medical follow-ups from the Sports Office will appear here.</p></div>
            @endforelse
        </div>
        {{ $messages->links() }}
    </section>
@endif
