@php
    $emailReminderView = $emailReminderView ?? 'index';
    $summary = $summary ?? ['total' => 0, 'sent' => 0, 'failed' => 0, 'queued' => 0, 'recipients_sent' => 0];
    $reminder = $reminder ?? new \App\Models\EmailReminder(['category' => 'General Reminder', 'recipient_type' => 'all']);
    $recipientType = old('recipient_type', $reminder->recipient_type ?: 'all');
    $selectedRecipientIds = array_map('strval', old('recipient_ids', $reminder->recipient_ids ?? (old('athlete_id') ? [old('athlete_id')] : [])));
    $statusClass = fn (string $status) => match ($status) {
        'sent' => 'status-approved',
        'partial', 'queued', 'sending', 'pending' => 'status-pending',
        'failed' => 'status-rejected',
        default => '',
    };
@endphp

@if (session('success'))
    <div class="notice" role="status">{{ session('success') }}</div>
@endif
@if ($errors->any())
    <div class="notice notice-error" role="alert">{{ $errors->first('send') ?: $errors->first('retry') ?: $errors->first('test_email') ?: 'Please review the highlighted fields.' }}</div>
@endif

@if ($emailReminderView === 'compose')
    <section class="card message-compose-card email-reminder-compose-card">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">EMAIL REMINDER</span>
                <h2>Compose Reminder</h2>
                <p>Choose recipients, preview the message, and send individual emails through the configured mail provider.</p>
            </div>
            <a class="text-link" href="{{ route('admin.email-reminders') }}">Back to Email Reminders</a>
        </div>

        <form method="POST" action="{{ route('admin.email-reminders.test') }}" class="message-form" style="margin-bottom:18px; padding-bottom:18px; border-bottom:1px solid var(--color-border);">
            @csrf
            <div class="grid grid-3" style="align-items:end;">
                <div class="form-group" style="grid-column:span 2;">
                    <label for="test-email">Send a test email</label>
                    <input class="form-control @error('test_email') is-invalid @enderror" id="test-email" type="email" name="test_email" value="{{ old('test_email', auth()->user()->email) }}" placeholder="admin@example.com" maxlength="255">
                    <small class="meta">Sends a sample SportsHub reminder to verify SMTP or the configured HTTPS email provider.</small>
                    @error('test_email')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <button class="button button-secondary" type="submit">Send Test Email</button>
                </div>
            </div>
        </form>

        <form method="POST" action="{{ route('admin.email-reminders.store') }}" class="message-form" id="email-reminder-form" data-preview-url="{{ route('admin.email-reminders.preview') }}">
            @csrf
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="reminder-category">Category</label>
                    <select class="form-control @error('category') is-invalid @enderror" id="reminder-category" name="category">
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(old('category', $reminder->category) === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                    @error('category')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <label for="recipient-type">Recipients</label>
                    <select class="form-control @error('recipient_type') is-invalid @enderror" id="recipient-type" name="recipient_type">
                        <option value="all" @selected($recipientType === 'all')>All registered athletes</option>
                        <option value="sport" @selected($recipientType === 'sport')>Athletes from a selected sport</option>
                        <option value="team" @selected($recipientType === 'team')>Athletes from a selected team</option>
                        <option value="selected" @selected($recipientType === 'selected' || $recipientType === 'individual')>Individually selected athletes</option>
                    </select>
                    @error('recipient_type')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <label for="reminder-subject">Email Subject</label>
                    <input class="form-control @error('subject') is-invalid @enderror" id="reminder-subject" name="subject" maxlength="255" value="{{ old('subject', $reminder->subject) }}" placeholder="Enter the email subject">
                    @error('subject')<small class="form-error">{{ $message }}</small>@enderror
                </div>
            </div>

            <div class="notice" style="margin:0;">
                Avoid including sensitive medical details in the subject or message. Each recipient receives an individual email; addresses are not shared with other recipients.
            </div>

            <div class="form-group message-recipient-panel" data-recipient-panel="sport team" hidden>
                <label for="reminder-sport" id="reminder-sport-label">Sport / Team</label>
                <select class="form-control @error('sport_id') is-invalid @enderror" id="reminder-sport" name="sport_id">
                    <option value="">Select a sport or team</option>
                    @foreach ($sports as $sportOption)
                        <option value="{{ $sportOption->id }}" @selected((string) old('sport_id', $reminder->sport_id) === (string) $sportOption->id)>{{ $sportOption->name }}</option>
                    @endforeach
                </select>
                <small class="meta" id="reminder-sport-hint">Select an existing sports program. Athletes assigned to that sport or approved for it are included.</small>
                @error('sport_id')<small class="form-error">{{ $message }}</small>@enderror
            </div>

            <div class="message-recipient-panel" data-recipient-panel="selected" hidden>
                <div class="form-group">
                    <label for="reminder-recipient-search">Find athletes</label>
                    <input class="form-control" id="reminder-recipient-search" type="search" placeholder="Filter by athlete name or Student ID" autocomplete="off">
                </div>
                <div class="message-recipient-list">
                    @forelse ($activeStudents as $student)
                        <label class="message-recipient-option" data-recipient-option>
                            <input type="checkbox" name="recipient_ids[]" value="{{ $student->id }}" @checked(in_array((string) $student->id, $selectedRecipientIds, true))>
                            <span>{{ $student->name }}@if ($student->student_id) <small>({{ $student->student_id }})</small>@endif</span>
                            <small>{{ $student->sport?->name ?? 'Unassigned' }}</small>
                        </label>
                    @empty
                        <div class="meta" style="padding:12px;">No registered athletes with valid email addresses are available.</div>
                    @endforelse
                </div>
                @error('recipient_ids')<small class="form-error">{{ $message }}</small>@enderror
                @error('athlete_id')<small class="form-error">{{ $message }}</small>@enderror
                @error('recipients')<small class="form-error">{{ $message }}</small>@enderror
            </div>

            <div class="grid grid-3">
                <div class="form-group">
                    <label for="relevant-date">Relevant Date (optional)</label>
                    <input class="form-control @error('relevant_date') is-invalid @enderror" type="date" id="relevant-date" name="relevant_date" value="{{ old('relevant_date', $reminder->relevant_date?->format('Y-m-d')) }}">
                    @error('relevant_date')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <label for="relevant-time">Relevant Time (optional)</label>
                    <input class="form-control @error('relevant_time') is-invalid @enderror" type="time" id="relevant-time" name="relevant_time" value="{{ old('relevant_time', $reminder->relevant_time) }}">
                    @error('relevant_time')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <label>Preview</label>
                    <button class="button button-secondary" type="button" id="email-reminder-preview">Preview Email</button>
                </div>
            </div>

            <div class="form-group">
                <label for="reminder-message">Message</label>
                <textarea class="form-control @error('message') is-invalid @enderror" id="reminder-message" name="message" rows="9" maxlength="20000" placeholder="Write the reminder message for athletes">{{ old('message', $reminder->message) }}</textarea>
                @error('message')<small class="form-error">{{ $message }}</small>@enderror
            </div>

            <div class="message-form-actions">
                <a class="button button-muted" href="{{ route('admin.email-reminders') }}">Cancel</a>
                <button class="button" type="submit">Send Reminder</button>
            </div>
        </form>
    </section>
    <script src="{{ asset('js/email-reminders.js') }}?v={{ filemtime(public_path('js/email-reminders.js')) }}" defer></script>
@elseif ($emailReminderView === 'show' && isset($reminder))
    <section class="card message-detail-card">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">EMAIL REMINDER HISTORY</span>
                <h2>{{ $reminder->subject }}</h2>
                <p>{{ $reminder->category }} · Created {{ $reminder->created_at?->format('M j, Y g:i A') }} by {{ $reminder->admin?->name ?? 'Administrator' }}</p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                @if (($reminder->failed_recipients_count ?? $reminder->failed_count) > 0)
                    <form method="POST" action="{{ route('admin.email-reminders.retry', $reminder) }}">
                        @csrf
                        <button class="button button-secondary" type="submit">Retry Failed</button>
                    </form>
                @endif
                <a class="text-link" href="{{ route('admin.email-reminders') }}">Back to History</a>
            </div>
        </div>

        <div class="message-detail-metadata">
            <span><strong>Status</strong><span class="badge {{ $statusClass($reminder->status) }}">{{ ucfirst($reminder->status) }}</span></span>
            <span><strong>Recipients</strong>{{ $reminder->recipients_count ?? $reminder->recipient_count }}</span>
            <span><strong>Sent</strong>{{ $reminder->sent_recipients_count ?? $reminder->sent_count }}</span>
            <span><strong>Failed</strong>{{ $reminder->failed_recipients_count ?? $reminder->failed_count }}</span>
            <span><strong>Pending/Queued</strong>{{ $reminder->pending_recipients_count ?? max(0, $reminder->recipient_count - $reminder->sent_count - $reminder->failed_count) }}</span>
            <span><strong>Recipient Type</strong>{{ $reminder->recipientTypeLabel() }}</span>
        </div>

        @if ($reminder->relevant_date || $reminder->relevant_time)
            <div class="message-reminder-schedule">
                <strong>Relevant schedule</strong>
                <span>{{ $reminder->relevant_date?->format('F j, Y') ?? 'Date not specified' }}@if ($reminder->relevant_time) · {{ \Illuminate\Support\Carbon::parse($reminder->relevant_time)->format('g:i A') }}@endif</span>
            </div>
        @endif

        <div class="message-content">{{ $reminder->message }}</div>

        <h3>Recipient Delivery Details</h3>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Athlete</th><th>Student ID</th><th>Sport</th><th>Email</th><th>Status</th><th>Sent At</th><th>Error</th></tr></thead>
                <tbody>
                    @forelse ($recipientRows as $delivery)
                        <tr>
                            <td>{{ $delivery->athlete?->name ?? 'Account removed' }}</td>
                            <td>{{ $delivery->athlete?->student_id ?: '—' }}</td>
                            <td>{{ $delivery->athlete?->sport?->name ?? 'Unassigned' }}</td>
                            <td>{{ $delivery->email }}</td>
                            <td><span class="badge {{ $statusClass($delivery->status) }}">{{ ucfirst($delivery->status) }}</span></td>
                            <td>{{ $delivery->sent_at?->format('M j, Y g:i A') ?? '—' }}</td>
                            <td style="max-width:320px; white-space:normal;">{{ $delivery->error_message ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td class="empty-cell" colspan="7">No recipient records found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $recipientRows->links() }}
    </section>
@else
    <div class="grid message-summary-grid email-reminder-summary-grid">
        <div class="card stat"><small>Total Reminders</small><strong>{{ $summary['total'] }}</strong></div>
        <div class="card stat"><small>Sent / Partial</small><strong>{{ $summary['sent'] }}</strong></div>
        <div class="card stat"><small>Emails Delivered</small><strong>{{ $summary['recipients_sent'] }}</strong></div>
        <div class="card stat"><small>Queued / Sending</small><strong>{{ $summary['queued'] }}</strong></div>
        <div class="card stat"><small>Failed</small><strong>{{ $summary['failed'] }}</strong></div>
    </div>

    <section class="card message-list-card">
        <div class="section-heading">
            <div><span class="admin-dashboard-kicker">SPORTSHUB EMAIL</span><h2>Email Reminder History</h2></div>
            <a class="button" href="{{ route('admin.email-reminders.create') }}">Compose Reminder</a>
        </div>
        <form method="GET" class="filters message-search-form">
            <input class="form-control" type="search" name="search" value="{{ request('search') }}" placeholder="Search subject, message, or category">
            <select class="form-control" name="category">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                @endforeach
            </select>
            <select class="form-control" name="status">
                <option value="">All statuses</option>
                @foreach (['queued', 'sending', 'sent', 'partial', 'failed'] as $statusOption)
                    <option value="{{ $statusOption }}" @selected(request('status') === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
            <button class="button button-secondary" type="submit">Apply</button>
        </form>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Subject</th><th>Category</th><th>Recipients</th><th>Sent</th><th>Failed</th><th>Status</th><th>Queued</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse ($reminders as $row)
                        <tr>
                            <td><strong>{{ $row->subject }}</strong><br><small class="meta">{{ $row->recipientTypeLabel() }}</small></td>
                            <td>{{ $row->category }}</td>
                            <td>{{ $row->recipients_count ?? $row->recipient_count }}</td>
                            <td>{{ $row->sent_recipients_count ?? $row->sent_count }}</td>
                            <td>{{ $row->failed_recipients_count ?? $row->failed_count }}</td>
                            <td><span class="badge {{ $statusClass($row->status) }}">{{ ucfirst($row->status) }}</span></td>
                            <td>{{ ($row->queued_at ?? $row->created_at)?->format('M j, Y g:i A') ?? '—' }}</td>
                            <td><a class="text-link" href="{{ route('admin.email-reminders.show', $row) }}">View Details</a></td>
                        </tr>
                    @empty
                        <tr><td class="empty-cell" colspan="8">No email reminders have been sent yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $reminders->links() }}
    </section>
@endif
