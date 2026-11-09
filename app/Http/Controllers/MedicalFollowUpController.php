<?php

namespace App\Http\Controllers;

use App\Models\MedicalConversation;
use App\Models\MedicalMessage;
use App\Models\User;
use App\Notifications\MedicalFollowUpNotification;
use App\Support\PortalNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MedicalFollowUpController extends Controller
{
    private const ATTACHMENT_FOLDER = 'medical-follow-up-attachments';

    public function adminIndex(Request $request): View
    {
        $this->authorizeAdmin();

        $conversations = MedicalConversation::query()
            ->with(['athlete.sport', 'creator', 'latestMessage.sender'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->input('search'));
                $query->whereHas('athlete', fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%"));
            })
            ->when($request->filled('status') && in_array($request->input('status'), MedicalConversation::STATUSES, true),
                fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('date'), function ($query) use ($request) {
                if (\Illuminate\Support\Carbon::hasFormat((string) $request->input('date'), 'Y-m-d')) {
                    $query->whereDate('updated_at', $request->input('date'));
                }
            })
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        $athletes = User::query()
            ->where('role', 'Student')
            ->with('sport')
            ->orderBy('name')
            ->get(['id', 'name', 'student_id', 'sport_id']);

        return view('admin.page', array_merge($this->adminPortalData(), [
            'page' => 'medical-follow-ups',
            'heading' => 'Medical Follow-ups',
            'subtitle' => 'Private medical communication with student-athletes',
            'conversations' => $conversations,
            'athletes' => $athletes,
            'categories' => MedicalConversation::CATEGORIES,
            'statuses' => MedicalConversation::STATUSES,
            'selectedConversation' => null,
            'followUpView' => 'index',
        ]));
    }

    public function adminCreate(): View
    {
        $this->authorizeAdmin();

        $athletes = User::query()
            ->where('role', 'Student')
            ->with('sport')
            ->orderBy('name')
            ->get(['id', 'name', 'student_id', 'sport_id']);

        return view('admin.page', array_merge($this->adminPortalData(), [
            'page' => 'medical-follow-ups',
            'heading' => 'New Medical Follow-up',
            'subtitle' => 'Start a private conversation with an athlete',
            'conversations' => collect(),
            'athletes' => $athletes,
            'categories' => MedicalConversation::CATEGORIES,
            'statuses' => MedicalConversation::STATUSES,
            'selectedConversation' => null,
            'followUpView' => 'create',
        ]));
    }

    public function adminStore(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'athlete_id' => ['required', Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', 'Student'))],
            'category' => ['required', 'string', 'max:80'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ], [
            'athlete_id.required' => 'Select an athlete.',
            'athlete_id.exists' => 'The selected athlete is not valid.',
        ]);

        $admin = $request->user();
        $athlete = User::where('role', 'Student')->findOrFail($validated['athlete_id']);

        $conversation = DB::transaction(function () use ($validated, $admin, $athlete) {
            $conversation = MedicalConversation::create([
                'athlete_id' => $athlete->id,
                'created_by' => $admin->id,
                'subject' => $validated['subject'],
                'category' => $validated['category'],
                'status' => MedicalConversation::STATUS_PENDING,
            ]);

            $conversation->messages()->create([
                'sender_id' => $admin->id,
                'message' => $validated['message'],
            ]);

            $athlete->notify(new MedicalFollowUpNotification([
                'title' => 'New Medical Follow-up',
                'message' => 'You have a new private medical follow-up message from the sports office. Open it to review and respond.',
                'url' => route('student.medical-follow-ups.show', $conversation),
                'medical_conversation_id' => $conversation->id,
            ]));

            return $conversation;
        });

        return redirect()->route('admin.medical.follow-ups.show', $conversation)
            ->with('success', 'Medical follow-up conversation started.');
    }

    public function adminShow(MedicalConversation $conversation): View
    {
        $this->authorizeAdmin();
        $conversation->load(['athlete.sport', 'creator', 'messages.sender']);
        $conversation->markReadFor(request()->user());

        return view('admin.page', array_merge($this->adminPortalData(), [
            'page' => 'medical-follow-ups',
            'heading' => 'Medical Follow-up',
            'subtitle' => $conversation->subject,
            'conversations' => collect(),
            'athletes' => collect(),
            'categories' => MedicalConversation::CATEGORIES,
            'statuses' => MedicalConversation::STATUSES,
            'selectedConversation' => $conversation,
            'followUpView' => 'show',
        ]));
    }

    public function adminReply(Request $request, MedicalConversation $conversation): RedirectResponse
    {
        $this->authorizeAdmin();
        abort_if($conversation->status === MedicalConversation::STATUS_RESOLVED && ! $request->boolean('reopen'), 422, 'Reopen or update status before replying to a resolved conversation.');

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $admin = $request->user();
        DB::transaction(function () use ($conversation, $admin, $validated) {
            $conversation->messages()->create([
                'sender_id' => $admin->id,
                'message' => $validated['message'],
            ]);
            $conversation->forceFill([
                'status' => MedicalConversation::STATUS_PENDING,
            ])->save();

            $conversation->athlete->notify(new MedicalFollowUpNotification([
                'title' => 'Medical Follow-up Update',
                'message' => 'You have a new reply in your medical follow-up conversation. Open it to review and respond.',
                'url' => route('student.medical-follow-ups.show', $conversation),
                'medical_conversation_id' => $conversation->id,
            ]));
        });

        return redirect()->route('admin.medical.follow-ups.show', $conversation)
            ->with('success', 'Message sent.');
    }

    public function adminUpdateStatus(Request $request, MedicalConversation $conversation): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'status' => ['required', Rule::in(MedicalConversation::STATUSES)],
        ]);

        $conversation->update(['status' => $validated['status']]);

        if ($validated['status'] === MedicalConversation::STATUS_RESOLVED) {
            $conversation->athlete->notify(new MedicalFollowUpNotification([
                'title' => 'Medical Follow-up Resolved',
                'message' => 'A medical follow-up conversation has been marked as resolved by the sports office.',
                'url' => route('student.medical-follow-ups.show', $conversation),
                'medical_conversation_id' => $conversation->id,
            ]));
        }

        return back()->with('success', 'Conversation status updated.');
    }

    public function studentIndex(Request $request): View
    {
        $athlete = $this->authorizeStudent($request);

        $conversations = MedicalConversation::query()
            ->forAthlete($athlete)
            ->with(['creator', 'latestMessage.sender'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->whereNull('read_at')->where('sender_id', '!=', $athlete->id)])
            ->orderByDesc('updated_at')
            ->paginate(15);

        return view('student.page', $this->studentPageData($athlete, 'medical-follow-ups', [
            'heading' => 'Medical Follow-ups',
            'subtitle' => 'Private medical messages and follow-ups from the sports office',
            'conversations' => $conversations,
            'selectedConversation' => null,
            'followUpView' => 'index',
        ]));
    }

    public function studentShow(Request $request, MedicalConversation $conversation): View
    {
        $athlete = $this->authorizeStudent($request);
        abort_unless($conversation->isParticipant($athlete), 403);

        $conversation->load(['creator', 'messages.sender']);
        $conversation->markReadFor($athlete);

        return view('student.page', $this->studentPageData($athlete, 'medical-follow-ups', [
            'heading' => 'Medical Follow-up',
            'subtitle' => $conversation->subject,
            'conversations' => collect(),
            'selectedConversation' => $conversation,
            'followUpView' => 'show',
        ]));
    }

    public function studentReply(Request $request, MedicalConversation $conversation): RedirectResponse
    {
        $athlete = $this->authorizeStudent($request);
        abort_unless($conversation->isParticipant($athlete), 403);
        abort_if($conversation->status === MedicalConversation::STATUS_RESOLVED, 422, 'This conversation has been resolved. Contact the sports office if you need further assistance.');

        $maxKb = max(1024, (int) config('medical.certificate_max_kb', 5120));
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.$maxKb],
        ], [
            'attachment.mimes' => 'Attachments must be a PDF, JPG, JPEG, or PNG file.',
        ]);

        $path = null;
        $name = null;
        $type = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $extension = $file->guessExtension() ?: $file->getClientOriginalExtension();
            $path = $file->storeAs(self::ATTACHMENT_FOLDER, Str::uuid().'.'.$extension, 'private');
            $name = Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 190, '');
            $type = $file->getMimeType() ?: 'application/octet-stream';
        }

        try {
            DB::transaction(function () use ($conversation, $athlete, $validated, $path, $name, $type) {
                $conversation->messages()->create([
                    'sender_id' => $athlete->id,
                    'message' => $validated['message'],
                    'attachment_path' => $path,
                    'attachment_name' => $name,
                    'attachment_type' => $type,
                ]);

                $conversation->forceFill([
                    'status' => MedicalConversation::STATUS_RESPONDED,
                ])->save();

                $recipients = User::query()->where('role', 'Administrator')->get();
                foreach ($recipients as $admin) {
                    $admin->notify(new MedicalFollowUpNotification([
                        'title' => 'Medical Follow-up Response',
                        'message' => 'A student-athlete replied to a medical follow-up conversation. Review the response in Medical Follow-ups.',
                        'url' => route('admin.medical.follow-ups.show', $conversation),
                        'medical_conversation_id' => $conversation->id,
                        'dedupe_key' => 'medical-follow-up-reply|'.$conversation->id.'|'.now()->timestamp,
                    ]));
                }
            });
        } catch (\Throwable $e) {
            if ($path && Storage::disk('private')->exists($path)) {
                Storage::disk('private')->delete($path);
            }
            throw $e;
        }

        return redirect()->route('student.medical-follow-ups.show', $conversation)
            ->with('success', 'Your response has been sent.');
    }

    public function downloadAttachment(Request $request, MedicalConversation $conversation, MedicalMessage $message)
    {
        $user = $request->user();
        abort_unless($user && $conversation->isParticipant($user), 403);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);
        abort_unless($message->hasAttachment(), 404);

        return Storage::disk('private')->download($message->attachment_path, $message->attachmentDownloadName(), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function viewAttachment(Request $request, MedicalConversation $conversation, MedicalMessage $message)
    {
        $user = $request->user();
        abort_unless($user && $conversation->isParticipant($user), 403);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);
        abort_unless($message->hasAttachment(), 404);

        return Storage::disk('private')->response($message->attachment_path, $message->attachmentDownloadName(), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    private function authorizeAdmin(): void
    {
        abort_unless(Gate::allows('manage-medical-follow-ups'), 403);
    }

    private function authorizeStudent(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->role === 'Student', 403);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function studentPageData(User $athlete, string $page, array $data = []): array
    {
        return array_merge([
            'page' => $page,
            'heading' => 'Medical Follow-ups',
            'subtitle' => 'Private medical messages from the sports office',
            'action' => null,
            'active' => $page,
            'navItems' => PortalNavigation::student(),
            'athlete' => $athlete,
            'roleLabel' => 'Athlete/User Portal',
            'userName' => $athlete->name,
            'userRole' => $athlete->sport?->name ?? 'Unassigned sport',
            'studentApplication' => $athlete->applications()->with('sportCategory')->latest()->first(),
            'upcomingStudentEvents' => collect(),
        ], $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminPortalData(): array
    {
        $user = auth()->user();

        return [
            'title' => 'Medical Follow-ups',
            'navItems' => PortalNavigation::admin(),
            'active' => 'medical',
            'roleLabel' => 'Admin Portal',
            'userName' => $user?->name,
            'userRole' => $user?->role,
            'announcements' => [],
        ];
    }
}
