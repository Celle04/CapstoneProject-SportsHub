@php
    $followUpView = $followUpView ?? 'index';
    $selectedConversation = $selectedConversation ?? null;
@endphp

@if (session('success'))
    <div class="notice" role="status">{{ session('success') }}</div>
@endif
@if ($errors->any())
    <div class="notice notice-error" role="alert">{{ $errors->first() }}</div>
@endif

<section class="card" style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
    <div>
        <span class="admin-dashboard-kicker">MEDICAL</span>
        <h2 style="margin:4px 0 0;">Medical Follow-ups</h2>
        <p class="meta" style="margin:4px 0 0;">Private conversations with student-athletes. Confidential details are not shown in general notifications.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        @if ($followUpView !== 'index')
            <a class="button button-secondary" href="{{ route('admin.medical.follow-ups') }}">Back to Follow-ups</a>
        @endif
        <a class="button" href="{{ route('admin.medical.follow-ups.create') }}">New Follow-up</a>
        <a class="button button-secondary" href="{{ route('admin.medical') }}">Medical Records</a>
    </div>
</section>

@if ($followUpView === 'create')
    <section class="card">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">NEW CONVERSATION</span>
                <h2>Start a Private Medical Follow-up</h2>
            </div>
        </div>
        <form method="POST" action="{{ route('admin.medical.follow-ups.store') }}">
            @csrf
            <div class="grid grid-2">
                <div class="form-group">
                    <label for="follow-up-athlete">Athlete</label>
                    <select class="form-control @error('athlete_id') is-invalid @enderror" id="follow-up-athlete" name="athlete_id" required>
                        <option value="">Select an athlete</option>
                        @foreach ($athletes as $athlete)
                            <option value="{{ $athlete->id }}" @selected(old('athlete_id') == $athlete->id)>
                                {{ $athlete->name }}@if ($athlete->student_id) ({{ $athlete->student_id }})@endif — {{ $athlete->sport?->name ?? 'Unassigned sport' }}
                            </option>
                        @endforeach
                    </select>
                    @error('athlete_id')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <label for="follow-up-category">Category</label>
                    <select class="form-control @error('category') is-invalid @enderror" id="follow-up-category" name="category" required>
                        <option value="">Select a category</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(old('category') === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                    <small class="form-hint">You may also enter a custom subject below.</small>
                    @error('category')<small class="form-error">{{ $message }}</small>@enderror
                </div>
            </div>
            <div class="form-group">
                <label for="follow-up-subject">Subject</label>
                <input class="form-control @error('subject') is-invalid @enderror" id="follow-up-subject" name="subject" type="text" maxlength="255" value="{{ old('subject') }}" placeholder="Conversation subject" required>
                @error('subject')<small class="form-error">{{ $message }}</small>@enderror
            </div>
            <div class="form-group">
                <label for="follow-up-message">Message</label>
                <textarea class="form-control @error('message') is-invalid @enderror" id="follow-up-message" name="message" rows="6" maxlength="5000" placeholder="Ask for updates, request documents, or follow up on a check-up" required>{{ old('message') }}</textarea>
                @error('message')<small class="form-error">{{ $message }}</small>@enderror
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="button" type="submit">Start Conversation</button>
                <a class="button button-secondary" href="{{ route('admin.medical.follow-ups') }}">Cancel</a>
            </div>
        </form>
    </section>
@elseif ($followUpView === 'show' && $selectedConversation)
    <div class="grid" style="grid-template-columns:minmax(260px,320px) 1fr;gap:16px;align-items:start;">
        <section class="card">
            <div class="section-heading"><div><span class="admin-dashboard-kicker">ATHLETE</span><h2>Conversation Details</h2></div></div>
            <div class="medical-fact-grid" style="grid-template-columns:1fr;">
                <div class="medical-fact"><span>Athlete</span><strong>{{ $selectedConversation->athlete?->name ?? 'Unknown' }}</strong></div>
                <div class="medical-fact"><span>Student ID</span><strong>{{ $selectedConversation->athlete?->student_id ?: 'Not set' }}</strong></div>
                <div class="medical-fact"><span>Assigned sport</span><strong>{{ $selectedConversation->athlete?->sport?->name ?? 'Unassigned' }}</strong></div>
                <div class="medical-fact"><span>Category</span><strong>{{ $selectedConversation->category }}</strong></div>
                <div class="medical-fact"><span>Status</span><strong><span class="badge {{ $selectedConversation->statusBadgeClass() }}">{{ $selectedConversation->status }}</span></strong></div>
                <div class="medical-fact"><span>Started</span><strong>{{ $selectedConversation->created_at?->format('M j, Y g:i A') }}</strong></div>
                <div class="medical-fact"><span>Last activity</span><strong>{{ $selectedConversation->updated_at?->format('M j, Y g:i A') }}</strong></div>
            </div>
            <form method="POST" action="{{ route('admin.medical.follow-ups.status', $selectedConversation) }}" style="margin-top:16px;">
                @csrf
                @method('PATCH')
                <div class="form-group">
                    <label for="conversation-status">Update status</label>
                    <select class="form-control" id="conversation-status" name="status">
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($selectedConversation->status === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="button button-secondary" type="submit">Update Status</button>
            </form>
        </section>

        <section class="card">
            <div class="section-heading">
                <div>
                    <span class="admin-dashboard-kicker">{{ strtoupper($selectedConversation->category) }}</span>
                    <h2>{{ $selectedConversation->subject }}</h2>
                    <p class="meta">Private medical follow-up. Review attachments before adding them to official medical records.</p>
                </div>
            </div>
            <div style="display:grid;gap:12px;margin:16px 0;max-height:52vh;overflow:auto;padding-right:4px;">
                @forelse ($selectedConversation->messages as $message)
                    @php($isAdminSender = $message->sender?->role === 'Administrator')
                    <article style="padding:12px 14px;border:1px solid var(--color-border);border-radius:10px;background:{{ $isAdminSender ? 'rgba(16,6,159,.04)' : 'rgba(255,255,255,.45)' }};">
                        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
                            <strong>{{ $message->sender?->name ?? 'Unknown' }} @if($isAdminSender)<span class="badge">Admin</span>@else<span class="badge">Athlete</span>@endif</strong>
                            <small class="meta">{{ $message->created_at?->format('M j, Y g:i A') }}</small>
                        </div>
                        <div style="white-space:normal;line-height:1.5;">{!! nl2br(e($message->message)) !!}</div>
                        @if ($message->hasAttachment())
                            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                <span class="meta">Attachment: {{ $message->attachment_name }}</span>
                                <a class="text-link" href="{{ route('admin.medical.follow-ups.attachments.view', [$selectedConversation, $message]) }}">View</a>
                                <a class="text-link" href="{{ route('admin.medical.follow-ups.attachments.download', [$selectedConversation, $message]) }}">Download</a>
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="empty-state">No messages yet.</p>
                @endforelse
            </div>

            @if ($selectedConversation->status !== 'Resolved')
                <form method="POST" action="{{ route('admin.medical.follow-ups.reply', $selectedConversation) }}">
                    @csrf
                    <div class="form-group">
                        <label for="admin-reply">Reply to athlete</label>
                        <textarea class="form-control @error('message') is-invalid @enderror" id="admin-reply" name="message" rows="4" maxlength="5000" placeholder="Send a follow-up, ask for an update, or request missing documents" required>{{ old('message') }}</textarea>
                        @error('message')<small class="form-error">{{ $message }}</small>@enderror
                    </div>
                    <button class="button" type="submit">Send Reply</button>
                </form>
            @else
                <div class="notice">This conversation is resolved. Change the status if you need to continue.</div>
                <form method="POST" action="{{ route('admin.medical.follow-ups.reply', $selectedConversation) }}" style="margin-top:12px;">
                    @csrf
                    <input type="hidden" name="reopen" value="1">
                    <div class="form-group">
                        <label for="admin-reply-reopen">Reply and reopen as Pending</label>
                        <textarea class="form-control" id="admin-reply-reopen" name="message" rows="3" maxlength="5000" required>{{ old('message') }}</textarea>
                    </div>
                    <button class="button" type="submit">Send Reply &amp; Reopen</button>
                </form>
            @endif
        </section>
    </div>
@else
    <section class="card">
        <form method="GET" action="{{ route('admin.medical.follow-ups') }}" class="filters" style="margin-bottom:16px;">
            <input class="form-control" type="search" name="search" value="{{ request('search') }}" placeholder="Search athlete name or Student ID">
            <select class="form-control" name="status">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                @endforeach
            </select>
            <input class="form-control" type="date" name="date" value="{{ request('date') }}" aria-label="Filter by last activity date">
            <button class="button button-secondary" type="submit">Filter</button>
            <a class="button button-muted" href="{{ route('admin.medical.follow-ups') }}">Reset</a>
        </form>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Athlete</th>
                        <th>Subject</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Latest Message</th>
                        <th>Last Activity</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($conversations as $conversation)
                        @php($unread = $conversation->messages()->whereNull('read_at')->whereHas('sender', fn($q) => $q->where('role', 'Student'))->exists())
                        <tr>
                            <td>
                                <strong>{{ $conversation->athlete?->name ?? 'Unknown' }}</strong>
                                <br><small class="meta">{{ $conversation->athlete?->student_id ?: 'No Student ID' }} · {{ $conversation->athlete?->sport?->name ?? 'Unassigned' }}</small>
                            </td>
                            <td>
                                {{ $conversation->subject }}
                                @if ($unread) <span class="badge status-pending">New reply</span> @endif
                            </td>
                            <td>{{ $conversation->category }}</td>
                            <td><span class="badge {{ $conversation->statusBadgeClass() }}">{{ $conversation->status }}</span></td>
                            <td><small class="meta">{{ \Illuminate\Support\Str::limit(strip_tags((string) ($conversation->latestMessage?->message ?? '')), 80) ?: '—' }}</small></td>
                            <td>{{ $conversation->updated_at?->format('M j, Y g:i A') }}</td>
                            <td><a class="text-link" href="{{ route('admin.medical.follow-ups.show', $conversation) }}">Open</a></td>
                        </tr>
                    @empty
                        <tr><td class="empty-cell" colspan="7">No medical follow-up conversations yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($conversations->hasPages()) <nav aria-label="Follow-up pages" style="margin-top:12px;">{{ $conversations->links() }}</nav> @endif
    </section>
@endif
