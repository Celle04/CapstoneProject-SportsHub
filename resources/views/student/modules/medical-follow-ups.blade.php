@php
    $followUpView = $followUpView ?? 'index';
    $selectedConversation = $selectedConversation ?? null;
@endphp

@if ($followUpView === 'show' && $selectedConversation)
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
        <a class="button button-secondary" href="{{ route('student.medical-follow-ups') }}">Back to Medical Follow-ups</a>
    </div>
    <section class="card" style="margin-bottom:16px;">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">{{ strtoupper($selectedConversation->category) }}</span>
                <h2>{{ $selectedConversation->subject }}</h2>
                <p class="meta">Status: <span class="badge {{ $selectedConversation->statusBadgeClass() }}">{{ $selectedConversation->status }}</span> · Started {{ $selectedConversation->created_at?->format('M j, Y g:i A') }}</p>
            </div>
        </div>
        <div style="display:grid;gap:12px;margin:16px 0;max-height:52vh;overflow:auto;padding-right:4px;">
            @foreach ($selectedConversation->messages as $message)
                @php($isMine = (int) $message->sender_id === (int) auth()->id())
                <article style="padding:12px 14px;border:1px solid var(--color-border);border-radius:10px;background:{{ $isMine ? 'rgba(16,6,159,.04)' : 'rgba(255,255,255,.45)' }};">
                    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
                        <strong>{{ $isMine ? 'You' : ($message->sender?->name ?? 'Sports Office') }}</strong>
                        <small class="meta">{{ $message->created_at?->format('M j, Y g:i A') }}</small>
                    </div>
                    <div style="line-height:1.5;">{!! nl2br(e($message->message)) !!}</div>
                    @if ($message->hasAttachment())
                        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <span class="meta">Attachment: {{ $message->attachment_name }}</span>
                            <a class="text-link" href="{{ route('student.medical-follow-ups.attachments.view', [$selectedConversation, $message]) }}">View</a>
                            <a class="text-link" href="{{ route('student.medical-follow-ups.attachments.download', [$selectedConversation, $message]) }}">Download</a>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    <section class="card">
        @if ($selectedConversation->status === 'Resolved')
            <div class="notice">This medical follow-up has been marked as resolved. If you still need help, please contact the sports office.</div>
        @else
            <div class="section-heading"><div><span class="admin-dashboard-kicker">REPLY</span><h2>Respond to Follow-up</h2><p class="meta">You may reply with an update and submit a requested document (PDF/JPG/PNG). Uploaded files are private and reviewed before official records are updated.</p></div></div>
            <form method="POST" action="{{ route('student.medical-follow-ups.reply', $selectedConversation) }}" enctype="multipart/form-data">
                @csrf
                <div class="form-group">
                    <label for="student-reply">Your message</label>
                    <textarea class="form-control @error('message') is-invalid @enderror" id="student-reply" name="message" rows="5" maxlength="5000" required>{{ old('message') }}</textarea>
                    @error('message')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <label for="student-attachment">Attachment (optional)</label>
                    <input class="form-control @error('attachment') is-invalid @enderror" id="student-attachment" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
                    <small class="form-hint">Use this only for documents requested by the sports office. Official medical records are updated only after admin review.</small>
                    @error('attachment')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <button class="button" type="submit">Send Response</button>
            </form>
        @endif
    </section>
@else
    <section class="card">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">PRIVATE MESSAGES</span>
                <h2>Medical Follow-ups</h2>
                <p class="meta">Only you and authorized sports/medical staff can access these conversations.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Last activity</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($conversations as $conversation)
                        @php($unread = (int) ($conversation->unread_count ?? 0) > 0)
                        <tr>
                            <td>
                                <strong>{{ $conversation->subject }}</strong>
                                @if ($unread) <span class="badge status-pending">Unread</span> @endif
                                <br><small class="meta">{{ \Illuminate\Support\Str::limit(strip_tags((string) ($conversation->latestMessage?->message ?? '')), 90) ?: '—' }}</small>
                            </td>
                            <td>{{ $conversation->category }}</td>
                            <td><span class="badge {{ $conversation->statusBadgeClass() }}">{{ $conversation->status }}</span></td>
                            <td>{{ $conversation->updated_at?->format('M j, Y g:i A') }}</td>
                            <td><a class="text-link" href="{{ route('student.medical-follow-ups.show', $conversation) }}">Open</a></td>
                        </tr>
                    @empty
                        <tr><td class="empty-cell" colspan="5">You have no medical follow-up messages.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($conversations->hasPages()) <nav aria-label="Follow-up pages" style="margin-top:12px;">{{ $conversations->links() }}</nav> @endif
    </section>
@endif
