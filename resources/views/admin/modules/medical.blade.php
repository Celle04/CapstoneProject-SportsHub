@php
    $medicalSummary = $medicalSummary ?? [
        'total' => 0,
        'cleared' => 0,
        'evaluation' => 0,
        'pending' => 0,
        'restricted' => 0,
        'expired' => 0,
        'expiring_soon' => 0,
        'under_recovery' => 0,
    ];
    $medicalFormMode = $medicalFormMode ?? 'create';
    $medicalFormRecord = $medicalFormRecord ?? null;
    $medicalStatuses = $medicalStatuses ?? ['For Evaluation', 'Cleared', 'Restricted Participation', 'Expired'];
    $medicalCertificateStates = $medicalCertificateStates ?? ['Valid', 'Expiring Soon', 'Expired', 'Missing'];
    $medicalSeverities = $medicalSeverities ?? ['Mild', 'Moderate', 'Severe'];
    $medicalClearances = $medicalClearances ?? ['Pending', 'Cleared', 'Not Cleared'];
    $medicalViewMode = $medicalViewMode ?? 'index';
    $selectedMedicalRecord = $selectedMedicalRecord ?? null;
    $medicalIncidents = $medicalIncidents ?? collect();
    $medicalIncidentFormMode = $medicalIncidentFormMode ?? 'create';
    $medicalIncidentFormRecord = $medicalIncidentFormRecord ?? null;
    $medicalProfileDocuments = $medicalProfileDocuments ?? collect();
    $expiringCertificates = $expiringCertificates ?? collect();
    $upcomingCheckups = $upcomingCheckups ?? collect();
    $overdueCheckups = $overdueCheckups ?? collect();
    $medicalExpiringDays = $medicalExpiringDays ?? 30;
    $recordCount = $medicalRecordCount ?? count($medicalRecords ?? []);
    $sportChartMax = max(1, (int) (($medicalAthletesBySport ?? collect())->max('total') ?? 0));

    $initials = static function (string $name): string {
        $pieces = preg_split('/\s+/', trim($name)) ?: [];

        return mb_strtoupper(implode('', array_map(
            static fn (string $word): string => mb_substr($word, 0, 1),
            array_slice($pieces, 0, 2),
        )));
    };
@endphp

@php
    $medicalKpis = [
        ['label' => 'Total Medical Records', 'value' => $medicalSummary['total'], 'icon' => 'clipboard', 'tone' => 'red', 'hint' => 'All active examination records'],
        ['label' => 'Cleared Athletes', 'value' => $medicalSummary['cleared'], 'icon' => 'medical', 'tone' => 'green', 'hint' => 'Valid clearance on record'],
        ['label' => 'For Evaluation', 'value' => $medicalSummary['evaluation'], 'icon' => 'calendar', 'tone' => 'gold', 'hint' => 'Further assessment required'],
        ['label' => 'Restricted Participation', 'value' => $medicalSummary['restricted'], 'icon' => 'users', 'tone' => 'violet', 'hint' => 'Not fully cleared'],
        ['label' => 'Expired Clearances', 'value' => $medicalSummary['expired'], 'icon' => 'certificate', 'tone' => 'danger', 'hint' => 'Renewal required'],
        ['label' => 'Under Recovery', 'value' => $medicalSummary['under_recovery'], 'icon' => 'activity', 'tone' => 'amber', 'hint' => 'Active injury recovery'],
    ];
@endphp

<div class="admin-dashboard medical-page">
    <section class="dashboard-overview medical-overview">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">AT A GLANCE</span>
                <h2>Medical Overview</h2>
                <p>Clearance, certificates and recovery status across every athlete record</p>
            </div>
            <span class="dashboard-today"><svg aria-hidden="true"><use href="#icon-certificate"></use></svg> {{ $medicalExpiringDays }}-day expiry window</span>
        </div>
        <div class="grid dashboard-kpis medical-kpis">
            @foreach ($medicalKpis as $kpi)
                <div class="card stat medical-kpi">
                    <span class="kpi-body">
                        <small>{{ $kpi['label'] }}</small>
                        <strong>{{ $kpi['value'] }}</strong>
                        <span class="kpi-action">{{ $kpi['hint'] }}</span>
                    </span>
                    <span class="medical-kpi-icon tone-{{ $kpi['tone'] }}"><svg aria-hidden="true"><use href="#icon-{{ $kpi['icon'] }}"></use></svg></span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="grid medical-dashboard-insights" aria-label="Medical record statistics">
        <article class="card medical-chart-card">
            <div class="medical-card-head">
                <div><span class="admin-dashboard-kicker">CLEARANCE OVERVIEW</span><h2>Clearance Status Distribution</h2></div>
            </div>
            <div class="medical-chart-bars">
                @foreach (($medicalStatusDistribution ?? collect()) as $status => $total)
                    @php $percentage = $medicalSummary['athletes_with_records'] ? round($total / $medicalSummary['athletes_with_records'] * 100) : 0; @endphp
                    <div class="medical-chart-row">
                        <div class="medical-chart-label"><span>{{ $status }}</span><strong>{{ $total }}</strong></div>
                        <div class="medical-chart-track" role="img" aria-label="{{ $status }}: {{ $total }} records ({{ $percentage }}%)">
                            <span class="medical-chart-bar medical-chart-{{ \Illuminate\Support\Str::slug($status) }}" style="width: {{ $percentage }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>
        </article>
        <article class="card medical-chart-card">
            <div class="medical-card-head">
                <div><span class="admin-dashboard-kicker">SPORT COVERAGE</span><h2>Athletes with Medical Records</h2></div>
            </div>
            <div class="medical-chart-bars">
                @forelse (($medicalAthletesBySport ?? collect()) as $sportCoverage)
                    @php $sportPercent = min(100, round($sportCoverage->total / $sportChartMax * 100)); @endphp
                    <div class="medical-chart-row">
                        <div class="medical-chart-label"><span>{{ $sportCoverage->sport_name }}</span><strong>{{ $sportCoverage->total }}</strong></div>
                        <div class="medical-chart-track" role="img" aria-label="{{ $sportCoverage->sport_name }}: {{ $sportCoverage->total }} athletes">
                            <span class="medical-chart-bar medical-chart-sport" style="width: {{ $sportPercent }}%"></span>
                        </div>
                    </div>
                @empty
                    <p class="empty-state">No sport coverage to display yet.</p>
                @endforelse
            </div>
        </article>
        <article class="card medical-chart-card medical-recent-card">
            <div class="medical-card-head">
                <div><span class="admin-dashboard-kicker">RECENT ACTIVITY</span><h2>Recent Medical Record Updates</h2></div>
            </div>
            <div class="medical-recent-list">
                @forelse (($recentMedicalUpdates ?? collect()) as $recentRecord)
                    <a class="medical-recent-row" href="{{ route('admin.medical.show', $recentRecord) }}">
                        <span class="medical-recent-copy">
                            <strong>{{ $recentRecord->athlete?->name ?? 'Unknown athlete' }}</strong>
                            <small>{{ $recentRecord->effectiveStatus() }} &middot; {{ $recentRecord->updated_at?->format('M j, Y g:i A') }}</small>
                        </span>
                        <svg aria-hidden="true"><use href="#icon-chevron-right"></use></svg>
                    </a>
                @empty
                    <p class="empty-state">No medical record updates yet.</p>
                @endforelse
            </div>
        </article>
    </section>

