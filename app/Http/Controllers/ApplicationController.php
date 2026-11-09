<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Sport;
use App\Models\User;
use App\Services\StudentUpdateNotifier;
use App\Support\PortalNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensureAdministrator();

        $applications = Application::with(['sportCategory', 'athlete', 'reviewer'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim($request->string('search')->toString());
                $query->where(function ($applicationQuery) use ($search) {
                    $applicationQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('student_id', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('sport_id'), fn ($query) => $query->where('sport_id', $request->integer('sport_id')))
            ->when($request->filled('grade'), fn ($query) => $query->where('grade', $request->string('grade')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('date'), fn ($query) => $query->whereDate('created_at', $request->input('date')))
            ->latest()
            ->get();

        return view('applications.index', $this->portalData([
            'page' => 'applications',
            'heading' => 'Applications Management',
            'subtitle' => 'Review and process athlete applications',
            'action' => null,
            'applications' => $applications,
            'sports' => Sport::orderBy('name')->get(),
            'grades' => Application::whereNotNull('grade')->distinct()->orderBy('grade')->pluck('grade'),
            'statuses' => self::statuses(),
            'summary' => collect(self::statuses())->mapWithKeys(fn ($status) => [$status => Application::where('status', $status)->count()])->all() + ['Total' => Application::count()],
        ]));
    }

    public function show(Application $application): View
    {
        $this->ensureAdministrator();

        $application->load(['sportCategory', 'athlete', 'reviewer', 'statusHistories.changedBy']);
        $matchingAthlete = $application->athlete
            ?: ($application->student_id ? User::query()->where('role', 'Student')->where('student_id', $application->student_id)->first() : null);

        return view('applications.show', $this->portalData([
            'application' => $application,
            'matchingAthlete' => $matchingAthlete,
            'sports' => Sport::orderBy('name')->get(),
            'statusDefinitions' => self::statusDefinitions(),
        ]));
    }

    public function markUnderReview(Request $request, Application $application): RedirectResponse
    {
        $this->ensureAdministrator();

        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'admin_instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->applyStatus($application, 'Under Review', $validated['review_notes'] ?? null, null, $validated['admin_instructions'] ?? null);

        return back()->with('success', 'Application marked as under review.');
    }

    public function update(Request $request, Application $application): RedirectResponse
    {
        $this->ensureAdministrator();

        $validated = $this->reviewValidated($request);
        $this->applyStatus(
            $application,
            $validated['status'],
            $validated['review_notes'] ?? null,
            $validated['rejection_reason'] ?? null,
            $validated['admin_instructions'] ?? null,
        );

        return back()->with('success', 'Application updated successfully.');
    }

    public function approve(Request $request, Application $application): RedirectResponse
    {
        $this->ensureAdministrator();
        abort_unless($this->documentsVerified($application), 422, 'Cannot approve application. Required eligibility documents are missing or have not been verified.');

        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'admin_instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->applyStatus($application, 'Approved', $validated['review_notes'] ?? null, null, $validated['admin_instructions'] ?? null);

        return back()->with('success', 'Application approved successfully.');
    }

    public function reject(Request $request, Application $application): RedirectResponse
    {
        $this->ensureAdministrator();

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'admin_instructions' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->applyStatus($application, 'Rejected', $validated['review_notes'] ?? null, $validated['rejection_reason'], $validated['admin_instructions'] ?? null);

        return back()->with('success', 'Application rejected.');
    }

    public function requestDocuments(Request $request, Application $application): RedirectResponse
    {
        $this->ensureAdministrator();

        $validated = $request->validate([
            'documents' => ['nullable', 'array'],
            'documents.*' => ['in:medical,birth,consent'],
            'message' => ['required', 'string', 'max:2000'],
            'admin_instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $documents = array_values(array_unique($validated['documents'] ?? []));
        $this->applyStatus($application, 'Requires Revision', $validated['message'], null, $validated['admin_instructions'] ?? null);
        $application->update(['documents_requested' => $documents ?: null]);
        $this->recordHistory($application, $documents ? 'Revision requested for specific documents.' : 'Revision requested.');

        return back()->with('success', 'Revision requested. The applicant can check their status using their reference number.');
    }

    public function verifyDocument(Application $application, string $document): RedirectResponse
    {
        $this->ensureAdministrator();
        $definition = $this->documentDefinition($document);
        abort_unless($application->{$definition['path']}, 422, 'This document has not been submitted.');
        $application->update([$definition['status'] => 'Verified']);
        $this->recordHistory($application, $definition['label'].' verified.');
        $this->notifyApplicant($application, 'Your '.$definition['label'].' has been verified.');

        return back()->with('success', 'Document verified successfully.');
    }

    public function rejectDocument(Request $request, Application $application, string $document): RedirectResponse
    {
        $this->ensureAdministrator();
        $definition = $this->documentDefinition($document);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $notes = $application->document_rejection_notes ?? [];
        $notes[$document] = $validated['reason'];
        $application->update([$definition['status'] => 'Rejected', 'document_rejection_notes' => $notes, 'status' => 'Documents Required']);
        $this->recordHistory($application, $definition['label'].' rejected.');
        $this->notifyApplicant($application, 'Your '.$definition['label'].' needs an update: '.$validated['reason']);

        return back()->with('success', 'Document rejected.');
    }

    public function store(Request $request): RedirectResponse
    {
        $hasNameParts = $request->filled('first_name') || $request->filled('last_name') || $request->has('no_middle_name');

        $nameRules = $hasNameParts
            ? [
                'first_name' => ['required', 'string', 'max:255'],
                'no_middle_name' => ['nullable', 'boolean'],
                'middle_name' => [
                    $request->boolean('no_middle_name') ? 'nullable' : 'required',
                    'string',
                    'max:255',
                ],
                'last_name' => ['required', 'string', 'max:255'],
                'name' => ['nullable', 'string', 'max:255'],
            ]
            : [
                'name' => ['required', 'string', 'max:255'],
                'first_name' => ['nullable', 'string', 'max:255'],
                'middle_name' => ['nullable', 'string', 'max:255'],
                'last_name' => ['nullable', 'string', 'max:255'],
                'no_middle_name' => ['nullable', 'boolean'],
            ];

        $validated = $request->validate(array_merge($nameRules, [
            'student_id' => ['required', 'string', 'max:100'],
            'grade' => ['required', 'in:Grade 7,Grade 8,Grade 9,Grade 10,Grade 11,Grade 12'],
            'gender' => ['required', 'in:Male,Female'],
            'email' => ['required', 'email', 'max:255'],
            'sport_id' => ['required', 'exists:sports,id'],
            'policy_accepted' => ['accepted'],
            'medical_certificate' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'birth_certificate' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'parent_consent' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]), [
            'policy_accepted.accepted' => 'You must agree to the Privacy Policy and Terms & Conditions before submitting.',
            'first_name.required' => 'Please enter your first name.',
            'middle_name.required' => 'Please enter your middle name, or select "No middle name".',
            'last_name.required' => 'Please enter your last name.',
        ]);

        $firstName = trim((string) ($validated['first_name'] ?? ''));
        $middleName = $request->boolean('no_middle_name') ? '' : trim((string) ($validated['middle_name'] ?? ''));
        $lastName = trim((string) ($validated['last_name'] ?? ''));
        $name = $hasNameParts
            ? Application::composeName($firstName, $middleName, $lastName)
            : trim((string) ($validated['name'] ?? ''));

        $sport = Sport::findOrFail($validated['sport_id']);
        unset($validated['policy_accepted'], $validated['no_middle_name']);

        $application = Application::create([
            ...$validated,
            'name' => $name,
            'first_name' => $firstName !== '' ? $firstName : null,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'sport' => $sport->name,
            'privacy_accepted' => true,
            'terms_accepted' => true,
            'policy_accepted_at' => now(),
            'medical_certificate_path' => $request->file('medical_certificate')->store('application-documents/medical', 'private'),
            'birth_certificate_path' => $request->file('birth_certificate')->store('application-documents/birth', 'private'),
            'parent_consent_path' => $request->file('parent_consent')->store('application-documents/consent', 'private'),
        ]);

        ApplicationStatusHistory::create([
            'application_id' => $application->id,
            'status' => $application->status,
            'notes' => 'Application submitted.',
            'changed_by' => null,
        ]);

        return redirect()->route('application.create')->with([
            'submitted' => true,
            'application_reference' => $application->reference_number,
            'application_status_url' => route('application.status'),
        ]);
    }

    private function reviewValidated(Request $request): array
    {
        $status = Application::normalizeStatus($request->input('status'));
        $request->merge(['status' => $status]);

        return $request->validate([
            'status' => ['required', 'in:'.implode(',', self::statuses())],
            'rejection_reason' => ['required_if:status,Rejected', 'nullable', 'string', 'max:2000'],
            'review_notes' => ['required_if:status,Requires Revision', 'nullable', 'string', 'max:2000'],
            'admin_instructions' => ['nullable', 'string', 'max:2000'],
        ], [
            'review_notes.required_if' => 'Please provide an explanation or instructions when requesting a revision.',
        ]);
    }

    private function applyStatus(Application $application, string $status, ?string $reviewNotes = null, ?string $rejectionReason = null, ?string $adminInstructions = null): void
    {
        $status = Application::normalizeStatus($status) ?: $status;

        if ($status === 'Approved') {
            abort_unless($this->documentsVerified($application), 422, 'Cannot approve application. Required eligibility documents are missing or have not been verified.');
        }
        if ($status === 'Requires Revision') {
            $reviewNotes = $reviewNotes ?: $application->review_notes;
            abort_if(blank($reviewNotes), 422, 'An explanation is required when requesting a revision.');
        }

        DB::transaction(function () use ($application, $status, $reviewNotes, $rejectionReason, $adminInstructions) {
            $application->forceFill([
                'status' => $status,
                'review_notes' => $reviewNotes ?? $application->review_notes,
                'admin_instructions' => $adminInstructions ?? $application->admin_instructions,
                'rejection_reason' => $status === 'Rejected' ? $rejectionReason : null,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ])->save();

            ApplicationStatusHistory::create([
                'application_id' => $application->id,
                'status' => $status,
                'notes' => $reviewNotes ?? $rejectionReason,
                'admin_instructions' => $adminInstructions ?? $application->admin_instructions,
                'changed_by' => auth()->id(),
            ]);

            $this->recordHistory($application, 'Application moved to '.$status.'.');
        });

        $this->notifyApplicant($application, $status);
    }

    private function notifyApplicant(Application $application, string $status): void
    {
        $student = $application->athlete;
        if (! $student && $application->student_id) {
            $student = User::query()->where('role', 'Student')->where('student_id', $application->student_id)->first();
        }

        $statusUrl = route('student.application');
        $message = match ($status) {
            'Approved' => 'Your sports application was approved. Check your application status for next steps.',
            'Rejected' => 'Your sports application was not approved. Check your application status for details.',
            'Requires Revision' => 'Your sports application needs revision. Check your application status for instructions.',
            'Under Review' => 'Your sports application is now under review.',
            default => 'Your sports application status was updated to '.$status.'.',
        };

        app(StudentUpdateNotifier::class)->notifyStudent(
            $student,
            'Application update',
            $message,
            $statusUrl,
            ['dedupe_key' => 'application-status|'.$application->id.'|'.$status.'|'.$application->updated_at?->timestamp]
        );
    }

    private function documentsVerified(Application $application): bool
    {
        return collect(self::documentDefinitions())->every(fn ($definition, $document) => $application->{$definition['path']} && $application->{$definition['status']} === 'Verified');
    }

    private function documentDefinition(string $document): array
    {
        abort_unless(isset(self::documentDefinitions()[$document]), 404);
        return self::documentDefinitions()[$document];
    }

    private function recordHistory(Application $application, string $label): void
    {
        $history = $application->review_history ?? [];
        $history[] = ['label' => $label, 'at' => now()->toISOString(), 'by' => auth()->id()];
        $application->update(['review_history' => $history]);
    }

    private function portalData(array $data = []): array
    {
        return array_merge([
            'title' => 'Applications Management',
            'navItems' => PortalNavigation::admin(),
            'active' => 'applications',
            'roleLabel' => 'Admin Portal',
            'userName' => auth()->user()->name,
            'userRole' => auth()->user()->role,
        ], $data);
    }

    private function ensureAdministrator(): void
    {
        abort_unless(auth()->user()?->role === 'Administrator', 403);
    }

    public static function statuses(): array
    {
        return ['Pending', 'Under Review', 'Requires Revision', 'Approved', 'Rejected'];
    }

    public static function statusDefinitions(): array
    {
        return [
            'Pending' => 'Your application has been submitted and is waiting for review.',
            'Under Review' => 'A sports coordinator is currently reviewing your application.',
            'Approved' => 'Your application has been approved. Follow the instructions below for next steps.',
            'Rejected' => 'Your application was not approved. Please review the explanation and instructions below.',
            'Requires Revision' => 'Your application needs corrections or missing information before it can be reviewed again.',
        ];
    }

    public function showStatusForm(): View
    {
        return view('application.status', ['result' => null, 'statusDefinitions' => self::statusDefinitions()]);
    }

    public function checkStatus(Request $request): View
    {
        $validated = $request->validate([
            'reference_number' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $application = Application::query()
            ->where('reference_number', trim($validated['reference_number']))
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($validated['email']))])
            ->with('sportCategory')
            ->first();

        return view('application.status', [
            'result' => $application,
            'statusDefinitions' => self::statusDefinitions(),
            'notFound' => $application === null,
            'input' => $validated,
        ]);
    }

    public static function documentDefinitions(): array
    {
        return [
            'medical' => ['label' => 'Medical Certificate', 'path' => 'medical_certificate_path', 'status' => 'medical_document_status'],
            'birth' => ['label' => 'Birth Certificate', 'path' => 'birth_certificate_path', 'status' => 'birth_document_status'],
            'consent' => ['label' => 'Parent/Guardian Consent', 'path' => 'parent_consent_path', 'status' => 'consent_document_status'],
        ];
    }
}
