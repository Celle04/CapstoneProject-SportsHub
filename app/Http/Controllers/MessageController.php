<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Sport;
use App\Models\User;
use App\Services\MessageDeliveryService;
use App\Support\PortalNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MessageController extends Controller
{
    public function __construct(private readonly MessageDeliveryService $delivery)
    {
    }

    public function index(Request $request): View
    {
        $this->ensureAdministrator();

        $query = Message::query()
            ->where('sender_id', auth()->id())
            ->withCount('recipients')
            ->withCount(['recipients as read_recipients_count' => fn (Builder $recipients) => $recipients->where('is_read', true)])
            ->when(in_array($request->query('status'), ['sent', 'draft'], true), fn (Builder $messages) => $messages->where('status', $request->query('status')))
            ->when($request->query('filter') === 'medical', fn (Builder $messages) => $messages->where('message_type', 'medical_reminder'))
            ->when($request->filled('search'), function (Builder $messages) use ($request): void {
                $search = trim($request->string('search')->toString());
                $messages->where(function (Builder $matching) use ($search): void {
                    $matching->where('subject', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%");
                });
            });

        $sort = $request->query('sort') === 'oldest' ? 'asc' : 'desc';
        $messages = $query->orderByRaw('COALESCE(sent_at, updated_at) '.$sort)->orderByDesc('id')->paginate(15)->withQueryString();

        return $this->adminView('index', [
            'messages' => $messages,
            'messageSummary' => [
                'sent' => Message::where('sender_id', auth()->id())->where('status', 'sent')->count(),
                'recipients' => DB::table('message_recipients')
                    ->join('messages', 'messages.id', '=', 'message_recipients.message_id')
                    ->where('messages.sender_id', auth()->id())
                    ->where('messages.status', 'sent')
                    ->count(),
                'read' => DB::table('message_recipients')
                    ->join('messages', 'messages.id', '=', 'message_recipients.message_id')
                    ->where('messages.sender_id', auth()->id())
                    ->where('messages.status', 'sent')
                    ->where('message_recipients.is_read', true)
                    ->count(),
                'unread' => DB::table('message_recipients')
                    ->join('messages', 'messages.id', '=', 'message_recipients.message_id')
                    ->where('messages.sender_id', auth()->id())
                    ->where('messages.status', 'sent')
                    ->where('message_recipients.is_read', false)
                    ->count(),
                'drafts' => Message::where('sender_id', auth()->id())->where('status', 'draft')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        $this->ensureAdministrator();

        return $this->adminView('compose', ['messageRecord' => new Message()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureAdministrator();
        $validated = $this->validated($request);

        if ($request->input('action') === 'send') {
            $message = $this->delivery->send(auth()->user(), $validated);

            return redirect()->route('admin.messages.show', $message)->with('success', 'Message sent to '.$message->recipients()->count().' athlete(s).');
        }

        $message = $this->delivery->saveDraft(auth()->user(), $validated);

        return redirect()->route('admin.messages.edit', $message)->with('success', 'Draft saved.');
    }

    public function show(Message $message): View
    {
        $this->ensureAdministrator();
        abort_unless($message->sender_id === auth()->id(), 404);
        abort_unless($message->status === 'sent', 404);

        $message->load(['sender', 'sport', 'recipients.recipient.sport'])
            ->loadCount(['recipients', 'recipients as read_recipients_count' => fn (Builder $recipients) => $recipients->where('is_read', true)]);

        return $this->adminView('show', ['messageRecord' => $message]);
    }

    public function edit(Message $message): View
    {
        $this->ensureAdministrator();
        $this->ensureOwnDraft($message);

        return $this->adminView('compose', ['messageRecord' => $message]);
    }

    public function update(Request $request, Message $message): RedirectResponse
    {
        $this->ensureAdministrator();
        $this->ensureOwnDraft($message);
        $validated = $this->validated($request);

        if ($request->input('action') === 'send') {
            $this->delivery->send(auth()->user(), $validated, $message);

            return redirect()->route('admin.messages.show', $message)->with('success', 'Message sent to '.$message->recipients()->count().' athlete(s).');
        }

        $this->delivery->saveDraft(auth()->user(), $validated, $message);

        return back()->with('success', 'Draft saved.');
    }

    public function destroy(Message $message): RedirectResponse
    {
        $this->ensureAdministrator();
        $this->ensureOwnDraft($message);
        $message->delete();

        return redirect()->route('admin.messages')->with('success', 'Draft deleted.');
    }

    public function inbox(): View
    {
        $student = $this->ensureStudent();
        $messages = MessageRecipient::query()
            ->where('recipient_id', $student->id)
            ->whereHas('message', fn (Builder $messages) => $messages->where('status', 'sent'))
            ->with('message.sender')
            ->latest()
            ->paginate(15);

        return $this->studentView('index', ['messages' => $messages]);
    }

    public function studentShow(Message $message): View
    {
        $student = $this->ensureStudent();
        $delivery = MessageRecipient::query()
            ->where('recipient_id', $student->id)
            ->whereHas('message', fn (Builder $messages) => $messages->where('status', 'sent'))
            ->where('message_id', $message->id)
            ->with('message.sender')
            ->firstOrFail();

        DB::transaction(function () use ($delivery, $student, $message): void {
            MessageRecipient::markReadFor($message->id, $student->id);
            $student->unreadNotifications
                ->filter(fn ($notification) => (int) data_get($notification->data, 'message_id') === $message->id)
                ->each->markAsRead();
        });

        return $this->studentView('show', [
            'message' => $delivery->message,
            'messageRecipient' => $delivery->fresh(),
        ]);
    }

    private function validated(Request $request): array
    {
        $sending = $request->input('action') === 'send';
        $medicalReminder = $request->input('message_type') === 'medical_reminder';
        $validated = $request->validate([
            'subject' => [$sending ? 'required' : 'nullable', 'string', 'max:255'],
            'content' => [$sending ? 'required' : 'nullable', 'string', 'max:20000'],
            'message_type' => ['required', Rule::in(['general', 'medical_reminder'])],
            'priority' => ['required', Rule::in(['Normal', 'Important', 'Urgent'])],
            'recipient_type' => ['required', Rule::in(['individual', 'all', 'sport', 'selected'])],
            'action' => ['required', Rule::in(['draft', 'send'])],
            'athlete_id' => [$sending && $request->input('recipient_type') === 'individual' ? 'required' : 'nullable', 'integer', 'exists:users,id'],
            'recipient_ids' => [$sending && $request->input('recipient_type') === 'selected' ? 'required' : 'nullable', 'array', $sending && $request->input('recipient_type') === 'selected' ? 'min:1' : 'nullable'],
            'recipient_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'sport_id' => [$sending && $request->input('recipient_type') === 'sport' ? 'required' : 'nullable', 'integer', 'exists:sports,id'],
            'checkup_date' => [$medicalReminder && $sending ? 'required' : 'nullable', 'date', 'after_or_equal:today'],
            'checkup_time' => [$medicalReminder && $sending ? 'required' : 'nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $recipientIds = $validated['recipient_type'] === 'selected'
            ? array_map('intval', $validated['recipient_ids'] ?? [])
            : ($validated['recipient_type'] === 'individual' && ! empty($validated['athlete_id']) ? [(int) $validated['athlete_id']] : []);

        if ($recipientIds !== []) {
            $activeCount = User::query()
                ->where('role', 'Student')
                ->where('status', 'Active')
                ->whereIn('id', $recipientIds)
                ->count();

            if ($activeCount !== count(array_unique($recipientIds))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'recipients' => 'Only active student-athlete accounts can receive messages.',
                ]);
            }
        }

        return $validated;
    }

    private function adminView(string $view, array $data = []): View
    {
        return view('admin.page', array_merge([
            'page' => 'messages',
            'heading' => 'Messages',
            'subtitle' => 'Communicate with student-athletes through SportsHub',
            'active' => 'messages',
            'navItems' => PortalNavigation::admin(),
            'roleLabel' => 'Admin Portal',
            'userName' => auth()->user()->name,
            'userRole' => 'Administrator',
            'messageView' => $view,
            'activeStudents' => User::query()->where('role', 'Student')->where('status', 'Active')->with('sport')->orderBy('name')->get(),
            'sports' => Sport::orderBy('name')->get(),
        ], $data));
    }

    private function studentView(string $view, array $data = []): View
    {
        $student = $this->ensureStudent();

        return view('student.page', array_merge([
            'page' => 'messages',
            'heading' => 'Messages',
            'subtitle' => 'Messages and checkup reminders from the Sports Office',
            'active' => 'messages',
            'navItems' => PortalNavigation::student(),
            'roleLabel' => 'Athlete/User Portal',
            'userName' => $student->name,
            'userRole' => $student->sport?->name ?? 'Unassigned sport',
            'athlete' => $student,
            'messageView' => $view,
        ], $data));
    }

    private function ensureAdministrator(): void
    {
        abort_unless(auth()->user()?->role === 'Administrator', 403);
    }

    private function ensureStudent(): User
    {
        $student = auth()->user();
        abort_unless($student?->role === 'Student', 403);

        return $student;
    }

    private function ensureOwnDraft(Message $message): void
    {
        abort_unless($message->sender_id === auth()->id() && $message->status === 'draft', 404);
    }
}
