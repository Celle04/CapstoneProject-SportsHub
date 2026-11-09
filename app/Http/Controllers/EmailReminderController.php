<?php

namespace App\Http\Controllers;

use App\Mail\EmailReminderMail;
use App\Models\EmailReminder;
use App\Models\Sport;
use App\Models\User;
use App\Services\EmailReminderService;
use App\Support\PortalNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class EmailReminderController extends Controller
{
    public function __construct(private readonly EmailReminderService $reminders)
    {
    }

    public function index(Request $request): View
    {
        $this->ensureAdministrator();

        $reminders = EmailReminder::query()
            ->with(['admin', 'sport'])
            ->withCount([
                'recipients',
                'recipients as sent_recipients_count' => fn (Builder $query) => $query->where('status', 'sent'),
                'recipients as failed_recipients_count' => fn (Builder $query) => $query->where('status', 'failed'),
                'recipients as pending_recipients_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'queued']),
            ])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('category'), fn (Builder $query) => $query->where('category', $request->string('category')->toString()))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where(function (Builder $matching) use ($search): void {
                    $matching->where('subject', 'like', "%{$search}%")
                        ->orWhere('message', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return $this->adminView('index', [
            'reminders' => $reminders,
            'summary' => [
                'total' => EmailReminder::count(),
                'sent' => EmailReminder::whereIn('status', ['sent', 'partial'])->count(),
                'failed' => EmailReminder::where('status', 'failed')->count(),
                'queued' => EmailReminder::whereIn('status', ['queued', 'sending'])->count(),
                'recipients_sent' => (int) EmailReminder::sum('sent_count'),
            ],
        ]);
    }

    public function create(): View
    {
        $this->ensureAdministrator();

        return $this->adminView('compose', [
            'reminder' => new EmailReminder(['category' => 'General Reminder', 'recipient_type' => 'all']),
        ]);
    }

    public function preview(Request $request): Response
    {
        $this->ensureAdministrator();
        $data = $this->validateReminder($request, false);
        $reminder = new EmailReminder($data);

        $athleteName = 'Sample Athlete';
        if (($data['recipient_type'] ?? null) === 'individual' && ! empty($data['athlete_id'])) {
            $athleteName = User::where('role', 'Student')->find($data['athlete_id'])?->name ?: $athleteName;
        } elseif (($data['recipient_type'] ?? null) === 'selected' && ! empty($data['recipient_ids'][0])) {
            $athleteName = User::where('role', 'Student')->find($data['recipient_ids'][0])?->name ?: $athleteName;
        }

        $html = (new EmailReminderMail($reminder, $athleteName))->render();

        return response($html);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureAdministrator();
        $this->throttle($request, 'email-reminders:send:'.auth()->id(), 12, 60);

        $validated = $this->validateReminder($request, true);

        try {
            $reminder = $this->reminders->queueReminder(auth()->user(), $validated);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['send' => 'The reminder could not be queued. Check your queue and mail configuration, then try again.']);
        }

        return redirect()->route('admin.email-reminders.show', $reminder)
            ->with('success', 'Email reminder queued for '.$reminder->recipient_count.' recipient(s). Delivery is processed in the background.');
    }

    public function show(EmailReminder $emailReminder): View
    {
        $this->ensureAdministrator();

        $emailReminder->load(['admin', 'sport'])->loadCount([
            'recipients',
            'recipients as sent_recipients_count' => fn (Builder $query) => $query->where('status', 'sent'),
            'recipients as failed_recipients_count' => fn (Builder $query) => $query->where('status', 'failed'),
            'recipients as pending_recipients_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'queued']),
        ]);

        $recipients = $emailReminder->recipients()
            ->with('athlete:id,name,student_id,sport_id')
            ->with('athlete.sport:id,name')
            ->latest()
            ->paginate(25, ['*'], 'recipients_page')
            ->withQueryString();

        return $this->adminView('show', [
            'reminder' => $emailReminder,
            'recipientRows' => $recipients,
        ]);
    }

    public function sendTest(Request $request): RedirectResponse
    {
        $this->ensureAdministrator();
        $this->throttle($request, 'email-reminders:test:'.auth()->id(), 6, 60);

        $validated = $request->validate([
            'test_email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $this->reminders->sendTest(auth()->user(), $validated['test_email']);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['test_email' => 'The test email could not be sent. Check MAIL_* or HTTPS provider settings and try again.']);
        }

        return back()->with('success', 'Test email sent to '.$validated['test_email'].'.');
    }

    public function retry(EmailReminder $emailReminder): RedirectResponse
    {
        $this->ensureAdministrator();
        $this->throttle($request = request(), 'email-reminders:retry:'.auth()->id(), 10, 60);

        $count = $this->reminders->retryFailed($emailReminder);

        if ($count < 1) {
            return back()->withErrors(['retry' => 'There are no failed recipients to retry for this reminder.']);
        }

        return back()->with('success', 'Retry queued for '.$count.' failed recipient(s).');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateReminder(Request $request, bool $sending): array
    {
        $rules = [
            'category' => ['required', Rule::in(EmailReminder::CATEGORIES)],
            'subject' => [$sending ? 'required' : 'nullable', 'string', 'max:255'],
            'message' => [$sending ? 'required' : 'nullable', 'string', 'max:20000'],
            'recipient_type' => ['required', Rule::in(array_keys(EmailReminder::RECIPIENT_TYPES))],
            'athlete_id' => ['nullable', 'integer', 'exists:users,id'],
            'recipient_ids' => ['nullable', 'array'],
            'recipient_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'sport_id' => ['nullable', 'integer', 'exists:sports,id'],
            'relevant_date' => ['nullable', 'date'],
            'relevant_time' => ['nullable', 'date_format:H:i'],
        ];

        $validated = $request->validate($rules);

        if ($sending) {
            $type = $validated['recipient_type'];
            if ($type === 'individual' && empty($validated['athlete_id'])) {
                throw ValidationException::withMessages(['athlete_id' => 'Select an athlete to receive this reminder.']);
            }
            if ($type === 'selected' && empty($validated['recipient_ids'])) {
                throw ValidationException::withMessages(['recipient_ids' => 'Select at least one athlete to receive this reminder.']);
            }
            if (in_array($type, ['sport', 'team'], true) && empty($validated['sport_id'])) {
                throw ValidationException::withMessages(['sport_id' => 'Select a sport or team.']);
            }

            $this->validateRecipientAccounts($validated);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateRecipientAccounts(array $validated): void
    {
        $ids = [];
        if ($validated['recipient_type'] === 'individual' && ! empty($validated['athlete_id'])) {
            $ids = [(int) $validated['athlete_id']];
        }
        if ($validated['recipient_type'] === 'selected') {
            $ids = array_values(array_unique(array_map('intval', $validated['recipient_ids'] ?? [])));
        }
        if ($ids === []) {
            return;
        }

        $count = User::query()->where('role', 'Student')->whereIn('id', $ids)->count();
        if ($count !== count($ids)) {
            throw ValidationException::withMessages(['recipients' => 'Only registered student-athlete accounts can receive email reminders.']);
        }
    }

    private function throttle(Request $request, string $key, int $maxAttempts, int $decaySeconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                'send' => 'Too many email reminder actions. Please wait a moment before trying again.',
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    private function adminView(string $view, array $data = []): View
    {
        return view('admin.page', array_merge([
            'page' => 'email-reminders',
            'heading' => 'Email Reminders',
            'subtitle' => 'Send and track email reminders for athletes and sports activities',
            'active' => 'email-reminders',
            'navItems' => PortalNavigation::admin(),
            'roleLabel' => 'Admin Portal',
            'userName' => auth()->user()->name,
            'userRole' => 'Administrator',
            'emailReminderView' => $view,
            'categories' => EmailReminder::CATEGORIES,
            'activeStudents' => User::query()->where('role', 'Student')->whereNotNull('email')->where('email', '!=', '')->with('sport:id,name')->orderBy('name')->get(['id', 'name', 'student_id', 'sport_id', 'email']),
            'sports' => Sport::orderBy('name')->get(['id', 'name']),
        ], $data));
    }

    private function ensureAdministrator(): void
    {
        abort_unless(auth()->user()?->role === 'Administrator', 403);
    }
}
