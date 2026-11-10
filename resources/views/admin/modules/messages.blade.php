@php
    $messageView = $messageView ?? 'index';
    $messageSummary = $messageSummary ?? ['sent' => 0, 'general' => 0, 'medical' => 0, 'recipients' => 0, 'read' => 0, 'unread' => 0, 'drafts' => 0];
    $messageRecord = $messageRecord ?? new \App\Models\Message();
    $followUpRecord = $followUpRecord ?? $messageRecord->followUp;
    $messageTab = $messageTab ?? 'all';
    $recipientType = old('recipient_type', $messageRecord->recipient_type ?: 'individual');
    $messageCategory = old('category', $messageRecord->category ?: 'general');
    $selectedRecipientIds = array_map('strval', old('recipient_ids', $messageRecord->recipient_ids ?? []));
    $eventRecord = $messageRecord->event ?? null;
    $followUpTypes = $followUpTypes ?? \App\Models\MedicalFollowUp::TYPES;
@endphp

@if (session('success'))
    <div class="notice" role="status">{{ session('success') }}</div>
@endif
@if ($errors->any())
    <div class="notice notice-error" role="alert">Please review the highlighted message fields.</div>
@endif

@if ($messageView === 'compose')
    <section class="card message-compose-card">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">{{ $messageRecord->exists ? 'DRAFT' : 'NEW MESSAGE' }}</span>
                <h2>{{ $messageRecord->exists ? 'Edit Draft' : 'Compose Message' }}</h2>
                <p>General messages and medical follow-ups are delivered to the athletes' SportsHub inbox and notification bell.</p>
            </div>
            <a class="text-link" href="{{ route('admin.messages') }}">Back to Messages</a>
        </div>

        <form method="POST" action="{{ $messageRecord->exists ? route('admin.messages.update', $messageRecord) : route('admin.messages.store') }}" class="message-form">
            @csrf
            @if ($messageRecord->exists) @method('PUT') @endif
            <input type="hidden" name="event_id" value="{{ old('event_id', $messageRecord->event_id) }}">
            @if ($eventRecord)
                <div class="notice" style="margin:0;">
                    <strong>Associated event:</strong> {{ $eventRecord->title }}
                    ({{ $eventRecord->starts_at?->format('M j, Y g:i A') ?? 'Date not set' }}@if ($eventRecord->venue) · {{ $eventRecord->venue }}@endif).
                    <a class="text-link" href="{{ route('events.show', $eventRecord) }}">View event</a>
                </div>
            @endif
            <div class="form-group">
                <label for="message-subject">Subject</label>
                <input class="form-control @error('subject') is-invalid @enderror" id="message-subject" name="subject" maxlength="255" value="{{ old('subject', $messageRecord->subject) }}" placeholder="Enter a subject">
                @error('subject')<small class="form-error">{{ $message }}</small>@enderror
            </div>
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="message-category">Message Category</label>
                    <select class="form-control" id="message-category" name="category">
                        <option value="general" @selected($messageCategory === 'general')>General Message</option>
                        <option value="medical_follow_up" @selected($messageCategory === 'medical_follow_up')>Medical Follow-up</option>
                    </select>
                    <small class="form-hint">A medical follow-up is tracked until it is acknowledged and verified.</small>
                </div>
                <div class="form-group">
                    <label for="message-priority">Priority</label>
                    <select class="form-control" id="message-priority" name="priority">
                        @foreach (['Normal', 'Important', 'Urgent'] as $priority)
                            <option value="{{ $priority }}" @selected(old('priority', $messageRecord->priority ?: 'Normal') === $priority)>{{ $priority }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="recipient-type">Recipients</label>
                    <select class="form-control" id="recipient-type" name="recipient_type">
                        @foreach (['individual' => 'One athlete', 'selected' => 'Individually selected athletes', 'all' => 'All active athletes', 'sport' => 'Athletes from a selected sport'] as $value => $label)
                            <option value="{{ $value }}" @selected($recipientType === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-group message-recipient-panel" data-recipient-panel="individual">
                <label for="message-athlete-search">Find athlete by name or Student ID</label>
                <input class="form-control" id="message-athlete-search" type="search" placeholder="Search athletes" autocomplete="off">
                <select class="form-control" name="athlete_id" id="message-athlete">
                    <option value="">Select an active athlete</option>
                    @foreach ($activeStudents as $student)
                        <option value="{{ $student->id }}" @selected((string) old('athlete_id', $messageRecord->recipient_ids[0] ?? '') === (string) $student->id)>
                            {{ $student->name }}@if ($student->student_id) ({{ $student->student_id }})@endif — {{ $student->sport?->name ?? 'Unassigned' }}
                        </option>
                    @endforeach
                </select>
                @error('athlete_id')<small class="form-error">{{ $message }}</small>@enderror
            </div>

            <div class="form-group message-recipient-panel" data-recipient-panel="sport" hidden>
                <label for="message-sport">Sport</label>
                <select class="form-control" id="message-sport" name="sport_id">
                    <option value="">Select a sport</option>
                    @foreach ($sports as $sportOption)
                        <option value="{{ $sportOption->id }}" @selected((string) old('sport_id', $messageRecord->sport_id ?? '') === (string) $sportOption->id)>{{ $sportOption->name }}</option>
                    @endforeach
                </select>
                @error('sport_id')<small class="form-error">{{ $message }}</small>@enderror
            </div>

            <div class="message-recipient-panel" data-recipient-panel="selected" hidden>
                <div class="form-group">
                    <label for="message-recipient-search">Find athletes</label>
                    <input class="form-control" id="message-recipient-search" type="search" placeholder="Filter by athlete name or Student ID" autocomplete="off">
                </div>
                <div class="message-recipient-list">
                    @foreach ($activeStudents as $student)
                        <label class="message-recipient-option" data-recipient-option>
                            <input type="checkbox" name="recipient_ids[]" value="{{ $student->id }}" @checked(in_array((string) $student->id, $selectedRecipientIds, true))>
                            <span>{{ $student->name }}@if ($student->student_id) <small>({{ $student->student_id }})</small>@endif</span>
                            <small>{{ $student->sport?->name ?? 'Unassigned' }}</small>
                        </label>
                    @endforeach
                </div>
                @error('recipient_ids')<small class="form-error">{{ $message }}</small>@enderror
                @error('recipients')<small class="form-error">{{ $message }}</small>@enderror
            </div>

            <div class="grid grid-3 message-medical-fields" data-medical-fields hidden>
                <div class="form-group">
                    <label for="follow-up-type">Follow-up Type</label>
                    <select class="form-control @error('follow_up_type') is-invalid @enderror" id="follow-up-type" name="follow_up_type">
                        @foreach ($followUpTypes as $type)
                            <option value="{{ $type }}" @selected(old('follow_up_type', $followUpRecord?->follow_up_type ?? 'Medical Checkup Reminder') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                    @error('follow_up_type')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <label for="scheduled-date">Checkup or Follow-up Date</label>
                    <input class="form-control @error('scheduled_date') is-invalid @enderror" type="date" id="scheduled-date" name="scheduled_date" value="{{ old('scheduled_date', $followUpRecord?->scheduled_date?->format('Y-m-d') ?? $messageRecord->checkup_date?->format('Y-m-d')) }}">
                    @error('scheduled_date')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <label for="scheduled-time">Checkup or Follow-up Time</label>
                    <input class="form-control @error('scheduled_time') is-invalid @enderror" type="time" id="scheduled-time" name="scheduled_time" value="{{ old('scheduled_time', $followUpRecord?->scheduled_time ?? $messageRecord->checkup_time) }}">
                    @error('scheduled_time')<small class="form-error">{{ $message }}</small>@enderror
                </div>
            </div>

            <div class="grid grid-2 message-medical-fields" data-medical-fields hidden>
                <div class="form-group">
                    <label for="follow-up-location">Location</label>
                    <input class="form-control @error('location') is-invalid @enderror" type="text" id="follow-up-location" name="location" maxlength="255" value="{{ old('location', $followUpRecord?->location ?? $messageRecord->location) }}" placeholder="e.g. School Medical Clinic">
                    @error('location')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <label for="response-required">Response Required</label>
                    <select class="form-control" id="response-required" name="response_required">
                        <option value="0" @selected(! old('response_required', $followUpRecord?->response_required ?? false))>No acknowledgement needed</option>
                        <option value="1" @selected((bool) old('response_required', $followUpRecord?->response_required ?? false))>Yes — the athlete must acknowledge</option>
                    </select>
                </div>
            </div>

            <div class="form-group message-medical-fields" data-medical-fields hidden>
                <label for="follow-up-instructions">Additional Medical Instructions</label>
                <textarea class="form-control @error('additional_instructions') is-invalid @enderror" id="follow-up-instructions" name="additional_instructions" rows="3" maxlength="5000" placeholder="Documents to bring, preparation notes, and what the athlete has to do.">{{ old('additional_instructions', $followUpRecord?->additional_instructions) }}</textarea>
                @error('additional_instructions')<small class="form-error">{{ $message }}</small>@enderror
            </div>

            <div class="form-group">
                <label for="message-content">Message / Instructions</label>
                <textarea class="form-control @error('content') is-invalid @enderror" id="message-content" name="content" rows="7" maxlength="20000" placeholder="Write the message for athletes">{{ old('content', $messageRecord->content) }}</textarea>
                @error('content')<small class="form-error">{{ $message }}</small>@enderror
            </div>

            <div class="message-form-actions">
                <a class="button button-muted" href="{{ route('admin.messages') }}">Cancel</a>
                <button class="button button-secondary" type="submit" name="action" value="draft">Save as Draft</button>
                <button class="button" type="submit" name="action" value="send">Send Message</button>
            </div>
            @error('recipients')<small class="form-error">{{ $message }}</small>@enderror
        </form>
    </section>
    <script src="{{ asset('js/messages.js') }}?v={{ filemtime(public_path('js/messages.js')) }}" defer></script>
@elseif ($messageView === 'show' && isset($messageRecord))
    @php
        $detailCategory = \App\Models\Message::categoryName($messageRecord->category);
    @endphp
    <section class="card message-detail-card">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">{{ strtoupper($detailCategory) }}</span>
                <h2>{{ $messageRecord->subject }}</h2>
                <p>Sent {{ $messageRecord->sent_at?->format('M j, Y g:i A') }} by {{ $messageRecord->sender?->name ?? 'SportsHub Admin' }}</p>
            </div>
            <a class="text-link" href="{{ route('admin.messages') }}">Back to Messages</a>
        </div>
        <div class="message-detail-metadata">
            <span><strong>Category</strong>{{ $detailCategory }}</span>
            <span><strong>Type</strong>{{ \App\Models\Message::categoryLabel($messageRecord->message_type) }}</span>
            <span><strong>Recipients</strong>{{ $messageRecord->recipients_count }}</span>
            <span><strong>Read</strong>{{ $messageRecord->read_recipients_count }}</span>
            <span><strong>Unread</strong>{{ $messageRecord->recipients_count - $messageRecord->read_recipients_count }}</span>
            <span><strong>Priority</strong><span class="badge">{{ $messageRecord->priority }}</span></span>
            <span><strong>Recipient type</strong>{{ ucfirst($messageRecord->recipient_type) }}@if ($messageRecord->sport) · {{ $messageRecord->sport->name }}@endif</span>
            @if ($messageRecord->event)
                <span><strong>Associated Event</strong><a class="text-link" href="{{ route('events.show', $messageRecord->event) }}">{{ $messageRecord->event->title }}</a></span>
            @endif
        </div>
        @if ($messageRecord->event)
            <div class="message-reminder-schedule">
                <strong>Event details</strong>
                <span>{{ $messageRecord->event->title }} · {{ $messageRecord->event->starts_at?->format('M j, Y g:i A') ?? 'Date not set' }}@if ($messageRecord->event->ends_at) - {{ $messageRecord->event->ends_at->format('g:i A') }}@endif</span>
                @if ($messageRecord->event->venue)<span>{{ $messageRecord->event->venue }}</span>@endif
                <span>Status: {{ $messageRecord->event->status }}</span>
            </div>
        @endif
        @if ($followUpRecord)
            <div class="message-reminder-schedule">
                <strong>Medical follow-up</strong>
                <span>{{ $followUpRecord->follow_up_type }}</span>
                <span>{{ $followUpRecord->scheduleLabel() }}</span>
                @if ($followUpRecord->additional_instructions)<span>{{ $followUpRecord->additional_instructions }}</span>@endif
                <span>Response required: {{ $followUpRecord->response_required ? 'Yes' : 'No' }}</span>
                <span>Status: <span class="badge follow-up-status follow-up-status-{{ strtolower($followUpRecord->effectiveStatus()) }}">{{ $followUpRecord->effectiveStatus() }}</span></span>
            </div>
        @endif
        <div class="message-content">{{ $messageRecord->content }}</div>
        @if ($followUpRecord)
            <h3>Follow-up Recipient Tracking</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Athlete</th><th>Student ID</th><th>Sport</th><th>Delivery</th><th>Read Status</th><th>Acknowledgment</th><th>Completion</th><th>Follow-up Date</th><th>Last Updated</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($followUpRecord->recipients as $recipient)
                            <tr>
                                <td>{{ $recipient->recipient?->name ?? 'Account removed' }}</td>
                                <td>{{ $recipient->recipient?->student_id ?: '—' }}</td>
                                <td>{{ $recipient->recipient?->sport?->name ?? 'Unassigned' }}</td>
                                <td>Sent</td>
                                <td><span class="badge {{ $recipient->isRead() ? 'status-approved' : 'status-pending' }}">{{ $recipient->isRead() ? 'Read' : 'Unread' }}</span></td>
                                <td><span class="badge {{ $recipient->isAcknowledged() ? 'status-approved' : 'status-pending' }}">{{ $recipient->isAcknowledged() ? 'Acknowledged' : 'Not acknowledged' }}</span></td>
                                <td><span class="badge {{ $recipient->isCompleted() ? 'status-approved' : 'status-pending' }}">{{ $recipient->isCompleted() ? 'Completed' : 'Pending' }}</span></td>
                                <td>{{ $followUpRecord->scheduled_date?->format('M j, Y') ?? '—' }}</td>
                                <td>{{ $recipient->updated_at?->format('M j, Y g:i A') ?? '—' }}</td>
                                <td>
                                    @unless ($recipient->isCompleted())
                                        <button class="row-action" type="button" data-confirm-dialog data-confirm-title="Mark as completed?" data-confirm-message="Confirm that this athlete completed the follow-up. Only an administrator can verify a completion." data-confirm-label="Mark Completed" data-confirm-icon="check" data-confirm-variant="success" data-confirm-method="PATCH" data-confirm-url="{{ route('admin.follow-ups.recipient.complete', [$messageRecord, $recipient->recipient_id]) }}">Mark Completed</button>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="10">This follow-up has no recipients yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="message-form-actions">
                @unless (in_array($followUpRecord->status, [\App\Models\MedicalFollowUp::STATUS_COMPLETED, \App\Models\MedicalFollowUp::STATUS_CANCELLED], true))
                    <button class="button" type="button" data-confirm-dialog data-confirm-title="Mark the follow-up as completed?" data-confirm-message="Only mark this follow-up as completed after verifying that the athletes finished it." data-confirm-label="Mark Completed" data-confirm-icon="check" data-confirm-variant="success" data-confirm-method="PATCH" data-confirm-url="{{ route('admin.follow-ups.complete', $messageRecord) }}">Mark Follow-up Completed</button>
                    <button class="button button-secondary" type="button" data-confirm-dialog data-confirm-title="Cancel this follow-up?" data-confirm-message="The athletes will see that this follow-up was cancelled." data-confirm-label="Cancel Follow-up" data-confirm-icon="warning" data-confirm-variant="warning" data-confirm-method="PATCH" data-confirm-url="{{ route('admin.follow-ups.cancel', $messageRecord) }}">Cancel Follow-up</button>
                @endunless
            </div>
        @else
            <h3>Recipient Read Tracking</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Athlete</th><th>Student ID</th><th>Sport</th><th>Delivery</th><th>Read Status</th><th>Read At</th></tr></thead>
                    <tbody>
                        @foreach ($messageRecord->recipients as $delivery)
                            <tr>
                                <td>{{ $delivery->recipient?->name ?? 'Account removed' }}</td>
                                <td>{{ $delivery->recipient?->student_id ?: '—' }}</td>
                                <td>{{ $delivery->recipient?->sport?->name ?? 'Unassigned' }}</td>
                                <td>Sent</td>
                                <td><span class="badge {{ $delivery->is_read ? 'status-approved' : 'status-pending' }}">{{ $delivery->is_read ? 'Read' : 'Unread' }}</span></td>
                                <td>{{ $delivery->read_at?->format('M j, Y g:i A') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($messageRecord->replies->isNotEmpty())
            <h3>Replies</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>From</th><th>Reply</th><th>Sent</th></tr></thead>
                    <tbody>
                        @foreach ($messageRecord->replies as $reply)
                            <tr>
                                <td>{{ $reply->sender?->name ?? 'Unknown' }}</td>
                                <td>{{ \Illuminate\Support\Str::limit($reply->content, 160) }}</td>
                                <td>{{ $reply->sent_at?->format('M j, Y g:i A') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@else
    <div class="grid message-summary-grid">
        <div class="card stat"><small>Total Messages Sent</small><strong>{{ $messageSummary['sent'] }}</strong></div>
        <div class="card stat"><small>General Messages</small><strong>{{ $messageSummary['general'] }}</strong></div>
        <div class="card stat"><small>Medical Follow-ups</small><strong>{{ $messageSummary['medical'] }}</strong></div>
        <div class="card stat"><small>Read</small><strong>{{ $messageSummary['read'] }}</strong></div>
        <div class="card stat"><small>Unread</small><strong>{{ $messageSummary['unread'] }}</strong></div>
        <div class="card stat"><small>Drafts</small><strong>{{ $messageSummary['drafts'] }}</strong></div>
    </div>

    <section class="card message-list-card">
        <div class="section-heading">
            <div><span class="admin-dashboard-kicker">SPORTSHUB INBOX</span><h2>Messages &amp; Follow-ups</h2></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a class="button button-secondary" href="{{ route('admin.messages.create', ['category' => 'general']) }}">Compose Message</a>
                <a class="button" href="{{ route('admin.messages.create', ['category' => 'medical_follow_up']) }}">Send Medical Follow-up</a>
            </div>
        </div>
        <nav class="message-filter-tabs" aria-label="Message filters">
            <a href="{{ route('admin.messages', ['tab' => 'all']) }}" class="{{ $messageTab === 'all' ? 'is-active' : '' }}">All</a>
            <a href="{{ route('admin.messages', ['tab' => 'general']) }}" class="{{ $messageTab === 'general' ? 'is-active' : '' }}">General Messages</a>
            <a href="{{ route('admin.messages', ['tab' => 'medical']) }}" class="{{ $messageTab === 'medical' ? 'is-active' : '' }}">Medical Follow-ups</a>
            <a href="{{ route('admin.messages', ['tab' => 'sent']) }}" class="{{ $messageTab === 'sent' ? 'is-active' : '' }}">Sent</a>
            <a href="{{ route('admin.messages', ['tab' => 'drafts']) }}" class="{{ $messageTab === 'drafts' ? 'is-active' : '' }}">Drafts</a>
        </nav>
        <form method="GET" class="filters message-search-form">
            <input type="hidden" name="tab" value="{{ $messageTab }}">
            <input class="form-control" type="search" name="search" value="{{ request('search') }}" placeholder="Search subject, message or athlete">
            <select class="form-control" name="sort"><option value="newest" @selected(request('sort') !== 'oldest')>Newest first</option><option value="oldest" @selected(request('sort') === 'oldest')>Oldest first</option></select>
            <button class="button button-secondary" type="submit">Apply</button>
        </form>
        @if ($messageTab === 'medical')
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Follow-up Subject</th><th>Follow-up Type</th><th>Athlete / Group</th><th>Assigned Sport</th><th>Follow-up Date</th><th>Read</th><th>Acknowledged</th><th>Completed</th><th>Overdue</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($followUps as $followUp)
                            @php
                                $recipientNames = $followUp->recipients->pluck('recipient.name')->filter()->implode(', ');
                                $overdueCount = $followUp->recipients->filter(fn ($recipient) => $followUp->isOverdue() && ! $recipient->isCompleted())->count();
                            @endphp
                            <tr>
                                <td><strong>{{ $followUp->message?->subject ?: 'Untitled follow-up' }}</strong></td>
                                <td>{{ $followUp->follow_up_type }}</td>
                                <td>{{ $recipientNames ?: '—' }}</td>
                                <td>{{ $followUp->message?->sport?->name ?? 'All sports' }}</td>
                                <td>{{ $followUp->scheduled_date?->format('M j, Y') ?? '—' }}</td>
                                <td>{{ $followUp->readCount() }}/{{ $followUp->recipientCount() }}</td>
                                <td>{{ $followUp->acknowledgedCount() }}/{{ $followUp->recipientCount() }}</td>
                                <td>{{ $followUp->completedCount() }}/{{ $followUp->recipientCount() }}</td>
                                <td>{{ $overdueCount }}</td>
                                <td><span class="badge follow-up-status follow-up-status-{{ strtolower($followUp->effectiveStatus()) }}">{{ $followUp->effectiveStatus() }}</span></td>
                                <td>
                                    @if ($followUp->message)
                                        <a class="text-link" href="{{ route('admin.messages.show', $followUp->message) }}">View Details</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="11">No medical follow-ups match this view yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $followUps->links() }}
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Subject</th><th>Category</th><th>Recipient Type</th><th>Recipients</th><th>Date Sent</th><th>Read</th><th>Unread</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($messages as $row)
                            <tr>
                                <td><strong>{{ $row->subject ?: 'Untitled draft' }}</strong>@if ($row->event)<br><small class="meta">{{ $row->event->title }}</small>@endif</td>
                                <td><span class="badge {{ $row->category === 'medical_follow_up' ? 'medical-status-pending' : '' }}">{{ \App\Models\Message::categoryName($row->category) }}</span><br><small class="meta">{{ \App\Models\Message::categoryLabel($row->message_type) }}</small></td>
                                <td>{{ ucfirst($row->recipient_type) }}@if ($row->sport) · {{ $row->sport->name }}@endif</td>
                                <td>{{ $row->recipients_count ?: ($row->status === 'draft' ? 'Not sent' : 0) }}</td>
                                <td>{{ $row->sent_at?->format('M j, Y g:i A') ?? '—' }}</td>
                                <td>{{ $row->status === 'sent' ? $row->read_recipients_count : '—' }}</td>
                                <td>{{ $row->status === 'sent' ? $row->recipients_count - $row->read_recipients_count : '—' }}</td>
                                <td><span class="badge {{ $row->status === 'sent' ? 'status-approved' : 'status-pending' }}">{{ ucfirst($row->status) }}</span></td>
                                <td>
                                    @if ($row->status === 'sent')
                                        <a class="text-link" href="{{ route('admin.messages.show', $row) }}">View Details</a>
                                    @else
                                        <a class="text-link" href="{{ route('admin.messages.edit', $row) }}">Edit Draft</a>
                                        <button class="row-action row-action-danger message-delete-form" type="button" data-confirm-dialog data-confirm-title="Delete Draft?" data-confirm-message="This draft will be permanently deleted. No athletes have received it." data-confirm-label="Delete Draft" data-confirm-icon="warning" data-confirm-variant="danger" data-confirm-method="DELETE" data-confirm-url="{{ route('admin.messages.destroy', $row) }}">Delete</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="9">No messages match this view yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $messages->links() }}
        @endif
    </section>
@endif