@if (!empty($medicalAlerts))
    <section class="card dashboard-attention">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">ACTION REQUIRED</span>
                <h2>Needs Your Attention</h2>
            </div>
            <span class="badge status-pending">{{ count($medicalAlerts) }} alert{{ count($medicalAlerts) === 1 ? '' : 's' }}</span>
        </div>
        <div class="dashboard-attention-items">
            @foreach ($medicalAlerts as $alert)
                <div class="attention-item attention-{{ $alert['type'] === 'danger' ? 'danger' : 'warning' }}">
                    <span><svg aria-hidden="true"><use href="#icon-{{ $alert['type'] === 'danger' ? 'medical' : 'bell' }}"></use></svg></span>
                    <span class="attention-body">
                        <strong>{{ $alert['title'] }}</strong>
                        <p>{{ $alert['message'] }}</p>
                    </span>
                </div>
            @endforeach
        </div>
    </section>
@endif

@if ($medicalViewMode === 'show' && $selectedMedicalRecord)
    <section class="card medical-detail-card">
        @if (session('success'))
            <div class="notice" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->has('checkup_reminder'))
            <div class="notice notice-error" role="alert">{{ $errors->first('checkup_reminder') }}</div>
        @endif
        <div class="profile-hero medical-hero">
            <span class="avatar avatar--lg avatar-initials medical-hero-avatar" aria-hidden="true">{{ filled($selectedMedicalRecord->athlete?->name ?? '') ? $initials($selectedMedicalRecord->athlete->name) : '?' }}</span>
            <div class="medical-hero-copy">
                <span class="admin-dashboard-kicker">ATHLETE MEDICAL PROFILE</span>
                <h2>{{ $selectedMedicalRecord->athlete?->name ?? 'Unknown athlete' }}</h2>
                <p>
                    {{ $selectedMedicalRecord->sportName() }}
                    @if ($selectedMedicalRecord->athlete?->student_id) &middot; {{ $selectedMedicalRecord->athlete->student_id }} @endif
                    &middot; Last examined {{ $selectedMedicalRecord->examinationDateLabel() }}
                    @if ($selectedMedicalRecord->isArchived()) &middot; <span class="badge">Archived</span> @endif
                </p>
            </div>
            <a class="medical-hero-back" href="{{ route('admin.medical') }}">Back to list</a>
        </div>

        <div class="medical-fact-grid">
            <div class="medical-fact medical-clearance-highlight"><span>Medical Clearance</span><strong><span class="{{ $selectedMedicalRecord->statusBadgeClass() }}">{{ $selectedMedicalRecord->effectiveStatus() }}</span></strong><small>{{ $selectedMedicalRecord->statusDescription() }}</small></div>
            <div class="medical-fact"><span>Examination Date</span><strong>{{ $selectedMedicalRecord->examinationDateLabel() }}</strong></div>
            <div class="medical-fact"><span>Next Checkup</span><strong>{{ $selectedMedicalRecord->nextCheckupLabel() }}</strong></div>
            <div class="medical-fact"><span>Clearance Date</span><strong>{{ $selectedMedicalRecord->clearance_date?->format('M j, Y') ?? 'Not recorded' }}</strong></div>
            <div class="medical-fact"><span>Expiration Date</span><strong>{{ $selectedMedicalRecord->expiration_date?->format('M j, Y') ?? $selectedMedicalRecord->nextCheckupLabel() }}</strong></div>
            <div class="medical-fact"><span>Examining Personnel</span><strong>{{ $selectedMedicalRecord->examining_personnel ?: 'Not recorded' }}</strong></div>
        </div>

        @can('view-medical-details')
            <div class="medical-profile-sections">
                <section class="medical-profile-section">
                    <h3>Athlete Information</h3>
                    <dl class="medical-profile-grid">
                        <div><dt>Athlete</dt><dd>{{ $selectedMedicalRecord->athlete?->name ?? 'Unknown athlete' }}</dd></div>
                        <div><dt>Student ID</dt><dd>{{ $selectedMedicalRecord->athlete?->student_id ?: 'Not recorded' }}</dd></div>
                        <div><dt>Assigned Sport</dt><dd>{{ $selectedMedicalRecord->sportName() }}</dd></div>
                        <div><dt>Email</dt><dd>{{ $selectedMedicalRecord->athlete?->email ?: 'Not recorded' }}</dd></div>
                    </dl>
                </section>
                <section class="medical-profile-section">
                    <h3>Physical Information</h3>
                    <dl class="medical-profile-grid">
                        <div><dt>Height</dt><dd>{{ $selectedMedicalRecord->height_cm ? $selectedMedicalRecord->height_cm.' cm' : 'Not reported' }}</dd></div>
                        <div><dt>Weight</dt><dd>{{ $selectedMedicalRecord->weight_kg ? $selectedMedicalRecord->weight_kg.' kg' : 'Not reported' }}</dd></div>
                        <div><dt>Blood Type</dt><dd>{{ $selectedMedicalRecord->blood_type ?: 'Unknown / not reported' }}</dd></div>
                    </dl>
                </section>
                <section class="medical-profile-section">
                    <h3>Allergies, Conditions and Medications</h3>
                    <dl class="medical-profile-grid">
                        <div><dt>Allergies</dt><dd>{{ $selectedMedicalRecord->allergies ?: 'Unknown / not reported' }}</dd></div>
                        <div><dt>Known Conditions</dt><dd>{{ $selectedMedicalRecord->medical_conditions ?: 'Unknown / not reported' }}</dd></div>
                        <div><dt>Current Medications</dt><dd>{{ $selectedMedicalRecord->current_medications ?: 'Unknown / not reported' }}</dd></div>
                    </dl>
                </section>
                <section class="medical-profile-section">
                    <h3>Medical History</h3>
                    <dl class="medical-profile-grid">
                        <div><dt>Previous Injuries</dt><dd>{{ $selectedMedicalRecord->previous_injuries ?: 'Not reported' }}</dd></div>
                        <div><dt>Relevant History</dt><dd>{{ $selectedMedicalRecord->medical_history ?: 'Not reported' }}</dd></div>
                        <div><dt>Additional Notes</dt><dd>{{ $selectedMedicalRecord->notes ?: 'Not reported' }}</dd></div>
                    </dl>
                </section>
                <section class="medical-profile-section">
                    <h3>Examination and Clearance Notes</h3>
                    <dl class="medical-profile-grid">
                        <div><dt>Assessment</dt><dd>{{ $selectedMedicalRecord->medical_assessment ?: $selectedMedicalRecord->findings ?: 'Not reported' }}</dd></div>
                        <div><dt>Medical Remarks</dt><dd>{{ $selectedMedicalRecord->medical_remarks ?: $selectedMedicalRecord->notes ?: 'Not reported' }}</dd></div>
                        <div><dt>Restrictions</dt><dd>{{ $selectedMedicalRecord->restrictions ?: 'None recorded' }}</dd></div>
                        <div><dt>Clearance Remarks</dt><dd>{{ $selectedMedicalRecord->clearance_remarks ?: 'Not reported' }}</dd></div>
                    </dl>
                </section>
            </div>

            <div class="medical-documents" id="medical-documents">
                <div>
                    <strong>Medical Documents</strong>
                    @if (filled($selectedMedicalRecord->medical_certificate))
                        <p>
                            <a class="text-link" href="{{ route('admin.medical.certificate.view', $selectedMedicalRecord) }}">View certificate</a>
                            &middot;
                            <a class="text-link" href="{{ route('admin.medical.certificate', $selectedMedicalRecord) }}">Download certificate</a>
                        </p>
                        @if (!$selectedMedicalRecord->isArchived())
                            <button class="row-action row-action-danger" type="button" data-confirm-dialog data-confirm-title="Remove Medical Certificate?" data-confirm-message="Permanently remove this private medical certificate?" data-confirm-label="Remove" data-confirm-method="DELETE" data-confirm-url="{{ route('admin.medical.certificate.destroy', $selectedMedicalRecord) }}">Remove Certificate</button>
                        @endif
                    @else
                        <p>No medical certificate has been uploaded for this record.</p>
                    @endif
                </div>

                @if (!$selectedMedicalRecord->isArchived())
                    <form method="POST" action="{{ route('admin.medical.documents.store', $selectedMedicalRecord) }}" enctype="multipart/form-data" class="medical-document-upload">
                        @csrf
                        <label class="visually-hidden" for="medical-document-category">Document category</label>
                        <select class="form-control" id="medical-document-category" name="category" required>
                            @foreach ($medicalDocumentCategories as $category)
                                <option value="{{ $category }}">{{ $category }}</option>
                            @endforeach
                        </select>
                        <label class="visually-hidden" for="medical-document-file">Select a medical document</label>
                        <input class="form-control" id="medical-document-file" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                        <button class="button button-secondary" type="submit">Upload Document</button>
                    </form>
                    @error('document')<small class="form-error">{{ $message }}</small>@enderror
                    @error('category')<small class="form-error">{{ $message }}</small>@enderror
                @endif

                @if ($selectedMedicalRecord->documents->isNotEmpty())
                    <div class="medical-managed-documents">
                        @foreach ($selectedMedicalRecord->documents as $document)
                            <div class="medical-managed-document">
                                <span><strong>{{ $document->category }}</strong><small>{{ $document->original_name }} &middot; {{ number_format($document->size / 1024) }} KB</small></span>
                                <span class="medical-document-actions">
                                    <a class="row-action" href="{{ route('admin.medical.documents.view', [$selectedMedicalRecord, $document]) }}">View</a>
                                    <a class="row-action" href="{{ route('admin.medical.documents.download', [$selectedMedicalRecord, $document]) }}">Download</a>
                                    @if (!$selectedMedicalRecord->isArchived())
                                        <button class="row-action row-action-danger" type="button" data-confirm-dialog data-confirm-title="Remove Medical Document?" data-confirm-message="Permanently remove this private medical document?" data-confirm-label="Remove" data-confirm-method="DELETE" data-confirm-url="{{ route('admin.medical.documents.destroy', [$selectedMedicalRecord, $document]) }}">Remove</button>
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p>No additional medical documents uploaded.</p>
                @endif

                @if ($medicalProfileDocuments->isNotEmpty())
                    <div class="medical-documents-filed">
                        <small class="meta">Documents filed with this athlete's applications:</small>
                        <p>
                            @foreach ($medicalProfileDocuments as $document)
                                <a class="text-link" href="{{ route('applications.documents.download', [$document, 'medical']) }}">{{ 'Application #'.$document->id }} &middot; {{ $document->created_at?->format('M j, Y') }}</a>{{ $loop->last ? '' : ' &middot; ' }}
                            @endforeach
                        </p>
                    </div>
                @endif
            </div>

            <section class="medical-history">
                <div class="section-heading">
                    <div><span class="admin-dashboard-kicker">AUDIT TRAIL</span><h2>Record Creation and Update History</h2></div>
                </div>
                <p class="meta">Created by {{ $selectedMedicalRecord->creator?->name ?? 'Unknown / legacy record' }} on {{ $selectedMedicalRecord->created_at?->format('M j, Y g:i A') ?? 'Unknown date' }}. Last updated by {{ $selectedMedicalRecord->updater?->name ?? 'Unknown / legacy record' }} on {{ $selectedMedicalRecord->updated_at?->format('M j, Y g:i A') ?? 'Unknown date' }}.</p>
                @forelse ($selectedMedicalRecord->history as $history)
                    <article class="medical-audit-entry">
                        <strong>{{ ucfirst($history->action) }}</strong>
                        <span>{{ $history->actor?->name ?? 'Former administrator' }} &middot; {{ $history->created_at?->format('M j, Y g:i A') }}</span>
                        @if ($history->previous_status || $history->new_status)
                            <small>Clearance: {{ $history->previous_status ?: 'Not set' }} &rarr; {{ $history->new_status ?: 'Removed' }}</small>
                        @endif
                        @if (is_array($history->changes) && $history->changes !== [])
                            <ul>
                                @foreach ($history->changes as $field => $change)
                                    @if (is_array($change) && array_key_exists('old', $change))
                                        <li>{{ str($field)->replace('_', ' ')->title() }}: {{ $change['old'] ?: 'Not reported' }} &rarr; {{ $change['new'] ?: 'Not reported' }}</li>
                                    @elseif (is_array($change) && isset($change['name']))
                                        <li>{{ $change['name'] }} ({{ $change['category'] ?? 'document' }})</li>
                                    @else
                                        <li>{{ str($field)->replace('_', ' ')->title() }} {{ is_array($change) ? implode(' ', $change) : $change }}</li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif
                    </article>
                @empty
                    <p class="empty-state">No earlier changes were recorded for this medical record.</p>
                @endforelse
            </section>

            <div class="medical-history">
                <div class="section-heading">
                    <div>
                        <span class="admin-dashboard-kicker">CASE HISTORY</span>
                        <h2>Injury &amp; Medical Incident History</h2>
                    </div>
                </div>
                @if ($selectedMedicalRecord->injuries->isEmpty())
                    <p class="empty-state">No injuries or medical incidents recorded.</p>
                @else
                    <div class="table-wrap">
                        <table class="data-table medical-incidents-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Sport</th>
                                    <th>Activity</th>
                                    <th>Injury</th>
                                    <th>Severity</th>
                                    <th>Treatment / Rest</th>
                                    <th>Clearance</th>
                                    <th>Return to Play</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($selectedMedicalRecord->injuries as $incident)
                                    <tr>
                                        <td>{{ $incident->incidentDateLabel() }}</td>
                                        <td>{{ $incident->sportName() }}</td>
                                        <td>{{ $incident->activity ?: 'Not recorded' }}</td>
                                        <td>
                                            <strong>{{ $incident->injury_type }}</strong>
                                            @if ($incident->body_part)<br><small class="meta">{{ $incident->body_part }}</small>@endif
                                        </td>
                                        <td><span class="{{ $incident->severityBadgeClass() }}">{{ $incident->severity }}</span></td>
                                        <td>
                                            {{ $incident->restPeriodLabel() }}
                                            <br><small class="meta">{{ $incident->treatment ?: 'No treatment recorded' }}</small>
                                        </td>
                                        <td><span class="{{ $incident->clearanceBadgeClass() }}">{{ $incident->medical_clearance }}</span></td>
                                        <td>{{ $incident->returnToPlayLabel() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endcan

        <div class="medical-detail-actions">
            <a class="button" href="{{ route('admin.medical.edit', $selectedMedicalRecord) }}">Edit Record</a>
            @if (!$selectedMedicalRecord->isArchived() && $selectedMedicalRecord->next_checkup_date)
                @if (filled($selectedMedicalRecord->athlete?->email))
                    <form method="POST" action="{{ route('admin.medical.checkup-reminder', $selectedMedicalRecord) }}">
                        @csrf
                        <button class="button button-secondary" type="submit">Send Checkup Reminder</button>
                    </form>
                @else
                    <span class="meta">Add an email address to the athlete's account to send a reminder.</span>
                @endif
            @endif
            @if ($selectedMedicalRecord->isArchived())
                <button class="button button-secondary" type="button" data-confirm-dialog data-confirm-title="Restore Medical Record?" data-confirm-message="Restore this medical record to the active list?" data-confirm-label="Restore" data-confirm-icon="check" data-confirm-variant="success" data-confirm-method="PATCH" data-confirm-url="{{ route('admin.medical.restore', $selectedMedicalRecord) }}">Restore</button>
            @else
                <button class="button button-secondary" type="button" data-confirm-dialog data-confirm-title="Archive Medical Record?" data-confirm-message="Archive this medical record? It will be removed from the active list." data-confirm-label="Archive" data-confirm-icon="warning" data-confirm-variant="warning" data-confirm-method="PATCH" data-confirm-url="{{ route('admin.medical.archive', $selectedMedicalRecord) }}">Archive</button>
            @endif
        </div>
    </section>
@else
    @if ($medicalShowForm)
    <section class="card medical-form-card" id="medical-record-form">
        <div class="section-heading">
            <div>
                <span class="admin-dashboard-kicker">{{ $medicalFormMode === 'edit' ? 'UPDATE RECORD' : 'NEW RECORD' }}</span>
                <h2>{{ $medicalFormMode === 'edit' ? 'Edit Medical Record' : 'Add Medical Record' }}</h2>
                <p>Each examination is stored as its own record to preserve prior medical and clearance history. Unknown information may be left blank.</p>
            </div>
            @if ($medicalFormMode === 'edit')
                <a class="text-link" href="{{ route('admin.medical') }}">Cancel</a>
            @endif
        </div>

        <form method="POST" action="{{ $medicalFormMode === 'edit' ? route('admin.medical.update', $medicalFormRecord) : route('admin.medical.store') }}" enctype="multipart/form-data" class="medical-form-body">
            @csrf
            @if ($medicalFormMode === 'edit') @method('PUT') @endif

            <fieldset class="medical-form-section">
                <legend>A. Athlete Information</legend>
                <div class="form-group">
                    <label for="medical-athlete-search">Find athlete by name or Student ID</label>
                    <div class="medical-athlete-picker">
                        <input class="form-control" id="medical-athlete-search" type="search" placeholder="Start typing an athlete name or Student ID" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="medical-athlete-results">
                        <div class="medical-athlete-results" id="medical-athlete-results" role="listbox" aria-label="Matching athletes" hidden></div>
                    </div>
                    <small class="medical-athlete-hint">Choose an athlete from the matching results or use the dropdown below.</small>
                </div>
                <div class="grid grid-3">
                <div class="form-group">
                    <label for="medical-athlete">Athlete</label>
                    <select class="form-control @error('athlete_id') is-invalid @enderror" id="medical-athlete" name="athlete_id" required>
                        <option value="">Select athlete</option>
                        @foreach ($athletes ?? [] as $option)
                            <option value="{{ $option->id }}" data-student-id="{{ $option->student_id }}" data-sport="{{ $option->sport?->name ?? 'Unassigned' }}" @selected((string) old('athlete_id', $medicalFormRecord?->athlete_id ?? '') === (string) $option->id)>
                                {{ $option->name }}@if ($option->student_id) ({{ $option->student_id }})@endif &mdash; {{ $option->sport?->name ?? 'Unassigned' }}
                            </option>
                        @endforeach
                    </select>
                    @error('athlete_id')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="form-group">
                    <label for="medical-student-id">Student ID</label>
                    <input class="form-control" id="medical-student-id" type="text" value="{{ $medicalFormRecord?->athlete?->student_id ?? '' }}" readonly>
                </div>
                <div class="form-group">
                    <label for="medical-assigned-sport">Assigned Sport</label>
                    <input class="form-control" id="medical-assigned-sport" type="text" value="{{ $medicalFormRecord?->athlete?->sport?->name ?? '' }}" readonly>
                </div>
                </div>
            </fieldset>

            <fieldset class="medical-form-section">
                <legend>B. Physical Information</legend>
                <div class="grid grid-3">
                <div class="form-group">
                    <label for="medical-height">Height (cm)</label>
                    <input class="form-control" id="medical-height" type="number" name="height_cm" min="1" max="300" step="0.1" value="{{ old('height_cm', $medicalFormRecord?->height_cm) }}">
                </div>
                <div class="form-group">
                    <label for="medical-weight">Weight (kg)</label>
                    <input class="form-control" id="medical-weight" type="number" name="weight_kg" min="1" max="500" step="0.1" value="{{ old('weight_kg', $medicalFormRecord?->weight_kg) }}">
                </div>
                <div class="form-group">
                    <label for="medical-blood-type">Blood Type</label>
                    <select class="form-control" id="medical-blood-type" name="blood_type">
                        <option value="">Unknown / not reported</option>
                        @foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'] as $bloodType)
                            <option value="{{ $bloodType }}" @selected(old('blood_type', $medicalFormRecord?->blood_type) === $bloodType)>{{ $bloodType }}</option>
                        @endforeach
                    </select>
                </div>
                </div>
            </fieldset>

            <fieldset class="medical-form-section">
                <legend>C. Allergies, Conditions and Medical History</legend>
                <div class="form-group">
                    <label for="medical-allergies">Allergies</label>
                    <textarea class="form-control" id="medical-allergies" name="allergies" rows="2" maxlength="2000" placeholder="Unknown / not reported if information is unavailable">{{ old('allergies', $medicalFormRecord?->allergies ?? '') }}</textarea>
                </div>
                <div class="form-group">
                    <label for="medical-conditions">Known Medical Conditions</label>
                    <textarea class="form-control" id="medical-conditions" name="medical_conditions" rows="2" maxlength="2000" placeholder="Unknown / not reported if information is unavailable">{{ old('medical_conditions', $medicalFormRecord?->medical_conditions ?? '') }}</textarea>
                </div>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label for="medical-previous-injuries">Previous Injuries</label>
                        <textarea class="form-control" id="medical-previous-injuries" name="previous_injuries" rows="2" maxlength="2000" placeholder="Not reported if information is unavailable">{{ old('previous_injuries', $medicalFormRecord?->previous_injuries ?? '') }}</textarea>
                    </div>
                    <div class="form-group">
                        <label for="medical-medications">Current Medications</label>
                        <textarea class="form-control" id="medical-medications" name="current_medications" rows="2" maxlength="2000" placeholder="Unknown / not reported if information is unavailable">{{ old('current_medications', $medicalFormRecord?->current_medications ?? '') }}</textarea>
                    </div>
                </div>
                <div class="form-group">
                    <label for="medical-history">Relevant Medical History</label>
                    <textarea class="form-control" id="medical-history" name="medical_history" rows="3" maxlength="4000" placeholder="Not reported if information is unavailable">{{ old('medical_history', $medicalFormRecord?->medical_history ?? '') }}</textarea>
                </div>
            </fieldset>

            <fieldset class="medical-form-section">
                <legend>D. Medical Examination</legend>
                <div class="grid grid-3">
                    <div class="form-group">
                        <label for="medical-examination-date">Examination Date</label>
                        <input class="form-control @error('examination_date') is-invalid @enderror" id="medical-examination-date" type="date" name="examination_date" value="{{ old('examination_date', $medicalFormRecord?->examination_date?->format('Y-m-d') ?? '') }}" required>
                        @error('examination_date')<small class="form-error">{{ $message }}</small>@enderror
                    </div>
                    <div class="form-group">
                        <label for="medical-examiner">Examining Personnel</label>
                        <input class="form-control" id="medical-examiner" type="text" name="examining_personnel" maxlength="160" value="{{ old('examining_personnel', $medicalFormRecord?->examining_personnel ?? '') }}">
                    </div>
                    <div class="form-group">
                        <label for="medical-next-checkup">Next Checkup Date</label>
                        <input class="form-control @error('next_checkup_date') is-invalid @enderror" id="medical-next-checkup" type="date" name="next_checkup_date" value="{{ old('next_checkup_date', $medicalFormRecord?->next_checkup_date?->format('Y-m-d') ?? '') }}">
                        @error('next_checkup_date')<small class="form-error">{{ $message }}</small>@enderror
                    </div>
                </div>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label for="medical-assessment">Medical Assessment</label>
                        <textarea class="form-control" id="medical-assessment" name="medical_assessment" rows="3" maxlength="3000">{{ old('medical_assessment', $medicalFormRecord?->medical_assessment ?? $medicalFormRecord?->findings ?? '') }}</textarea>
                    </div>
                    <div class="form-group">
                        <label for="medical-remarks">Medical Remarks</label>
                        <textarea class="form-control" id="medical-remarks" name="medical_remarks" rows="3" maxlength="3000">{{ old('medical_remarks', $medicalFormRecord?->medical_remarks ?? $medicalFormRecord?->notes ?? '') }}</textarea>
                    </div>
                </div>
            </fieldset>

            <fieldset class="medical-form-section">
                <legend>E. Medical Clearance</legend>
                <div class="grid grid-3">
                    <div class="form-group">
                        <label for="medical-status">Clearance Status</label>
                        <select class="form-control @error('medical_status') is-invalid @enderror" id="medical-status" name="medical_status" required>
                            @foreach ($medicalStatuses as $status)
                                <option value="{{ $status }}" @selected(old('medical_status', $medicalFormRecord?->effectiveStatus() ?? 'For Evaluation') === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                        <small class="form-hint">Clearance decisions must be made by authorized school medical personnel.</small>
                        @error('medical_status')<small class="form-error">{{ $message }}</small>@enderror
                    </div>
                    <div class="form-group">
                        <label for="medical-clearance-date">Clearance Date</label>
                        <input class="form-control" id="medical-clearance-date" type="date" name="clearance_date" value="{{ old('clearance_date', $medicalFormRecord?->clearance_date?->format('Y-m-d') ?? '') }}">
                    </div>
                    <div class="form-group">
                        <label for="medical-expiration-date">Expiration Date</label>
                        <input class="form-control @error('expiration_date') is-invalid @enderror" id="medical-expiration-date" type="date" name="expiration_date" value="{{ old('expiration_date', ($medicalFormRecord?->expiration_date ?? $medicalFormRecord?->next_checkup_date)?->format('Y-m-d') ?? '') }}">
                        @error('expiration_date')<small class="form-error">{{ $message }}</small>@enderror
                    </div>
                </div>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label for="medical-restrictions">Medical Restrictions</label>
                        <textarea class="form-control" id="medical-restrictions" name="restrictions" rows="3" maxlength="1000">{{ old('restrictions', $medicalFormRecord?->restrictions ?? '') }}</textarea>
                    </div>
                    <div class="form-group">
                        <label for="medical-clearance-remarks">Clearance Remarks</label>
                        <textarea class="form-control" id="medical-clearance-remarks" name="clearance_remarks" rows="3" maxlength="3000">{{ old('clearance_remarks', $medicalFormRecord?->clearance_remarks ?? '') }}</textarea>
                    </div>
                </div>
                <div class="form-group">
                    <label for="medical-certificate">Medical Certificate (optional)</label>
                    <input class="form-control @error('medical_certificate') is-invalid @enderror" id="medical-certificate" type="file" name="medical_certificate" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
                    <small class="form-hint">PDF, JPG, JPEG or PNG &middot; maximum {{ round((int) config('medical.certificate_max_kb', 2048) / 1024) }} MB.@if (filled($medicalFormRecord?->medical_certificate)) Existing certificate will be replaced if a new file is uploaded.@endif</small>
                    @error('medical_certificate')<small class="form-error">{{ $message }}</small>@enderror
                </div>
            </fieldset>

            <div class="medical-form-actions">
                <button class="button" type="submit">{{ $medicalFormMode === 'edit' ? 'Update Record' : 'Save Record' }}</button>
                @if ($medicalFormMode === 'edit')
                    <a class="button button-secondary" href="{{ route('admin.medical') }}">Cancel</a>
                @endif
            </div>
        </form>
    </section>
    @endif

    <section class="card medical-records-card">
        <div class="medical-card-head">
            <div>
                <span class="admin-dashboard-kicker">ALL RECORDS</span>
                <h2>Medical Records</h2>
                <p>{{ $recordCount }} record{{ $recordCount === 1 ? '' : 's' }} matching the current filters</p>
            </div>
            <span class="medical-count-chip">{{ $recordCount }}</span>
        </div>

        <form method="GET" action="{{ route('admin.medical') }}" class="filters medical-filters">
            <input class="form-control medical-search" name="search" value="{{ $search ?? '' }}" placeholder="Search athlete name or Student ID..." aria-label="Search by athlete name or Student ID">
            <select class="form-control" name="sport_id" aria-label="Filter by sport">
                <option value="">All Sports</option>
                @foreach ($sports ?? [] as $option)
                    <option value="{{ $option->id }}" @selected((string) ($sportFilter ?? '') === (string) $option->id)>{{ $option->name }}</option>
                @endforeach
            </select>
            <select class="form-control" name="status" aria-label="Filter by medical status">
                <option value="">All Statuses</option>
                @foreach ($medicalStatuses as $status)
                    <option value="{{ $status }}" @selected(($statusFilter ?? '') === $status)>{{ $status }}</option>
                @endforeach
            </select>
            <select class="form-control" name="certificate" aria-label="Filter by certificate status">
                <option value="">All Certificates</option>
                @foreach ($medicalCertificateStates as $state)
                    <option value="{{ $state }}" @selected(($certificateFilter ?? '') === $state)>{{ $state }}</option>
                @endforeach
            </select>
            <input class="form-control" name="examination_date" type="date" value="{{ $examinationDateFilter ?? '' }}" aria-label="Examination date" title="Examination date">
            <label class="medical-filter-toggle">
                <input type="checkbox" name="archived" value="1" @checked($archivedFilter ?? false)>
                Show archived only
            </label>
            <button class="button" type="submit">Search</button>
            <a class="button button-secondary" href="{{ route('admin.medical') }}">Reset</a>
        </form>

        <div class="table-wrap">
            <table class="data-table medical-table">
                <thead>
                    <tr>
                        <th>Athlete</th>
                        <th>Student ID</th>
                        <th>Assigned Sport</th>
                        <th>Blood Type</th>
                        <th>Last Examination</th>
                        <th>Medical Clearance</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($medicalRecords ?? [] as $record)
                        <tr>
                            <td>
                                <div class="medical-cell">
                                    <span class="avatar avatar-initials medical-avatar" aria-hidden="true">{{ filled($record->athlete?->name ?? '') ? $initials($record->athlete->name) : '?' }}</span>
                                    <strong>{{ $record->athlete?->name ?? 'Unknown athlete' }}</strong>
                                </div>
                            </td>
                            <td>{{ $record->athlete?->student_id ?: 'Not recorded' }}</td>
                            <td>{{ $record->sportName() }}</td>
                            <td>{{ $record->blood_type ?: 'Unknown' }}</td>
                            <td>{{ $record->examinationDateLabel() }}</td>
                            <td><span class="{{ $record->statusBadgeClass() }}">{{ $record->effectiveStatus() }}</span>@if ($record->isArchived())<br><small class="meta">Archived</small>@endif</td>
                            <td>{{ $record->updated_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</td>
                            <td>
                                <div class="medical-actions">
                                    <a class="row-action" href="{{ route('admin.medical.show', $record) }}">View Record</a>
                                    <a class="row-action" href="{{ route('admin.medical.edit', $record) }}">Edit / Update Clearance</a>
                                    @if ($record->isArchived())
                                        <button class="row-action" type="button" data-confirm-dialog data-confirm-title="Restore Medical Record?" data-confirm-message="Restore this medical record to the active list?" data-confirm-label="Restore" data-confirm-icon="check" data-confirm-variant="success" data-confirm-method="PATCH" data-confirm-url="{{ route('admin.medical.restore', $record) }}">Restore</button>
                                        <button class="row-action row-action-danger" type="button" data-confirm-dialog data-confirm-title="Delete Medical Record?" data-confirm-message="Permanently delete this medical record? This cannot be undone." data-confirm-label="Delete" data-confirm-method="DELETE" data-confirm-url="{{ route('admin.medical.destroy', $record) }}">Delete</button>
                                    @else
                                        <a class="row-action" href="{{ route('admin.medical.show', $record).'#medical-documents' }}">View Documents</a>
                                        <button class="row-action" type="button" data-confirm-dialog data-confirm-title="Archive Medical Record?" data-confirm-message="Archive this medical record? It will be removed from the active list." data-confirm-label="Archive" data-confirm-icon="warning" data-confirm-variant="warning" data-confirm-method="PATCH" data-confirm-url="{{ route('admin.medical.archive', $record) }}">Archive</button>
                                        <button class="row-action row-action-danger" type="button" data-confirm-dialog data-confirm-title="Delete Medical Record?" data-confirm-message="Permanently delete this medical record and its private documents? The audit trail will be retained." data-confirm-label="Delete" data-confirm-method="DELETE" data-confirm-url="{{ route('admin.medical.destroy', $record) }}">Delete</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="empty-cell">
                                <p class="empty-state">No medical records found.</p>
                                <a class="button button-secondary" href="{{ route('admin.medical.create') }}">Add Medical Record</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($medicalRecords->hasPages())
            <nav class="medical-pagination" aria-label="Medical record pages">
                {{ $medicalRecords->links() }}
            </nav>
        @endif
    </section>

    <div class="grid medical-panels medical-tracker-grid">
        <section class="card medical-tracker-card">
            <div class="medical-tracker-head">
                <span class="medical-kpi-icon tone-amber"><svg aria-hidden="true"><use href="#icon-certificate"></use></svg></span>
                <div>
                    <h2>Expiring Soon</h2>
                    <p>Deadlines within the next {{ $medicalExpiringDays }} days</p>
                </div>
                <span class="medical-count-chip">{{ $expiringCertificates->count() }}</span>
            </div>
            <div class="medical-tracker-list">
                @forelse ($expiringCertificates as $record)
                    <div class="medical-tracker-row">
                        <span class="avatar avatar-initials medical-avatar" aria-hidden="true">{{ filled($record->athlete?->name ?? '') ? $initials($record->athlete->name) : '?' }}</span>
                        <div class="medical-tracker-main">
                            <a class="text-link" href="{{ route('admin.medical.show', $record) }}">{{ $record->athlete?->name ?? 'Unknown athlete' }}</a>
                            <small class="meta">
                                {{ $record->sportName() }}
                                @if ($record->athlete?->student_id) &middot; {{ $record->athlete->student_id }} @endif
                            </small>
                        </div>
                        <div class="medical-tracker-right">
                            <strong>{{ $record->nextCheckupLabel() }}</strong>
                            <span class="{{ $record->certificateBadgeClass() }}">{{ $record->certificateState() }}</span>
                        </div>
                    </div>
                @empty
                    <p class="empty-state">No medical certificates are expiring soon.</p>
                @endforelse
            </div>
        </section>

        <section class="card medical-tracker-card">
            <div class="medical-tracker-head">
                <span class="medical-kpi-icon tone-gold"><svg aria-hidden="true"><use href="#icon-calendar"></use></svg></span>
                <div>
                    <h2>Upcoming Checkups</h2>
                    <p>Clearances due in the weeks ahead</p>
                </div>
                <span class="medical-count-chip">{{ $upcomingCheckups->count() }}</span>
            </div>
            <div class="medical-tracker-list">
                @forelse ($upcomingCheckups as $record)
                    <div class="medical-tracker-row">
                        <span class="avatar avatar-initials medical-avatar" aria-hidden="true">{{ filled($record->athlete?->name ?? '') ? $initials($record->athlete->name) : '?' }}</span>
                        <div class="medical-tracker-main">
                            <a class="text-link" href="{{ route('admin.medical.show', $record) }}">{{ $record->athlete?->name ?? 'Unknown athlete' }}</a>
                            <small class="meta">{{ $record->sportName() }}</small>
                        </div>
                        <div class="medical-tracker-right">
                            <strong>{{ $record->nextCheckupLabel() }}</strong>
                            <span class="{{ $record->statusBadgeClass() }}">{{ $record->effectiveStatus() }}</span>
                        </div>
                    </div>
                @empty
                    <p class="empty-state">No upcoming checkups.</p>
                @endforelse
            </div>
        </section>

        <section class="card medical-tracker-card">
            <div class="medical-tracker-head">
                <span class="medical-kpi-icon tone-danger"><svg aria-hidden="true"><use href="#icon-bell"></use></svg></span>
                <div>
                    <h2>Overdue Checkups</h2>
                    <p>Clearances that require immediate action</p>
                </div>
                <span class="medical-count-chip">{{ $overdueCheckups->count() }}</span>
            </div>
            <div class="medical-tracker-list">
                @forelse ($overdueCheckups as $record)
                    <div class="medical-tracker-row">
                        <span class="avatar avatar-initials medical-avatar" aria-hidden="true">{{ filled($record->athlete?->name ?? '') ? $initials($record->athlete->name) : '?' }}</span>
                        <div class="medical-tracker-main">
                            <a class="text-link" href="{{ route('admin.medical.show', $record) }}">{{ $record->athlete?->name ?? 'Unknown athlete' }}</a>
                            <small class="meta">{{ $record->sportName() }}</small>
                        </div>
                        <div class="medical-tracker-right">
                            <strong>{{ $record->nextCheckupLabel() }}</strong>
                            <span class="{{ $record->statusBadgeClass() }}">{{ $record->effectiveStatus() }}</span>
                        </div>
                    </div>
                @empty
                    <p class="empty-state">No overdue checkups.</p>
                @endforelse
            </div>
        </section>
    </div>

    <details class="card medical-incident-disclosure" id="incident-form" @if ($medicalIncidentFormMode === 'edit' || $errors->any()) open @endif>
        <summary class="medical-disclosure-summary">
            <span class="medical-kpi-icon tone-gold"><svg aria-hidden="true"><use href="#icon-plus"></use></svg></span>
            <span class="medical-disclosure-copy">
                <strong>{{ $medicalIncidentFormMode === 'edit' ? 'Edit Injury / Medical Incident' : 'Record Injury / Medical Incident' }}</strong>
                <small>Log injuries, treatment and return-to-play dates. Records are stored per athlete, so repeated examinations never overwrite the injury history.</small>
            </span>
            <span class="medical-disclosure-toggle" aria-hidden="true"></span>
        </summary>

        <form method="POST" action="{{ $medicalIncidentFormMode === 'edit' ? route('admin.medical.incidents.update', $medicalIncidentFormRecord) : route('admin.medical.incidents.store') }}" class="medical-form-body">
            @csrf
            @if ($medicalIncidentFormMode === 'edit') @method('PUT') @endif

            <div class="grid grid-3">
                <div class="form-group">
                    <label for="incident-athlete">Athlete</label>
                    <select class="form-control @error('athlete_id') is-invalid @enderror" id="incident-athlete" name="athlete_id" required>
                        <option value="">Select athlete</option>
                        @foreach ($athletes ?? [] as $option)
                            <option value="{{ $option->id }}" @selected((string) old('athlete_id', $medicalIncidentFormRecord?->athlete_id ?? '') === (string) $option->id)>
                                {{ $option->name }}@if ($option->student_id) ({{ $option->student_id }})@endif
                            </option>
                        @endforeach
                    </select>
                    @error('athlete_id')<small class="form-error">{{ $message }}</small>@enderror
                </div>

                <div class="form-group">
                    <label for="incident-sport">Sport</label>
                    <select class="form-control @error('sport_id') is-invalid @enderror" id="incident-sport" name="sport_id">
                        <option value="">Use the athlete's sport</option>
                        @foreach ($sports ?? [] as $option)
                            <option value="{{ $option->id }}" @selected((string) old('sport_id', $medicalIncidentFormRecord?->sport_id ?? '') === (string) $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                    @error('sport_id')<small class="form-error">{{ $message }}</small>@enderror
                </div>

                <div class="form-group">
                    <label for="incident-date">Incident Date</label>
                    <input class="form-control @error('incident_date') is-invalid @enderror" id="incident-date" type="date" name="incident_date" value="{{ old('incident_date', $medicalIncidentFormRecord?->incident_date?->format('Y-m-d') ?? '') }}" required>
                    @error('incident_date')<small class="form-error">{{ $message }}</small>@enderror
                </div>
            </div>

            <div class="grid grid-3">
                <div class="form-group">
                    <label for="incident-injury-type">Injury / Condition</label>
                    <input class="form-control @error('injury_type') is-invalid @enderror" id="incident-injury-type" type="text" name="injury_type" maxlength="100" placeholder="e.g. Ankle sprain" value="{{ old('injury_type', $medicalIncidentFormRecord?->injury_type ?? '') }}" required>
                    @error('injury_type')<small class="form-error">{{ $message }}</small>@enderror
                </div>

                <div class="form-group">
                    <label for="incident-body-part">Body Part</label>
                    <input class="form-control @error('body_part') is-invalid @enderror" id="incident-body-part" type="text" name="body_part" maxlength="100" placeholder="e.g. Right ankle" value="{{ old('body_part', $medicalIncidentFormRecord?->body_part ?? '') }}">
                    @error('body_part')<small class="form-error">{{ $message }}</small>@enderror
                </div>

                <div class="form-group">
                    <label for="incident-activity">Activity</label>
                    <input class="form-control @error('activity') is-invalid @enderror" id="incident-activity" type="text" name="activity" maxlength="150" placeholder="e.g. Training drill" value="{{ old('activity', $medicalIncidentFormRecord?->activity ?? '') }}">
                    @error('activity')<small class="form-error">{{ $message }}</small>@enderror
                </div>
            </div>

            <div class="grid grid-3">
                <div class="form-group">
                    <label for="incident-severity">Severity</label>
                    <select class="form-control @error('severity') is-invalid @enderror" id="incident-severity" name="severity" required>
                        @foreach ($medicalSeverities as $level)
                            <option value="{{ $level }}" @selected(old('severity', $medicalIncidentFormRecord?->severity ?? 'Mild') === $level)>{{ $level }}</option>
                        @endforeach
                    </select>
                    @error('severity')<small class="form-error">{{ $message }}</small>@enderror
                </div>

                <div class="form-group">
                    <label for="incident-rest-period">Rest Period (days)</label>
                    <input class="form-control @error('rest_period_days') is-invalid @enderror" id="incident-rest-period" type="number" name="rest_period_days" min="0" max="365" value="{{ old('rest_period_days', $medicalIncidentFormRecord?->rest_period_days ?? '') }}">
                    @error('rest_period_days')<small class="form-error">{{ $message }}</small>@enderror
                </div>

                <div class="form-group">
                    <label for="incident-return-date">Return to Play</label>
                    <input class="form-control @error('return_to_play_date') is-invalid @enderror" id="incident-return-date" type="date" name="return_to_play_date" value="{{ old('return_to_play_date', $medicalIncidentFormRecord?->return_to_play_date?->format('Y-m-d') ?? '') }}">
                    @error('return_to_play_date')<small class="form-error">{{ $message }}</small>@enderror
                </div>
            </div>

            @can('view-medical-details')
                <div class="grid grid-2">
                    <div class="form-group">
                        <label for="incident-description">What Happened</label>
                        <textarea class="form-control @error('description') is-invalid @enderror" id="incident-description" name="description" rows="3" maxlength="2000" placeholder="Describe the injury or incident">{{ old('description', $medicalIncidentFormRecord?->description ?? '') }}</textarea>
                        @error('description')<small class="form-error">{{ $message }}</small>@enderror
                    </div>

                    <div class="form-group">
                        <label for="incident-treatment">Treatment</label>
                        <textarea class="form-control @error('treatment') is-invalid @enderror" id="incident-treatment" name="treatment" rows="3" maxlength="2000" placeholder="Treatment given">{{ old('treatment', $medicalIncidentFormRecord?->treatment ?? '') }}</textarea>
                        @error('treatment')<small class="form-error">{{ $message }}</small>@enderror
                    </div>
                </div>

                <div class="form-group">
                    <label for="incident-clearance">Medical Clearance</label>
                    <select class="form-control @error('medical_clearance') is-invalid @enderror" id="incident-clearance" name="medical_clearance" required>
                        @foreach ($medicalClearances as $state)
                            <option value="{{ $state }}" @selected(old('medical_clearance', $medicalIncidentFormRecord?->medical_clearance ?? 'Pending') === $state)>{{ $state }}</option>
                        @endforeach
                    </select>
                    <small class="form-hint">Athletes without a Cleared clearance and without a past return date are counted as under recovery.</small>
                    @error('medical_clearance')<small class="form-error">{{ $message }}</small>@enderror
                </div>
            @endcan

            <div class="medical-form-actions">
                <button class="button" type="submit">{{ $medicalIncidentFormMode === 'edit' ? 'Update Incident' : 'Save Incident' }}</button>
                @if ($medicalIncidentFormMode === 'edit')
                    <a class="button button-secondary" href="{{ route('admin.medical') }}">Cancel</a>
                @endif
            </div>
        </form>
    </details>

    <section class="card medical-incidents-card">
        <div class="medical-card-head">
            <div>
                <span class="admin-dashboard-kicker">INJURY LOG</span>
                <h2>Injuries &amp; Medical Incidents</h2>
                <p>{{ count($medicalIncidents) }} incident{{ count($medicalIncidents) === 1 ? '' : 's' }} on record</p>
            </div>
            <span class="medical-count-chip">{{ count($medicalIncidents) }}</span>
        </div>

        <div class="table-wrap">
            <table class="data-table medical-incidents-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Athlete</th>
                        <th>Sport</th>
                        <th>Injury</th>
                        <th>Severity</th>
                        <th>Clearance</th>
                        <th>Return to Play</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($medicalIncidents as $incident)
                        <tr>
                            <td>{{ $incident->incidentDateLabel() }}</td>
                            <td>
                                <div class="medical-cell">
                                    <span class="avatar avatar-initials medical-avatar" aria-hidden="true">{{ filled($incident->athlete?->name ?? '') ? $initials($incident->athlete->name) : '?' }}</span>
                                    <div>
                                        <strong>{{ $incident->athlete?->name ?? 'Unknown athlete' }}</strong>
                                        @if ($incident->athlete?->student_id)<br><small class="meta">{{ $incident->athlete->student_id }}</small>@endif
                                    </div>
                                </div>
                            </td>
                            <td>{{ $incident->sportName() }}</td>
                            <td>
                                <strong>{{ $incident->injury_type }}</strong>
                                @if ($incident->body_part)<br><small class="meta">{{ $incident->body_part }}</small>@endif
                                @if ($incident->activity)<br><small class="meta">{{ $incident->activity }}</small>@endif
                            </td>
                            <td><span class="{{ $incident->severityBadgeClass() }}">{{ $incident->severity }}</span></td>
                            <td><span class="{{ $incident->clearanceBadgeClass() }}">{{ $incident->medical_clearance }}</span></td>
                            <td>{{ $incident->returnToPlayLabel() }}</td>
                            <td>
                                <div class="medical-actions">
                                        <a class="row-action" href="{{ route('admin.medical').'?incident='.$incident->id }}">Edit</a>
                                        <button class="row-action row-action-danger" type="button" data-confirm-dialog data-confirm-title="Delete Medical Incident?" data-confirm-message="Delete this injury / medical incident?" data-confirm-label="Delete" data-confirm-method="DELETE" data-confirm-url="{{ route('admin.medical.incidents.destroy', $incident) }}">Delete</button>
                                    </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty-cell"><p class="empty-state">No injuries or medical incidents recorded.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endif
</div>