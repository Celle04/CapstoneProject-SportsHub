@extends('layouts.portal')

@section('content')
    @php
        $appController = \App\Http\Controllers\ApplicationController::class;
        $documentDefinitions = $appController::documentDefinitions();
        $initials = static function (string $name): string {
            $pieces = preg_split('/\s+/', trim($name)) ?: [];

            return mb_strtoupper(implode('', array_map(
                static fn (string $word): string => mb_substr($word, 0, 1),
                array_slice($pieces, 0, 2),
            )));
        };
        $sportLabel = $application->sportCategory?->name ?? $application->sport ?: 'Unassigned';
        $statusBadge = 'application-status-' . strtolower(str_replace(' ', '-', $application->status));
        $documentStatus = static fn (string $path, string $state): string => filled($application->{$path})
            ? ($application->{$state} ?: 'Submitted')
            : 'Missing';
        $documentsVerified = collect($documentDefinitions)->every(
            fn (array $definition) => filled($application->{$definition['path']}) && $application->{$definition['status']} === 'Verified'
        );
        $requestedDocuments = collect($application->documents_requested ?? [])
            ->map(fn ($document) => $documentDefinitions[$document]['label'] ?? ucfirst((string) $document))
            ->join(', ');
        $contactNumber = $application->athlete?->phone ?: 'Not provided';
        $heroMeta = collect([
            filled($application->grade) ? e($application->grade) : null,
            $application->gender ? e($application->gender) : null,
            e($sportLabel),
            filled($application->student_id) ? 'ID '.e($application->student_id) : null,
        ])->filter()->implode(' &middot; ');
    @endphp

    <div class="module-header">
        <div><h1>Application Review</h1><p class="page-subtitle">Review applicant information and eligibility documents</p></div>
        @if ($application->status === 'Approved')
            <div class="event-header-actions">
                @if ($application->athlete)
                    <a class="button button-secondary" href="{{ route('athletes.show', $application->athlete) }}">Account Created</a>
                @else
                    <a class="button" href="{{ route('athletes.create', $application) }}">Create Athlete Account</a>
                @endif
            </div>
        @endif
    </div>

    @if (session('success'))<div class="notice" data-toast>{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="notice notice-error">{{ $errors->first() }}</div>@endif

    <section class="card application-detail-card">
        <div class="profile-hero medical-hero">
            <span class="avatar avatar--lg avatar-initials medical-hero-avatar" aria-hidden="true">{{ filled($application->name ?? '') ? $initials($application->name) : '?' }}</span>
            <div class="medical-hero-copy">
                <span class="admin-dashboard-kicker">APPLICATION RECORD</span>
                <h2>{{ $application->name }}</h2>
                <p>{!! $heroMeta !!}</p>
            </div>
            <div class="application-hero-side">
                <span class="badge {{ $statusBadge }}">{{ $application->status }}</span>
                <small>Applied {{ $application->created_at?->format('M j, Y') }}</small>
                <a class="medical-hero-back" href="{{ route('admin.applications') }}">Back to Applications</a>
            </div>
        </div>

        <div class="application-detail-body">
            <section>
                <div class="medical-card-head"><div><span class="admin-dashboard-kicker">PROFILE</span><h2>Applicant Information</h2><p>Personal details submitted by the athlete.</p></div></div>
                <div class="medical-fact-grid application-facts">
                    <div class="medical-fact"><span>First name</span><strong>{{ $application->first_name ?: $application->name }}</strong></div>
                    <div class="medical-fact"><span>Middle name</span><strong>{{ $application->middle_name ?: 'N/A' }}</strong></div>
                    <div class="medical-fact"><span>Last name</span><strong>{{ $application->last_name ?: 'Not provided' }}</strong></div>
                    <div class="medical-fact"><span>Student ID</span><strong>{{ $application->student_id ?: 'Not provided' }}</strong></div>
                    <div class="medical-fact"><span>Email address</span><strong>{{ $application->email }}</strong></div>
                    <div class="medical-fact"><span>Contact number</span><strong>{{ $contactNumber }}</strong></div>
                    <div class="medical-fact"><span>Grade</span><strong>{{ $application->grade ?: 'Not provided' }}</strong></div>
                    <div class="medical-fact"><span>Gender</span><strong>{{ $application->gender ?: 'Not provided' }}</strong></div>
                </div>
            </section>

            <section>
                <div class="medical-card-head"><div><span class="admin-dashboard-kicker">APPLICATION</span><h2>Application Information</h2><p>What the athlete applied for and where it stands.</p></div></div>
                <div class="medical-fact-grid application-facts">
                    <div class="medical-fact"><span>Reference number</span><strong><code>{{ $application->reference_number ?: 'Not assigned' }}</code></strong></div>
                    <div class="medical-fact"><span>Sport</span><strong>{{ $sportLabel }}</strong></div>
                    <div class="medical-fact"><span>Date applied</span><strong>{{ $application->created_at?->format('F j, Y g:i A') ?: 'Not provided' }}</strong></div>
                    <div class="medical-fact"><span>Current status</span><strong>{{ $application->status }}</strong></div>
                    <div class="medical-fact"><span>Last updated</span><strong>{{ ($application->reviewed_at ?? $application->updated_at)?->format('F j, Y g:i A') ?: 'Not provided' }}</strong></div>
                    <div class="medical-fact"><span>Application ID</span><strong>#{{ $application->id }}</strong></div>
                    <div class="medical-fact"><span>Revision/documents requested</span><strong>{{ $requestedDocuments ?: 'None' }}</strong></div>
                    <div class="medical-fact"><span>Athlete profile</span><strong>{{ $application->athlete?->name ?? ($matchingAthlete?->name ?? 'Not created yet') }}</strong></div>
                </div>
            </section>

            <section>
                <div class="medical-card-head"><div><span class="admin-dashboard-kicker">REVIEW</span><h2>Review</h2><p>Decision recorded by the administration.</p></div></div>
                <div class="medical-fact-grid application-facts">
                    <div class="medical-fact"><span>Status</span><strong><span class="badge {{ $statusBadge }}">{{ $application->status }}</span></strong></div>
                    <div class="medical-fact"><span>Review date</span><strong>{{ $application->reviewed_at?->format('M j, Y g:i A') ?: 'Not reviewed yet' }}</strong></div>
                    <div class="medical-fact"><span>Reviewer</span><strong>{{ $application->reviewer?->name ?? 'Not assigned yet' }}</strong></div>
                </div>
                @if (filled($application->review_notes))
                    <div class="review-note"><strong>Review notes / revision explanation</strong><p>{{ $application->review_notes }}</p></div>
                @else
                    <div class="review-note"><strong>Review notes</strong><p class="meta">No review notes recorded yet.</p></div>
                @endif
                @if (filled($application->rejection_reason))
                    <div class="review-note"><strong>Rejection reason</strong><p>{{ $application->rejection_reason }}</p></div>
                @endif
                @if (filled($application->admin_instructions))
                    <div class="review-note"><strong>Instructions shown to applicant</strong><p>{{ $application->admin_instructions }}</p></div>
                @endif
            </section>

            <section>
                <div class="medical-card-head"><div><span class="admin-dashboard-kicker">DOCUMENTS</span><h2>Eligibility Documents</h2><p>Documents remain private and can only be accessed by authorized administrators.</p></div></div>
                <div class="application-doc-list">
                    @foreach ($documentDefinitions as $key => $document)
                        @php
                            $state = $documentStatus($document['path'], $document['status']);
                            $documentState = match ($state) {
                                'Verified' => 'is-verified',
                                'Rejected' => 'is-rejected',
                                'Submitted' => 'is-submitted',
                                'Pending' => 'is-pending',
                                default => 'is-missing',
                            };
                        @endphp
                        <div class="application-doc-row">
                            <span class="application-doc-state {{ $documentState }}"><svg aria-hidden="true"><use href="#icon-certificate"></use></svg></span>
                            <div class="application-doc-copy">
                                <strong>{{ $document['label'] }}</strong>
                                <span class="document-badge document-{{ strtolower($state) }}">{{ $state }}</span>
                                @if (($application->document_rejection_notes ?? [])[$key] ?? false)
                                    <p>{{ $application->document_rejection_notes[$key] }}</p>
                                @endif
                            </div>
                            <div class="application-doc-actions">
                                @if ($application->{$document['path']})
                                    <a class="row-action" href="{{ route('applications.documents.download', [$application, $key]) }}">View</a>
                                    @if ($state !== 'Verified')
                                        <form method="POST" action="{{ route('applications.documents.verify', [$application, $key]) }}">@csrf<button class="row-action" type="submit">Verify</button></form>
                                    @endif
                                    <form method="POST" action="{{ route('applications.documents.reject', [$application, $key]) }}" class="inline-form">@csrf<input class="form-control document-reason-input" name="reason" placeholder="Rejection reason" required><button class="row-action row-action-danger" type="submit">Reject</button></form>
                                @else
                                    <span class="meta">Not uploaded yet</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="application-review-columns">
                <div>
                    <div class="medical-card-head"><div><span class="admin-dashboard-kicker">ACTIONS</span><h2>Review Actions</h2><p>Move the application forward or send it back.</p></div></div>
                    <div class="review-action-stack">
                        @if ($application->status !== 'Approved')
                            <button class="button" type="button" data-app-modal-open="app-modal-approve">Approve Application</button>
                            @unless ($documentsVerified)
                                <span class="form-hint">All eligibility documents must be verified before the application can be approved.</span>
                            @endunless
                        @endif
                        <button class="button button-secondary" type="button" data-app-modal-open="app-modal-review">Mark as Under Review</button>
                        <button class="button button-danger" type="button" data-app-modal-open="app-modal-reject">Reject Application</button>
                        <form method="POST" action="{{ route('applications.update', $application) }}" class="application-action-form" data-app-status-form>@csrf @method('PATCH')<select class="form-control" name="status" aria-label="Application status">@foreach ($appController::statuses() as $status)<option value="{{ $status }}" @selected($application->status === $status)>{{ $status }}</option>@endforeach</select><textarea class="form-control" name="review_notes" placeholder="Review notes / explanation (required for rejection or revision)" rows="3">{{ $application->review_notes }}</textarea><textarea class="form-control" name="admin_instructions" placeholder="Instructions shown to applicant (optional, e.g. visit SNNHS Sports Office)" rows="2">{{ $application->admin_instructions }}</textarea><button class="button button-secondary" type="submit">Update Status</button></form>
                    </div>
                </div>
                <div>
                    <div class="medical-card-head"><div><span class="admin-dashboard-kicker">REQUESTS</span><h2>Request Revision</h2><p>Ask the applicant to correct information or submit missing documents.</p></div></div>
                    <div class="review-action-stack">
                        <button class="button button-secondary" type="button" data-app-modal-open="app-modal-documents">Request Revision</button>
                        <span class="form-hint">Requesting revision sets the application to "Requires Revision" and requires an explanation.</span>
                        @if (($matchingAthlete ?? null) && ! $application->athlete)
                            <span class="form-hint">A matching student account exists for this student ID. Create or link the athlete account only after approval.</span>
                        @endif
                    </div>
                </div>
            </section>
        </div>
    </section>

    <section class="card application-history">
        <div class="medical-card-head"><div><span class="admin-dashboard-kicker">TIMELINE</span><h2>Application Status History</h2><p>Status decisions, reviewer, and accountability trail.</p></div></div>
        @if ($application->statusHistories->isNotEmpty())
            <div class="application-timeline">
                @foreach ($application->statusHistories as $history)
                    <div class="application-timeline-row">
                        <span class="application-timeline-dot" aria-hidden="true"></span>
                        <div class="application-timeline-copy">
                            <strong>{{ $history->status }}</strong>
                            <time>{{ $history->created_at?->format('M j, Y g:i A') }}@if($history->changedBy) · {{ $history->changedBy->name }}@endif</time>
                            @if (filled($history->notes))<p class="meta" style="margin:.2rem 0 0; white-space:pre-wrap;">{{ $history->notes }}</p>@endif
                            @if (filled($history->admin_instructions))<p class="meta" style="margin:.2rem 0 0; white-space:pre-wrap;"><strong>Instructions:</strong> {{ $history->admin_instructions }}</p>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            @php($historyEntries = array_reverse($application->review_history ?? []))
            @if ($historyEntries)
                <div class="application-timeline">
                    @foreach ($historyEntries as $history)
                        <div class="application-timeline-row">
                            <span class="application-timeline-dot" aria-hidden="true"></span>
                            <div class="application-timeline-copy">
                                <strong>{{ $history['label'] }}</strong>
                                <time>{{ \Carbon\Carbon::parse($history['at'])->format('M j, Y g:i A') }}</time>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="empty-state">No review history recorded yet.</p>
            @endif
        @endif
    </section>

    @include('applications._review-modals', ['application' => $application])
@endsection
