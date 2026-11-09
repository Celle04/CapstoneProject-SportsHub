<section class="student-application-page">
    <header class="student-application-heading">
        <div><span>SPORTSHUB MEMBERSHIP</span><h2>My application</h2><p>Application details and the latest administrator review.</p></div>
        <svg aria-hidden="true"><use href="#icon-clipboard"></use></svg>
    </header>

    @if ($studentApplication)
        @php($applicationStatusClass = 'application-status-'.strtolower(str_replace(' ', '-', $studentApplication->status)))
        <section class="card student-application-overview">
            <div class="student-application-status-row">
                <div><span class="student-application-label">APPLICATION STATUS</span><span class="badge {{ $applicationStatusClass }}">{{ $studentApplication->status }}</span></div>
                <div class="student-application-submitted"><span>SUBMITTED</span><strong>{{ $studentApplication->created_at?->format('M j, Y') ?? 'Date not recorded' }}</strong></div>
            </div>
            <div class="student-application-details">
                <div><span>Reference number</span><strong>{{ $studentApplication->reference_number ?? 'Not assigned' }}</strong></div>
                <div><span>Applicant</span><strong>{{ $studentApplication->name }}</strong></div>
                <div><span>Student ID</span><strong>{{ $studentApplication->student_id ?? 'Not recorded' }}</strong></div>
                <div><span>Sport</span><strong>{{ $studentApplication->sportCategory?->name ?? $studentApplication->sport ?? 'Not assigned' }}</strong></div>
                <div><span>Grade</span><strong>{{ $studentApplication->grade ?: 'Not recorded' }}</strong></div>
                <div><span>Last updated</span><strong>{{ ($studentApplication->reviewed_at ?? $studentApplication->updated_at)?->format('M j, Y g:i A') ?? 'Not recorded' }}</strong></div>
            </div>
            @if ($studentApplication->status === 'Requires Revision')
                <p class="form-hint" style="margin-top:.75rem;">Your application needs corrections or missing information. Please review the instructions below and follow the office guidance if provided.</p>
            @elseif ($studentApplication->status === 'Approved')
                <p class="form-hint" style="margin-top:.75rem;">Your application was approved. Follow the instructions below for accessing your athlete account or visiting the SNNHS Sports Office if required.</p>
            @elseif ($studentApplication->status === 'Rejected')
                <p class="form-hint" style="margin-top:.75rem;">Your application was not approved. Please review the explanation and instructions below.</p>
            @endif
        </section>

        @if ($studentApplication->review_notes)
            <section class="student-application-feedback">
                <span>{{ $studentApplication->status === 'Requires Revision' ? 'REVISION INSTRUCTIONS' : 'ADMINISTRATOR FEEDBACK' }}</span>
                <p>{{ $studentApplication->review_notes }}</p>
            </section>
        @endif
        @if ($studentApplication->rejection_reason)
            <section class="student-application-rejection">
                <strong>Explanation</strong>
                <p>{{ $studentApplication->rejection_reason }}</p>
            </section>
        @endif
        @if (filled($studentApplication->admin_instructions))
            <section class="student-application-feedback">
                <span>OFFICE INSTRUCTIONS</span>
                <p>{{ $studentApplication->admin_instructions }}</p>
            </section>
        @endif
    @else
        <div class="student-application-empty">
            <span aria-hidden="true"><svg><use href="#icon-clipboard"></use></svg></span>
            <strong>No application linked</strong>
            <p>No sports application is linked to your account yet.</p>
        </div>
    @endif
</section>
