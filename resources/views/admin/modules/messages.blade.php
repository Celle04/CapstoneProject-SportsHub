@php
    $messageView = $messageView ?? 'index';
    $messageSummary = $messageSummary ?? ['sent' => 0, 'recipients' => 0, 'read' => 0, 'unread' => 0, 'drafts' => 0];
    $messageRecord = $messageRecord ?? new \App\Models\Message();
    $recipientType = old('recipient_type', $messageRecord->recipient_type ?: 'individual');
    $messageType = old('message_type', $messageRecord->message_type ?: 'general');
    $selectedRecipientIds = array_map('strval', old('recipient_ids', $messageRecord->recipient_ids ?? []));
    $eventRecord = $messageRecord->event ?? null;
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
                <p>Messages are delivered to recipients’ SportsHub inboxes and notification bell.</p>
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
                    <label for="message-type">Category</label>
                    <select class="form-control" id="message-type" name="message_type">
                        <option value="general" @selected($messageType === 'general')>General Message</option>
                        <option value="sports_event" @selected($messageType === 'sports_event')>Sports Event</option>
                        <option value="competition" @selected($messageType === 'competition')>Competition</option>
                        <option value="event_update" @selected($messageType === 'event_update')>Event Update</option>
                        <option value="medical_reminder" @selected($messageType === 'medical_reminder')>Medical Checkup Reminder</option>
                    </select>
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
                        @foreach (['individual' => 'One athlete', 'all' => 'All active athletes', 'sport' => 'Athletes from a selected sport', 'team' => 'Athletes from a selected team', 'selected' => 'Individually selected athletes'] as $value => $label)
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

            <div class="form-group message-recipient-panel" data-recipient-panel="sport team" hidden>
                <label for="message-sport">Sport / Team</label>
                <select class="form-control" id="message-sport" name="sport_id">
                    <option value="">Select a sport or team</option>
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
                    <label for="checkup-date">Checkup Date</label>
                    <input class="form-control @error('checkup_date') is-invalid @enderror" type="date" id="checkup-date" name="checkup_date" value="{{ old('checkup_date', $messageRecord->checkup_date?->format('Y-m-d')) }}">
                    @error('checkup_date')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <label for="checkup-time">Checkup Time</label>
                    <input class="form-control @error('checkup_time') is-invalid @enderror" type="time" id="checkup-time" name="checkup_time" value="{{ old('checkup_time', $messageRecord->checkup_time) }}">
                    @error('checkup_time')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <label for="checkup-location">Location</label>
                    <input class="form-control @error('location') is-invalid @enderror" type="text" id="checkup-location" name="location" maxlength="255" value="{{ old('location', $messageRecord->location) }}" placeholder="e.g. School Medical Clinic">
                    @error('location')<small class="form-error">{{ $message }}</small>@enderror
                </div>
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
    <section class="card message-detail-card">
        @php($detailCategory = \App\Models\Message::categoryLabel($messageRecord->message_type))
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
        @if ($messageRecord->message_type === 'medical_reminder')
            <div class="message-reminder-schedule">
                <strong>Checkup schedule</strong>
                <span>{{ $messageRecord->checkup_date?->format('F j, Y') ?? 'Date not specified' }}@if ($messageRecord->checkup_time) · {{ \Illuminate\Support\Carbon::parse($messageRecord->checkup_time)->format('g:i A') }}@endif</span>
                @if ($messageRecord->location)<span>{{ $messageRecord->location }}</span>@endif
            </div>
        @endif
        <div class="message-content">{{ $messageRecord->content }}</div>
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
    </section>
@else
    <div class="grid message-summary-grid">
        <div class="card stat"><small>Total Messages Sent</small><strong>{{ $messageSummary['sent'] }}</strong></div>
        <div class="card stat"><small>Total Recipients</small><strong>{{ $messageSummary['recipients'] }}</strong></div>
        <div class="card stat"><small>Read Messages</small><strong>{{ $messageSummary['read'] }}</strong></div>
        <div class="card stat"><small>Unread Messages</small><strong>{{ $messageSummary['unread'] }}</strong></div>
        <div class="card stat"><small>Draft Messages</small><strong>{{ $messageSummary['drafts'] }}</strong></div>
    </div>

    <section class="card message-list-card">
        <div class="section-heading">
            <div><span class="admin-dashboard-kicker">SPORTSHUB INBOX</span><h2>Messages</h2></div>
            <a class="button" href="{{ route('admin.messages.create') }}">Compose Message</a>
        </div>
        <nav class="message-filter-tabs" aria-label="Message filters">
            <a href="{{ route('admin.messages') }}" class="{{ !request('status') && !request('filter') ? 'is-active' : '' }}">All</a>
            <a href="{{ route('admin.messages', ['status' => 'sent']) }}" class="{{ request('status') === 'sent' && !request('filter') ? 'is-active' : '' }}">Sent</a>
            <a href="{{ route('admin.messages', ['status' => 'draft']) }}" class="{{ request('status') === 'draft' ? 'is-active' : '' }}">Drafts</a>
            <a href="{{ route('admin.messages', ['filter' => 'medical']) }}" class="{{ request('filter') === 'medical' ? 'is-active' : '' }}">Medical Reminders</a>
            <a href="{{ route('admin.messages', ['filter' => 'event']) }}" class="{{ request('filter') === 'event' ? 'is-active' : '' }}">Event Notifications</a>
        </nav>
        <form method="GET" class="filters message-search-form">
            <input class="form-control" type="search" name="search" value="{{ request('search') }}" placeholder="Search subject or message">
            @if (request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
            @if (request('filter'))<input type="hidden" name="filter" value="{{ request('filter') }}">@endif
            <select class="form-control" name="sort"><option value="newest" @selected(request('sort') !== 'oldest')>Newest first</option><option value="oldest" @selected(request('sort') === 'oldest')>Oldest first</option></select>
            <button class="button button-secondary" type="submit">Apply</button>
        </form>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Subject</th><th>Category</th><th>Associated Event</th><th>Recipients</th><th>Read</th><th>Unread</th><th>Date Sent</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse ($messages as $row)
                        <tr>
                            <td><strong>{{ $row->subject ?: 'Untitled draft' }}</strong><br><small class="meta">{{ ucfirst($row->recipient_type) }}@if ($row->sport) · {{ $row->sport->name }}@endif</small></td>
                            <td>{{ \App\Models\Message::categoryLabel($row->message_type) }}</td>
                            <td>@if ($row->event)<a class="text-link" href="{{ route('events.show', $row->event) }}">{{ $row->event->title }}</a>@else — @endif</td>
                            <td>{{ $row->recipients_count ?: ($row->status === 'draft' ? 'Not sent' : 0) }}</td>
                            <td>{{ $row->status === 'sent' ? $row->read_recipients_count : '—' }}</td>
                            <td>{{ $row->status === 'sent' ? $row->recipients_count - $row->read_recipients_count : '—' }}</td>
                            <td>{{ $row->sent_at?->format('M j, Y g:i A') ?? '—' }}</td>
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
    </section>
@endif
