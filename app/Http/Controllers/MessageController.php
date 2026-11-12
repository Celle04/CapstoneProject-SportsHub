<?php

namespace App\Http\Controllers;

use App\Models\MedicalFollowUp;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Sport;
use App\Models\User;
use App\Notifications\FollowUpAcknowledgedNotification;
use App\Notifications\MessageReceivedNotification;
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

    /**
     * The unified Messages & Follow-ups module of the sports office.
     */
    public function index(Request $request): View
    {
        $this->ensureAdministrator();

        $tab = $this->tab($request);

        $messages = Message::query()
            ->where('sender_id', auth()->id())
            ->whereNull('reply_to_message_id')
            ->with(['event', 'sport'])
            ->withCount('recipients')
            ->withCount(['recipients as read_recipients_count' => fn (Builder $recipients) => $recipients->where('is_read', true)])
            ->when($tab === 'general', fn (Builder $query) => $query->where('category', Message::CATEGORY_GENERAL))
            ->when($tab === 'medical', fn (Builder $query) => $query->where('category', Message::CATEGORY_MEDICAL_FOLLOW_UP))
            ->when(in_array($tab, ['sent', 'drafts'], true), fn (Builder $query) => $query->where('status', $tab === 'sent' ? 'sent' : 'draft'))
            ->when($request->filled('event_id'), fn (Builder $query) => $query->where('event_id', $request->integer('event_id')))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = trim((string) $request->input('search'));
                $query->where(function (Builder $matching) use ($search): void {
                    $matching->where('subject', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%");
                });
            });

        $sort = $request->query('sort') === 'oldest' ? 'asc' : 'desc';
        $messages = $messages->orderByRaw('COALESCE(sent_at, updated_at) '.$sort)->orderByDesc('id')->paginate(15)->withQueryString();

        $followUps = MedicalFollowUp::query()
            ->with(['message.sender', 'message.sport', 'recipients.recipient'])
            ->archived($request->boolean('archived'))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = trim((string) $request->input('search'));
                $query->where(function (Builder $matching) use ($search): void {
                    $matching->whereHas('message', fn (Builder $message) => $message
                        ->where('subject', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%"))
                        ->orWhereHas('recipients.recipient', fn (Builder $athlete) => $athlete
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('student_id', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return $this->adminView('index', [
            'messages' => $messages,
            'followUps' => $followUps,
            'messageTab' => $tab,
            'messageSummary' => $this->summary(),
            'showArchivedFollowUps' => $request->boolean('archived'),
        ]);
    }

    /**
     * The compose screen of the unified module.
     */
    public function create(Request $request): View
    {
        $this->ensureAdministrator();

        $message = new Message([
            'category' => $request->query('category', Message::CATEGORY_GENERAL),
            'message_type' => $request->query('message_type', 'general'),
            'priority' => $request->query('priority', 'Normal'),
            'recipient_type' => $request->query('recipient_type', 'individual'),
            'sport_id' => $request->query('sport_id'),
            'event_id' => $request->query('event_id'),
            'subject' => $request->query('subject'),
            'content' => $request->query('content'),
        ]);

        if ($request->filled('event_id')) {
            $event = \App\Models\Event::with('sport')->find($request->integer('event_id'));
            if ($event) {
                $message->setRelation('event', $event);
                $message->event_id = $event->id;
                if (! $request->filled('subject')) {
                    $message->subject = $event->title;
                }
                if (! $request->filled('recipient_type')) {
                    $message->recipient_type = $event->sport_id ? 'sport' : 'all';
                    $message->sport_id = $event->sport_id;
                }
                if (! $request->filled('message_type')) {
                    $message->message_type = in_array($event->event_type, ['Competition', 'Tournament', 'District Meet', 'Regional Meet', 'Friendly Match'], true)
                        ? 'competition'
                        : 'sports_event';
                }
            }
        }

        return $this->adminView('compose', [
            'messageRecord' => $message,
            'followUpRecord' => $message->followUp,
        ]);
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

    /**
     * The detail of a message. A medical follow-up shows its schedule and the
     * acknowledgment and completion state of every athlete it reached.
     *
     * Every administrator of the sports office may open a delivered message,
     * so a follow-up can be monitored by whoever is on duty. Drafts stay
     * private to the administrator who wrote them.
     */
    public function show(Message $message): View
    {
        $this->ensureAdministrator();
        $message = $message->replyTo ?? $message;
        abort_unless($message->status === 'sent', 404);

        $message->load(['sender', 'sport', 'event', 'recipients.recipient.sport', 'followUp.recipients.recipient.sport', 'replies.sender', 'replies.recipients.recipient'])
            ->loadCount(['recipients', 'recipients as read_recipients_count' => fn (Builder $recipients) => $recipients->where('is_read', true)]);
        $message->setRelation('replies', $message->replies->sortBy('sent_at')->values());

        return $this->adminView('show', [
            'messageRecord' => $message,
            'followUpRecord' => $message->followUp,
        ]);
    }

    /**
     * The sports office replies to one recipient of a general message.
     * The reply is attached to the original message but delivered only to
     * the selected recipient.
     */
    public function adminReply(Request $request, Message $message): RedirectResponse
    {
        $this->ensureAdministrator();

        $message = $message->replyTo ?? $message;
        abort_unless(
            $message->status === 'sent'
                && $message->category === Message::CATEGORY_GENERAL
                && ! $message->event_id
                && $message->sender?->role === 'Administrator',
            404,
        );

        $validated = $request->validate([
            'recipient_id' => [
                'required',
                'integer',
                Rule::exists('message_recipients', 'recipient_id')->where('message_id', $message->id),
            ],
            'content' => ['required', 'string', 'max:5000'],
        ], [
            'recipient_id.exists' => 'Choose an athlete who received this message.',
            'content.required' => 'Write a reply before sending it.',
        ]);

        $recipient = $message->recipients()
            ->where('recipient_id', $validated['recipient_id'])
            ->with('recipient')
            ->firstOrFail()
            ->recipient;

        abort_unless($recipient?->role === 'Student', 404);

        $reply = DB::transaction(function () use ($validated, $message, $recipient): Message {
            $reply = new Message([
                'sender_id' => auth()->id(),
                'subject' => $message->subject,
                'content' => $validated['content'],
                'message_type' => 'general',
                'category' => Message::CATEGORY_GENERAL,
                'priority' => 'Normal',
                'status' => 'sent',
                'recipient_type' => 'individual',
                'reply_to_message_id' => $message->id,
                'sent_at' => now(),
            ]);
            $reply->save();
            $reply->recipients()->create(['recipient_id' => $recipient->id]);
            $recipient->notify(new MessageReceivedNotification($reply));

            return $reply;
        });

        return redirect()->route('admin.messages.show', $message)->with('success', 'Reply sent to '.$recipient->name.'.');
    }

    public function edit(Message $message): View
    {
        $this->ensureAdministrator();
        $this->ensureOwnDraft($message);
        $message->load(['event', 'followUp']);

        return $this->adminView('compose', [
            'messageRecord' => $message,
            'followUpRecord' => $message->followUp,
        ]);
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

    /**
     * The inbox of the athlete: general messages and medical follow-ups in
     * one list.
     */
    public function inbox(Request $request): View|RedirectResponse
    {
        // The sports office follows the same module from the other side, so an
        // administrator who opens an athlete address is sent to the
        // administrator list instead of a forbidden page.
        if (auth()->user()?->role === 'Administrator') {
            return redirect()->route('admin.messages');
        }

        $student = $this->ensureStudent();
        $tab = $request->query('tab', 'all');

        $deliveries = MessageRecipient::query()
            ->where('recipient_id', $student->id)
            ->whereHas('message', fn (Builder $messages) => $messages->where('status', 'sent'))
            ->when($tab === 'general', fn (Builder $query) => $query->whereHas('message', fn (Builder $message) => $message->where('category', Message::CATEGORY_GENERAL)))
            ->when($tab === 'medical', fn (Builder $query) => $query->whereHas('message', fn (Builder $message) => $message->where('category', Message::CATEGORY_MEDICAL_FOLLOW_UP)))
            ->when($tab === 'unread', fn (Builder $query) => $query->where('is_read', false))
            ->with('message.sender', 'message.event', 'message.followUp')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return $this->studentView('index', [
            'messages' => $deliveries,
            'messageTab' => $tab,
            'messageSummary' => $this->studentSummary($student),
        ]);
    }

    /**
     * The detail of a received message, including the medical instructions
     * and the acknowledgment button of a follow-up.
     */
    public function studentShow(Message $message): View|RedirectResponse
    {
        // A notification, a copied address, or a bookmark can send the sports
        // office to an athlete page. The same message is opened in the
        // administrator module, where the sports office is allowed.
        if (auth()->user()?->role === 'Administrator') {
            return redirect()->route('admin.messages.show', $message);
        }

        $student = $this->ensureStudent();
        $delivery = MessageRecipient::query()
            ->where('recipient_id', $student->id)
            ->whereHas('message', fn (Builder $messages) => $messages->where('status', 'sent'))
            ->where('message_id', $message->id)
            ->with('message.replyTo')
            ->firstOrFail();
        $rootMessage = $delivery->message->replyTo ?? $delivery->message;
        $rootDelivery = MessageRecipient::query()
            ->where('recipient_id', $student->id)
            ->where('message_id', $rootMessage->id)
            ->firstOrFail();

        $rootMessage->load([
            'sender',
            'event',
            'followUp',
            'replies.sender',
            'replies.recipients',
        ]);
        $threadReplies = $rootMessage->replies
            ->filter(fn (Message $reply) => $reply->sender_id === $student->id
                || $reply->recipients->contains('recipient_id', $student->id))
            ->sortBy('sent_at')
            ->values();

        DB::transaction(function () use ($delivery, $rootDelivery, $rootMessage, $student): void {
            MessageRecipient::markReadFor($delivery->message_id, $student->id);
            MessageRecipient::markReadFor($rootDelivery->message_id, $student->id);

            $followUp = $rootMessage->followUp;
            if ($followUp) {
                $recipient = $followUp->recipients()->where('recipient_id', $student->id)->first();
                if ($recipient && ! $recipient->isRead()) {
                    $recipient->forceFill(['read_at' => now(), 'status' => $recipient->isAcknowledged() ? $recipient->status : MedicalFollowUp::STATUS_READ])->save();
                }

                $followUp->syncStatus();
            }

            $student->unreadNotifications
                ->filter(fn ($notification) => in_array((int) data_get($notification->data, 'message_id'), [
                    $delivery->message_id,
                    $rootDelivery->message_id,
                ], true))
                ->each->markAsRead();
        });

        return $this->studentView('show', [
            'message' => $rootMessage,
            'messageRecipient' => $rootDelivery->fresh(),
            'followUpRecipient' => $rootMessage->followUp?->recipients()->where('recipient_id', $student->id)->first(),
            'threadReplies' => $threadReplies,
        ]);
    }

    /**
     * The athlete confirms that they received a follow-up.
     *
     * Opening a message is never an acknowledgment: only this action records
     * it, with the time it happened.
     */
    public function acknowledge(Request $request, Message $message): RedirectResponse
    {
        $student = $this->ensureStudent();

        $delivery = MessageRecipient::query()
            ->where('recipient_id', $student->id)
            ->where('message_id', $message->id)
            ->whereHas('message', fn (Builder $messages) => $messages->where('status', 'sent'))
            ->firstOrFail();

        $followUp = $message->followUp;

        abort_unless($followUp, 404);
        abort_if($followUp->status === MedicalFollowUp::STATUS_CANCELLED, 422, 'This medical follow-up was cancelled by the sports office.');

        $recipient = $followUp->recipients()->where('recipient_id', $student->id)->firstOrFail();

        DB::transaction(function () use ($followUp, $recipient, $student): void {
            if (! $recipient->isAcknowledged()) {
                $recipient->forceFill([
                    'acknowledged_at' => now(),
                    'status' => MedicalFollowUp::STATUS_ACKNOWLEDGED,
                ])->save();
            }

            $followUp->syncStatus();
        });

        $sender = $message->sender;

        if ($sender && $sender->role === 'Administrator') {
            $sender->notify(new FollowUpAcknowledgedNotification($followUp, $student));
        }

        return back()->with('success', 'Acknowledged. The sports office can now see your confirmation.');
    }

    /**
     * The athlete answers a follow-up. The answer is kept in the same thread
     * as the message it belongs to.
     */
    public function reply(Request $request, Message $message): RedirectResponse
    {
        $student = $this->ensureStudent();
        $message = $message->replyTo ?? $message;

        $delivery = MessageRecipient::query()
            ->where('recipient_id', $student->id)
            ->where('message_id', $message->id)
            ->whereHas('message', fn (Builder $messages) => $messages->where('status', 'sent'))
            ->firstOrFail();

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ], [
            'content.required' => 'Write a reply before sending it.',
        ]);

        $followUp = $message->followUp;
        if (! $followUp) {
            abort_unless($message->category === Message::CATEGORY_GENERAL && ! $message->event_id, 404);
            abort_unless($message->sender?->role === 'Administrator', 404);

            $reply = DB::transaction(function () use ($validated, $student, $message): Message {
                $reply = new Message([
                    'sender_id' => $student->id,
                    'subject' => $message->subject,
                    'content' => $validated['content'],
                    'message_type' => 'general',
                    'category' => Message::CATEGORY_GENERAL,
                    'priority' => 'Normal',
                    'status' => 'sent',
                    'recipient_type' => 'individual',
                    'reply_to_message_id' => $message->id,
                    'sent_at' => now(),
                ]);
                $reply->save();
                $reply->recipients()->create(['recipient_id' => $message->sender_id]);
                $message->sender->notify(new MessageReceivedNotification($reply));

                return $reply;
            });

            return redirect()->route('student.messages.show', $message)->with('success', 'Reply sent to the sports office.');
        }

        abort_if($followUp->status === MedicalFollowUp::STATUS_CANCELLED, 422, 'This medical follow-up was cancelled by the sports office.');

        DB::transaction(function () use ($validated, $student, $message, $followUp, $delivery): void {
            $reply = new Message([
                'sender_id' => $student->id,
                'subject' => $message->subject,
                'content' => $validated['content'],
                'message_type' => 'medical_follow_up',
                'category' => Message::CATEGORY_MEDICAL_FOLLOW_UP,
                'priority' => 'Normal',
                'status' => 'sent',
                'recipient_type' => 'individual',
                'reply_to_message_id' => $message->id,
                'sent_at' => now(),
            ]);
            $reply->save();
            $reply->recipients()->create(['recipient_id' => $message->sender_id]);

            $recipient = $followUp->recipients()->where('recipient_id', $student->id)->first();
            if ($recipient && ! $recipient->isAcknowledged()) {
                $recipient->forceFill([
                    'acknowledged_at' => now(),
                    'status' => MedicalFollowUp::STATUS_ACKNOWLEDGED,
                ])->save();
            }

            $followUp->syncStatus();
        });

        $sender = $message->sender;

        if ($sender && $sender->role === 'Administrator') {
            $sender->notify(new FollowUpAcknowledgedNotification($followUp, $student));
        }

        return back()->with('success', 'Reply sent to the sports office.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $sending = $request->input('action') === 'send';
        $category = $request->input('category', Message::CATEGORY_GENERAL);
        $isFollowUp = $category === Message::CATEGORY_MEDICAL_FOLLOW_UP;
        $recipientType = $request->input('recipient_type', 'individual');

        $validated = $request->validate([
            'subject' => [$sending ? 'required' : 'nullable', 'string', 'max:255'],
            'content' => [$sending ? 'required' : 'nullable', 'string', 'max:20000'],
            'category' => ['required', Rule::in(Message::CATEGORIES)],
            'message_type' => ['nullable', Rule::in(['general', 'sports_event', 'competition', 'event_update'])],
            'priority' => ['required', Rule::in(['Normal', 'Important', 'Urgent'])],
            'recipient_type' => ['required', Rule::in(['individual', 'all', 'sport', 'selected'])],
            'action' => ['required', Rule::in(['draft', 'send'])],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'athlete_id' => [$sending && $recipientType === 'individual' ? 'required' : 'nullable', 'integer', 'exists:users,id'],
            'recipient_ids' => [$sending && $recipientType === 'selected' ? 'required' : 'nullable', 'array', $sending && $recipientType === 'selected' ? 'min:1' : 'nullable'],
            'recipient_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'sport_id' => [$sending && $recipientType === 'sport' ? 'required' : 'nullable', 'integer', 'exists:sports,id'],
            'follow_up_type' => [$isFollowUp ? 'required' : 'nullable', Rule::in(MedicalFollowUp::TYPES)],
            'scheduled_date' => [$isFollowUp && $sending ? 'required' : 'nullable', 'date'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'additional_instructions' => ['nullable', 'string', 'max:5000'],
            'response_required' => ['nullable', 'boolean'],
        ], [
            'follow_up_type.required' => 'Choose the type of medical follow-up.',
            'scheduled_date.required' => 'Set the date of the checkup or follow-up.',
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

    /**
     * The tab of the unified module, keeping the older query parameters
     * working.
     */
    private function tab(Request $request): string
    {
        $tab = (string) $request->query('tab', '');

        if (in_array($tab, ['all', 'general', 'medical', 'sent', 'drafts'], true)) {
            return $tab;
        }

        return match ((string) $request->query('status', '')) {
            'sent' => 'sent',
            'draft' => 'drafts',
            default => match ((string) $request->query('filter', '')) {
                'medical' => 'medical',
                default => 'all',
            },
        };
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        $sent = Message::where('sender_id', auth()->id())->where('status', 'sent')->whereNull('reply_to_message_id');

        return [
            'sent' => (clone $sent)->count(),
            'general' => (clone $sent)->where('category', Message::CATEGORY_GENERAL)->count(),
            'medical' => (clone $sent)->where('category', Message::CATEGORY_MEDICAL_FOLLOW_UP)->count(),
            'recipients' => DB::table('message_recipients')
                ->join('messages', 'messages.id', '=', 'message_recipients.message_id')
                ->where('messages.sender_id', auth()->id())
                ->where('messages.status', 'sent')
                ->whereNull('messages.reply_to_message_id')
                ->count(),
            'read' => DB::table('message_recipients')
                ->join('messages', 'messages.id', '=', 'message_recipients.message_id')
                ->where('messages.sender_id', auth()->id())
                ->where('messages.status', 'sent')
                ->whereNull('messages.reply_to_message_id')
                ->where('message_recipients.is_read', true)
                ->count(),
            'unread' => DB::table('message_recipients')
                ->join('messages', 'messages.id', '=', 'message_recipients.message_id')
                ->where('messages.sender_id', auth()->id())
                ->where('messages.status', 'sent')
                ->whereNull('messages.reply_to_message_id')
                ->where('message_recipients.is_read', false)
                ->count(),
            'drafts' => Message::where('sender_id', auth()->id())->where('status', 'draft')->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function studentSummary(User $student): array
    {
        $deliveries = MessageRecipient::query()
            ->where('recipient_id', $student->id)
            ->whereHas('message', fn (Builder $messages) => $messages->where('status', 'sent'));

        return [
            'total' => (clone $deliveries)->count(),
            'general' => (clone $deliveries)->whereHas('message', fn (Builder $message) => $message->where('category', Message::CATEGORY_GENERAL))->count(),
            'medical' => (clone $deliveries)->whereHas('message', fn (Builder $message) => $message->where('category', Message::CATEGORY_MEDICAL_FOLLOW_UP))->count(),
            'unread' => (clone $deliveries)->where('is_read', false)->count(),
        ];
    }

    private function adminView(string $view, array $data = []): View
    {
        return view('admin.page', array_merge([
            'page' => 'messages',
            'heading' => 'Messages & Follow-ups',
            'subtitle' => 'Communicate with student-athletes and track medical follow-ups',
            'active' => 'messages',
            'navItems' => PortalNavigation::admin(),
            'roleLabel' => 'Admin Portal',
            'userName' => auth()->user()->name,
            'userRole' => 'Administrator',
            'messageView' => $view,
            'activeStudents' => User::query()->where('role', 'Student')->where('status', 'Active')->with('sport')->orderBy('name')->get(),
            'sports' => Sport::orderBy('name')->get(),
            'followUpTypes' => MedicalFollowUp::TYPES,
        ], $data));
    }

    private function studentView(string $view, array $data = []): View
    {
        $student = $this->ensureStudent();

        return view('student.page', array_merge([
            'page' => 'messages',
            'heading' => 'Messages & Follow-ups',
            'subtitle' => 'Messages, reminders and medical follow-ups from the Sports Office',
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
