<?php

namespace App\Http\Controllers;

use App\Models\MedicalIncident;
use App\Models\MedicalDocument;
use App\Models\MedicalRecord;
use App\Models\MedicalRecordHistory;
use App\Models\Sport;
use App\Models\User;
use App\Support\PortalNavigation;
use App\Services\MessageDeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MedicalController extends Controller
{
    private const AUDITED_FIELDS = [
        'examination_date',
        'medical_status',
        'next_checkup_date',
        'findings',
        'restrictions',
        'notes',
        'height_cm',
        'weight_kg',
        'blood_type',
        'allergies',
        'medical_conditions',
        'previous_injuries',
        'current_medications',
        'medical_history',
        'examining_personnel',
        'medical_assessment',
        'medical_remarks',
        'clearance_date',
        'expiration_date',
        'clearance_remarks',
    ];

    /**
     * The folder certificates are written to on the private disk.
     */
    private const CERTIFICATE_FOLDER = 'medical-certificates';

    private const DOCUMENT_FOLDER = 'medical-documents';

    public function index(): View
    {
        $this->ensureAdministrator();

        return view('admin.page', $this->viewData());
    }

    public function create(Request $request): View
    {
        $this->ensureAdministrator();

        return view('admin.page', $this->viewData([
            'medicalShowForm' => true,
            'medicalPreselectedAthleteId' => $this->preselectedAthleteId($request),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureAdministrator();

        $validated = $this->validateRecord($request);
        $certificate = $this->storeCertificate($request);

        try {
            DB::transaction(function () use ($validated, $certificate): void {
                $record = MedicalRecord::create($this->recordAttributes($validated, $certificate, true));
                $this->writeHistory($record, 'created', null, $record->medical_status, ['record' => ['new']]);
            });
        } catch (\Throwable $exception) {
            if ($certificate && Storage::disk('private')->exists($certificate)) {
                Storage::disk('private')->delete($certificate);
            }

            throw $exception;
        }

        return redirect()->route('admin.medical')->with('success', 'Medical record added successfully.');
    }

    public function show(MedicalRecord $medical): View
    {
        $this->ensureAdministrator();

        return view('admin.page', $this->viewData([
            'heading' => 'Athlete Medical Profile',
            'subtitle' => 'Review clearance status, medical documents and injury history',
            'selectedMedicalRecord' => $medical->load([
                'athlete.sport',
                'creator:id,name',
                'updater:id,name',
                'documents' => fn ($query) => $query->latest(),
                'history.actor',
                'injuries' => fn ($query) => $query->with(['sport', 'athlete.sport'])->orderByDesc('incident_date')->orderByDesc('id'),
            ]),
            'medicalProfileDocuments' => $this->profileDocuments($medical),
            'medicalViewMode' => 'show',
        ]));
    }

    public function edit(MedicalRecord $medical): View
    {
        $this->ensureAdministrator();

        return view('admin.page', $this->viewData([
            'medicalFormMode' => 'edit',
            'medicalFormRecord' => $medical,
            'medicalShowForm' => true,
        ]));
    }

    public function update(Request $request, MedicalRecord $medical): RedirectResponse
    {
        $this->ensureAdministrator();

        $validated = $this->validateRecord($request, $medical);

        $certificate = $medical->medical_certificate;
        $previousCertificate = $certificate;
        $uploadedCertificate = $this->storeCertificate($request);
        if ($uploadedCertificate !== null) {
            $certificate = $uploadedCertificate;
        }

        try {
            DB::transaction(function () use ($medical, $validated, $certificate, $uploadedCertificate): void {
                $before = $medical->getOriginal();
                $attributes = $this->recordAttributes($validated, $certificate);
                $medical->update($attributes);
                $changes = $this->auditedChanges($before, $medical->getAttributes());
                if ($changes !== []) {
                    $this->writeHistory(
                        $medical,
                        'updated',
                        $before['medical_status'] ?? null,
                        $medical->medical_status,
                        $changes,
                    );
                }

                if ($uploadedCertificate !== null) {
                    $this->writeHistory($medical, 'document uploaded', null, null, ['medical certificate' => ['uploaded']]);
                }
            });
        } catch (\Throwable $exception) {
            if ($uploadedCertificate && Storage::disk('private')->exists($uploadedCertificate)) {
                Storage::disk('private')->delete($uploadedCertificate);
            }

            throw $exception;
        }

        if ($uploadedCertificate !== null && $previousCertificate !== $uploadedCertificate) {
            $this->deleteCertificatePath($previousCertificate);
        }

        return redirect()->route('admin.medical.show', $medical)->with('success', 'Medical record updated successfully.');
    }

    public function sendCheckupReminder(MedicalRecord $medical, MessageDeliveryService $delivery): RedirectResponse
    {
        $this->ensureAdministrator();
        $medical->loadMissing('athlete');

        if ($medical->isArchived()) {
            return back()->withErrors(['checkup_reminder' => 'Restore this medical record before sending a checkup reminder.']);
        }

        if (! $medical->next_checkup_date) {
            return back()->withErrors(['checkup_reminder' => 'Add a next checkup date before sending a reminder.']);
        }

        $athlete = $medical->athlete;

        if (! $athlete || $athlete->role !== 'Student' || $athlete->status !== 'Active') {
            return back()->withErrors(['checkup_reminder' => 'Only active student-athlete accounts can receive checkup reminders.']);
        }

        $message = $delivery->send(auth()->user(), [
            'subject' => 'Medical Checkup Reminder',
            'content' => 'Your medical record indicates a checkup is scheduled for '.$medical->next_checkup_date->format('F j, Y').'. Please contact the school sports office to confirm the time and location.',
            'category' => \App\Models\Message::CATEGORY_MEDICAL_FOLLOW_UP,
            'message_type' => 'medical_follow_up',
            'priority' => 'Important',
            'recipient_type' => 'individual',
            'athlete_id' => $athlete->id,
            'follow_up_type' => 'Medical Checkup Reminder',
            'scheduled_date' => $medical->next_checkup_date->toDateString(),
            'scheduled_time' => null,
            'location' => null,
            'additional_instructions' => 'Please contact the school sports office to confirm the time and location before your next practice.',
            'response_required' => true,
            'medical_record_id' => $medical->id,
        ]);

        return redirect()->route('admin.messages.show', $message)->with('success', 'In-app checkup reminder sent to the athlete.');
    }

    /**
     * Archiving keeps the student's medical history while removing the record
     * from the active list, so nothing has to be re-entered later.
     */
    public function archive(MedicalRecord $medical): RedirectResponse
    {
        $this->ensureAdministrator();

        DB::transaction(function () use ($medical): void {
            $medical->update(['archived_at' => now(), 'updated_by' => auth()->id()]);
            $this->writeHistory($medical, 'archived', null, null, ['record' => ['archived']]);
        });

        return redirect()->route('admin.medical')->with('success', 'Medical record archived. It is no longer listed among active records.');
    }

    public function restore(MedicalRecord $medical): RedirectResponse
    {
        $this->ensureAdministrator();

        DB::transaction(function () use ($medical): void {
            $medical->update(['archived_at' => null, 'updated_by' => auth()->id()]);
            $this->writeHistory($medical, 'restored', null, null, ['record' => ['restored']]);
        });

        return redirect()->route('admin.medical')->with('success', 'Medical record restored to the active list.');
    }

    public function destroy(MedicalRecord $medical): RedirectResponse
    {
        $this->ensureAdministrator();
        $paths = $medical->documents()->pluck('path')->all();
        $legacyCertificate = $medical->medical_certificate;

        DB::transaction(function () use ($medical): void {
            $this->writeHistory($medical, 'deleted', $medical->medical_status, null, ['record' => ['deleted']]);
            $medical->delete();
        });

        foreach ([...$paths, $legacyCertificate] as $path) {
            $this->deleteCertificatePath($path);
        }

        return redirect()->route('admin.medical')->with('success', 'Medical record deleted successfully.');
    }

    /**
     * Certificate preview for the record profile. The file name comes from the
     * stored path, never from the request, and the file stays on the private
     * disk so it is only reachable through this authorized route.
     */
    public function viewCertificate(MedicalRecord $medical)
    {
        $this->ensureAdministrator();

        abort_unless($medical->hasCertificate(), 404, 'No certificate is attached to this medical record.');

        return Storage::disk('private')->response($medical->medical_certificate, $medical->certificateDiskName(), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function downloadCertificate(MedicalRecord $medical)
    {
        $this->ensureAdministrator();

        abort_unless($medical->hasCertificate(), 404, 'The requested certificate is no longer available.');

        return Storage::disk('private')->download($medical->medical_certificate, $medical->certificateDiskName(), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyCertificate(MedicalRecord $medical): RedirectResponse
    {
        $this->ensureAdministrator();
        $path = $medical->medical_certificate;

        if (! $path) {
            return back()->withErrors(['medical_certificate' => 'There is no medical certificate attached to this record.']);
        }

        DB::transaction(function () use ($medical): void {
            $medical->forceFill([
                'medical_certificate' => null,
                'updated_by' => auth()->id(),
            ])->save();
            $this->writeHistory($medical, 'document removed', null, null, ['medical certificate' => ['removed']]);
        });
        $this->deleteCertificatePath($path);

        return back()->with('success', 'Medical certificate removed.');
    }

    public function storeDocument(Request $request, MedicalRecord $medical): RedirectResponse
    {
        $this->ensureAdministrator();
        abort_if($medical->isArchived(), 403, 'Documents cannot be added to an archived medical record.');

        $maxKilobytes = (int) config('medical.certificate_max_kb');
        $validated = $request->validate([
            'category' => ['required', Rule::in(MedicalDocument::CATEGORIES)],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.$maxKilobytes],
        ]);
        $file = $request->file('document');
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension();
        $path = $file->storeAs(self::DOCUMENT_FOLDER, Str::uuid().'.'.$extension, 'private');

        if ($path === false) {
            throw ValidationException::withMessages([
                'document' => 'The medical document could not be uploaded. Please try again.',
            ]);
        }

        try {
            DB::transaction(function () use ($medical, $validated, $file, $path): void {
                $medical->forceFill(['updated_by' => auth()->id()])->save();
                $document = $medical->documents()->create([
                    'category' => $validated['category'],
                    'original_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 190, ''),
                    'path' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(),
                    'uploaded_by' => auth()->id(),
                ]);
                $this->writeHistory($medical, 'document uploaded', null, null, [
                    'document' => ['name' => $document->original_name, 'category' => $document->category],
                ]);
            });
        } catch (\Throwable $exception) {
            if (Storage::disk('private')->exists($path)) {
                Storage::disk('private')->delete($path);
            }

            throw $exception;
        }

        return back()->with('success', 'Medical document uploaded successfully.');
    }

    public function viewDocument(MedicalRecord $medical, MedicalDocument $document)
    {
        $this->ensureAdministrator();
        abort_unless($document->medical_record_id === $medical->id && $document->hasFile(), 404);

        return Storage::disk('private')->response($document->path, $document->downloadName(), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function downloadDocument(MedicalRecord $medical, MedicalDocument $document)
    {
        $this->ensureAdministrator();
        abort_unless($document->medical_record_id === $medical->id && $document->hasFile(), 404);

        return Storage::disk('private')->download($document->path, $document->downloadName(), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroyDocument(MedicalRecord $medical, MedicalDocument $document): RedirectResponse
    {
        $this->ensureAdministrator();
        abort_unless($document->medical_record_id === $medical->id, 404);
        $name = $document->original_name;
        $path = $document->path;

        DB::transaction(function () use ($medical, $document, $name): void {
            $medical->forceFill(['updated_by' => auth()->id()])->save();
            $this->writeHistory($medical, 'document removed', null, null, ['document' => ['name' => $name, 'removed']]);
            $document->delete();
        });
        $this->deleteCertificatePath($path);

        return back()->with('success', 'Medical document removed.');
    }

    /**
     * Everything every screen of the module renders with: the filtered record
     * list, the summary strip, the tracking panels and the incident log.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function viewData(array $overrides = []): array
    {
        $filters = $this->filters(request());

        $records = MedicalRecord::query()
            ->filtered($filters)
            ->with(['athlete.sport'])
            ->orderByDesc('examination_date')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $summary = $this->summaryData();

        $incidents = MedicalIncident::query()
            ->with(['athlete.sport', 'sport'])
            ->orderByDesc('incident_date')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $incidentId = request('incident');
        $incidentRecord = filled($incidentId) ? MedicalIncident::with('athlete')->findOrFail($incidentId) : null;

        return array_merge($this->portalData(), [
            'page' => 'medical',
            'heading' => 'Medical Records',
            'subtitle' => 'Manage athlete health information and clearances',
            'athletes' => User::where('role', 'Student')->with('sport')->orderBy('name')->get(),
            'sports' => Sport::orderBy('name')->get(),
            'medicalRecords' => $records,
            'medicalRecordCount' => $records->total(),
            'medicalSummary' => $summary,
            'medicalAlerts' => $this->clearanceAlerts($summary),
            'medicalStatusDistribution' => $summary['distribution'],
            'medicalAthletesBySport' => DB::table('medical_records')
                ->whereNull('medical_records.archived_at')
                ->leftJoin('users', 'users.id', '=', 'medical_records.athlete_id')
                ->leftJoin('sports', 'sports.id', '=', 'users.sport_id')
                ->selectRaw("COALESCE(sports.name, 'Unassigned') as sport_name, COUNT(DISTINCT medical_records.athlete_id) as total")
                ->groupBy('sports.name')
                ->orderByDesc('total')
                ->limit(8)
                ->get(),
            'recentMedicalUpdates' => MedicalRecord::query()
                ->active()
                ->with('athlete:id,name,student_id')
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get(),
            'expiringCertificates' => MedicalRecord::query()
                ->active()
                ->whereRaw('COALESCE(expiration_date, next_checkup_date) between ? and ?', [
                    today()->toDateString(),
                    today()->addDays($this->expiringDays())->toDateString(),
                ])
                ->with(['athlete.sport'])
                ->orderByRaw('COALESCE(expiration_date, next_checkup_date)')
                ->orderBy('id')
                ->limit(12)
                ->get(),
            'upcomingCheckups' => MedicalRecord::query()
                ->active()
                ->upcomingCheckups()
                ->with(['athlete.sport'])
                ->orderBy('next_checkup_date')
                ->orderBy('id')
                ->limit(8)
                ->get(),
            'overdueCheckups' => MedicalRecord::query()
                ->active()
                ->overdueCheckups()
                ->with(['athlete.sport'])
                ->orderByDesc('next_checkup_date')
                ->orderByDesc('id')
                ->limit(8)
                ->get(),
            'medicalIncidents' => $incidents,
            'medicalIncidentFormMode' => $incidentRecord ? 'edit' : 'create',
            'medicalIncidentFormRecord' => $incidentRecord,
            'medicalFormMode' => 'create',
            'medicalFormRecord' => null,
            'medicalShowForm' => false,
            'selectedMedicalRecord' => null,
            'medicalProfileDocuments' => collect(),
            'medicalViewMode' => 'index',
            'medicalStatuses' => MedicalRecord::STATUSES,
            'medicalCertificateStates' => MedicalRecord::CERTIFICATE_STATES,
            'medicalSeverities' => MedicalIncident::SEVERITIES,
            'medicalClearances' => MedicalIncident::CLEARANCES,
            'medicalExpiringDays' => $this->expiringDays(),
            'medicalDocumentCategories' => MedicalDocument::CATEGORIES,
            'search' => $filters['search'],
            'sportFilter' => $filters['sport_id'],
            'statusFilter' => $filters['status'],
            'certificateFilter' => $filters['certificate'],
            'examinationDateFilter' => $filters['examination_date'],
            'archivedFilter' => $filters['archived'],
        ], $overrides);
    }

    /**
     * Whitelisted filter values, so a hand-crafted query string can never reach
     * the query builder as a status or a broken date.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $status = $request->input('status');
        $certificate = $request->input('certificate');
        $examinationDate = $request->input('examination_date');

        if (! is_string($examinationDate) || ! Carbon::hasFormat($examinationDate, 'Y-m-d')) {
            $examinationDate = null;
        }

        return [
            'search' => trim((string) $request->input('search', '')),
            'sport_id' => $request->filled('sport_id') ? $request->input('sport_id') : null,
            'status' => is_string($status) && in_array($status, [...MedicalRecord::STATUSES, 'Pending', 'Restricted', 'Not Cleared'], true) ? $status : null,
            'certificate' => is_string($certificate) && in_array($certificate, MedicalRecord::CERTIFICATE_STATES, true) ? $certificate : null,
            'examination_date' => $examinationDate,
            'archived' => $request->boolean('archived'),
        ];
    }

    /**
     * Dashboard metrics are based on every active exam record, not only the
     * current search result page.
     *
     * @return array<string, mixed>
     */
    private function summaryData(): array
    {
        $query = MedicalRecord::query()->active();
        $latestPerAthlete = (clone $query)->get([
            'id',
            'athlete_id',
            'medical_status',
            'expiration_date',
            'next_checkup_date',
            'examination_date',
        ])->groupBy('athlete_id')
            ->map(fn (Collection $records) => $records
                ->sortByDesc(fn (MedicalRecord $record) => [
                    $record->examination_date?->format('Y-m-d') ?? '',
                    $record->id,
                ])->first())
            ->filter();
        $distribution = $latestPerAthlete->countBy(fn (MedicalRecord $record) => $record->effectiveStatus());
        $expired = $distribution->get('Expired', 0);
        $cleared = $distribution->get('Cleared', 0);
        $forEvaluation = $distribution->get('For Evaluation', 0);
        $restricted = $distribution->get('Restricted Participation', 0);
        $expiringSoon = $latestPerAthlete->filter(fn (MedicalRecord $record) => $record->isExpiringSoon())->count();

        return [
            'total' => (clone $query)->count(),
            'athletes_with_records' => $latestPerAthlete->count(),
            'cleared' => (int) $cleared,
            'evaluation' => (int) $forEvaluation,
            'pending' => (int) $forEvaluation,
            'restricted' => (int) $restricted,
            'expired' => (int) $expired,
            'expiring_soon' => $expiringSoon,
            'under_recovery' => MedicalIncident::query()->underRecovery()->distinct()->count('athlete_id'),
            'distribution' => collect(MedicalRecord::STATUSES)
                ->mapWithKeys(fn (string $status) => [$status => (int) $distribution->get($status, 0)]),
            'missing' => User::query()->where('role', 'Student')->whereDoesntHave('medicalRecords', fn ($records) => $records->whereNull('archived_at'))->count(),
        ];
    }

    /**
     * The athlete a new record should be filed for, taken from the link that
     * sent the administrator here. Unknown or non-athlete ids are ignored so
     * the form still opens on the full athlete list.
     */
    private function preselectedAthleteId(Request $request): ?int
    {
        $athleteId = $request->integer('athlete_id');

        if ($athleteId <= 0) {
            return null;
        }

        return User::query()
            ->where('role', 'Student')
            ->whereKey($athleteId)
            ->value('id');
    }

    /**
     * @param  Collection<int, MedicalRecord>  $records
     * @return array<int, array<string, mixed>>
     */
    private function clearanceAlerts(array $summary): array
    {
        $warnings = [];

        if ($summary['expiring_soon'] > 0) {
            $warnings[] = [
                'type' => 'warning',
                'title' => 'Medical clearance expiring soon',
                'message' => $summary['expiring_soon'].' athlete record(s) have clearance or checkup dates due within '.$this->expiringDays().' days.',
                'url' => route('admin.medical', ['certificate' => MedicalRecord::CERTIFICATE_EXPIRING]),
                'action' => 'Review',
            ];
        }

        if ($summary['expired'] > 0) {
            $warnings[] = [
                'type' => 'danger',
                'title' => 'Expired medical clearance',
                'message' => $summary['expired'].' athlete record(s) have expired medical clearance or overdue checkup dates.',
                'url' => route('admin.medical', ['status' => 'Expired']),
                'action' => 'Review',
            ];
        }

        if ($summary['missing'] > 0) {
            $warnings[] = [
                'type' => 'danger',
                'title' => 'Medical records missing',
                'message' => $summary['missing'].' student-athlete account(s) have no active medical record.',
                'url' => route('athletes.index', ['medical' => 'missing']),
                'action' => 'Assign',
            ];
        }

        return $warnings;
    }

    /**
     * Documents filed with the athlete's applications, shown beside the
     * uploaded certificate so the profile holds every medical document in one
     * place without copying files around.
     */
    private function profileDocuments(MedicalRecord $medical): Collection
    {
        $athlete = $medical->athlete;

        if (! $athlete) {
            return collect();
        }

        return $athlete->applications()
            ->whereNotNull('medical_certificate_path')
            ->orderByDesc('id')
            ->limit(3)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function recordAttributes(array $validated, ?string $certificate, bool $creating = false): array
    {
        $attributes = [
            'athlete_id' => $validated['athlete_id'],
            'user_id' => $validated['athlete_id'],
            'examination_date' => $validated['examination_date'],
            'medical_status' => $validated['medical_status'],
            'next_checkup_date' => $validated['next_checkup_date'] ?? null,
            'findings' => $validated['medical_assessment'] ?? $validated['findings'] ?? null,
            'restrictions' => $validated['restrictions'] ?? null,
            'notes' => $validated['medical_remarks'] ?? $validated['notes'] ?? null,
            'clearance' => $validated['medical_status'],
            'last_checkup' => $validated['examination_date'],
            'medical_certificate' => $certificate,
            'height_cm' => $validated['height_cm'] ?? null,
            'weight_kg' => $validated['weight_kg'] ?? null,
            'blood_type' => $validated['blood_type'] ?? null,
            'allergies' => $validated['allergies'] ?? null,
            'medical_conditions' => $validated['medical_conditions'] ?? null,
            'previous_injuries' => $validated['previous_injuries'] ?? null,
            'current_medications' => $validated['current_medications'] ?? null,
            'medical_history' => $validated['medical_history'] ?? null,
            'examining_personnel' => $validated['examining_personnel'] ?? null,
            'medical_assessment' => $validated['medical_assessment'] ?? $validated['findings'] ?? null,
            'medical_remarks' => $validated['medical_remarks'] ?? $validated['notes'] ?? null,
            'clearance_date' => $validated['clearance_date'] ?? null,
            'expiration_date' => $validated['expiration_date'] ?? null,
            'clearance_remarks' => $validated['clearance_remarks'] ?? null,
            'updated_by' => auth()->id(),
        ];

        if ($creating) {
            $attributes['created_by'] = auth()->id();
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRecord(Request $request, ?MedicalRecord $medical = null): array
    {
        $mimes = implode(',', (array) config('medical.certificate_mimes'));
        $maxKilobytes = (int) config('medical.certificate_max_kb');

        return Validator::make($request->all(), [
            'athlete_id' => [
                'required',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'Student')),
            ],
            'examination_date' => ['required', 'date'],
            'medical_status' => ['required', Rule::in([...MedicalRecord::STATUSES, ...MedicalRecord::LEGACY_STATUSES])],
            'next_checkup_date' => ['nullable', 'date', 'after_or_equal:examination_date'],
            'findings' => ['nullable', 'string', 'max:1000'],
            'restrictions' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'height_cm' => ['nullable', 'numeric', 'gt:0', 'lte:300'],
            'weight_kg' => ['nullable', 'numeric', 'gt:0', 'lte:500'],
            'blood_type' => ['nullable', Rule::in(['Unknown', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'medical_conditions' => ['nullable', 'string', 'max:2000'],
            'previous_injuries' => ['nullable', 'string', 'max:2000'],
            'current_medications' => ['nullable', 'string', 'max:2000'],
            'medical_history' => ['nullable', 'string', 'max:4000'],
            'examining_personnel' => ['nullable', 'string', 'max:160'],
            'medical_assessment' => ['nullable', 'string', 'max:3000'],
            'medical_remarks' => ['nullable', 'string', 'max:3000'],
            'clearance_date' => ['nullable', 'date', 'after_or_equal:examination_date'],
            'expiration_date' => [
                'nullable',
                'date',
                'after_or_equal:clearance_date',
                Rule::requiredIf(($request->input('medical_status') === 'Expired')),
                Rule::when($request->input('medical_status') === 'Expired', ['before_or_equal:today']),
            ],
            'clearance_remarks' => ['nullable', 'string', 'max:3000'],
            'medical_certificate' => ['nullable', 'file', 'mimes:'.$mimes, 'max:'.$maxKilobytes],
        ], [
            'athlete_id.required' => 'Select the athlete this medical record belongs to.',
            'athlete_id.exists' => 'The selected athlete no longer exists.',
            'examination_date.required' => 'Enter the date of the medical examination.',
            'examination_date.date' => 'Enter a valid examination date.',
            'medical_status.required' => 'Select a medical status.',
            'medical_status.in' => 'Select one of the supported medical statuses.',
            'next_checkup_date.date' => 'Enter a valid next checkup date.',
            'next_checkup_date.after_or_equal' => 'The next checkup date must be on or after the examination date.',
            'blood_type.in' => 'Select a listed blood type or Unknown.',
            'height_cm.lte' => 'Enter a height no greater than 300 cm.',
            'weight_kg.lte' => 'Enter a weight no greater than 500 kg.',
            'expiration_date.after_or_equal' => 'The expiration date must be on or after the clearance date.',
            'clearance_date.after_or_equal' => 'The clearance date must be on or after the examination date.',
            'expiration_date.required' => 'Enter the expired clearance date so it can be tracked for renewal.',
            'medical_certificate.mimes' => 'The medical certificate must be a PDF, JPG, JPEG or PNG file.',
            'medical_certificate.max' => 'The medical certificate may not be larger than '.round($maxKilobytes / 1024).' MB.',
        ])->validate();
    }

    /**
     * Certificates are written to the private disk under a generated name, so
     * an upload failure never leaves a half-saved record behind.
     */
    private function storeCertificate(Request $request): ?string
    {
        if (! $request->hasFile('medical_certificate')) {
            return null;
        }

        $file = $request->file('medical_certificate');
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension();

        $path = $file->storeAs(self::CERTIFICATE_FOLDER, Str::uuid().'.'.$extension, 'private');

        if ($path === false) {
            throw ValidationException::withMessages([
                'medical_certificate' => 'The medical certificate could not be uploaded. Please try again.',
            ]);
        }

        return $path;
    }

    private function deleteCertificatePath(?string $path): void
    {
        if ($path && Storage::disk('private')->exists($path)) {
            Storage::disk('private')->delete($path);
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function auditedChanges(array $before, array $after): array
    {
        $changes = [];

        foreach (self::AUDITED_FIELDS as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            if ($old !== $new) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function writeHistory(
        MedicalRecord $medical,
        string $action,
        ?string $previousStatus,
        ?string $newStatus,
        array $changes,
    ): void {
        $medical->history()->create([
            'athlete_id' => $medical->athlete_id,
            'changed_by' => auth()->id(),
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'changes' => $changes,
        ]);
    }

    private function expiringDays(): int
    {
        return max(1, (int) config('medical.expiring_days', 30));
    }

    private function portalData(array $data = []): array
    {
        return array_merge([
            'title' => 'Medical Records',
            'navItems' => PortalNavigation::admin(),
            'active' => 'medical',
            'roleLabel' => 'Admin Portal',
            'userName' => auth()->user()->name,
            'userRole' => auth()->user()->role,
            'announcements' => [],
        ], $data);
    }

    private function ensureAdministrator(): void
    {
        abort_unless(Gate::allows('view-medical-details'), 403);
    }
}
