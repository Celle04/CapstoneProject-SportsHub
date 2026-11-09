<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AttendanceSession;
use App\Models\Event;
use App\Models\Coach;
use App\Models\MessageRecipient;
use App\Models\Sport;
use App\Services\AttendanceSessionService;
use App\Services\StudentUpdateNotifier;
use App\Support\PortalNavigation;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventController extends Controller
{
    public function __construct(private readonly AttendanceSessionService $attendance)
    {
    }

    public function index(Request $request): View
    {
        $this->ensureAdministrator();

        $eventsQuery = Event::with(['sport', 'coach'])->withCount('attendanceRecords')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim($request->string('search')->toString());
                $query->where(function ($eventQuery) use ($search) {
                    $eventQuery->where('title', 'like', "%{$search}%")
                        ->orWhere('venue', 'like', "%{$search}%")
                        ->orWhereHas('sport', fn ($sportQuery) => $sportQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('coach', fn ($coachQuery) => $coachQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('sport_id'), fn ($query) => $query->where('sport_id', $request->integer('sport_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('event_type'), fn ($query) => $query->where('event_type', $request->string('event_type')->toString()))
            ->when($request->filled('venue'), fn ($query) => $query->where('venue', $request->string('venue')->toString()))
            ->when($request->filled('date'), fn ($query) => $query->whereDate('starts_at', $request->input('date')));

        $events = $eventsQuery->orderBy('starts_at')->get();

        return view('events.index', $this->portalData([
            'events' => $events,
            'sports' => Sport::orderBy('name')->get(),
            'coaches' => Coach::orderBy('name')->get(),
            'venues' => Event::query()->whereNotNull('venue')->distinct()->orderBy('venue')->pluck('venue'),
            'eventTypes' => self::eventTypes(),
            'statuses' => self::statuses(),
            'summary' => [
                'total' => Event::count(),
                'scheduled' => Event::where('status', 'Scheduled')->count(),
                'ongoing' => Event::where('status', 'Ongoing')->count(),
                'completed' => Event::where('status', 'Completed')->count(),
                'cancelled' => Event::where('status', 'Cancelled')->count(),
            ],
            'upcomingEvents' => $events->filter(fn ($event) => in_array($event->status, ['Scheduled', 'Ongoing'], true) && $event->starts_at->greaterThanOrEqualTo(now()))->take(5)->values(),
        ]));
    }

    public function create(): View
    {
        $this->ensureAdministrator();

        return view('events.create', $this->portalData(['sports' => Sport::orderBy('name')->get(), 'coaches' => Coach::orderBy('name')->get(), 'eventTypes' => self::eventTypes(), 'statuses' => self::statuses()]));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureAdministrator();

        $validated = $this->validated($request);
        $sendNotification = (bool) ($validated['send_event_notification'] ?? false);
        unset($validated['send_event_notification'], $validated['notify_changes'], $validated['notify_cancellation']);
        $this->ensureVenueAvailable($validated['venue'], $validated['starts_at'], $validated['ends_at']);
        $event = Event::create($validated);

        $session = $this->createAttendanceSession($event, $validated['attendance'] ?? []);

        $this->announce($event);

        if ($sendNotification) {
            return redirect()
                ->route('admin.messages.create', $this->notificationPrefill($event, 'Event created: '.$event->title))
                ->with('success', ($session
                    ? 'Event created successfully with its attendance session. The roster is ready for check-ins.'
                    : 'Event created successfully.').' Review and send the event notification below.');
        }

        if ($event->status === 'Scheduled' && $event->starts_at?->isFuture()) {
            app(StudentUpdateNotifier::class)->notifyStudents(
                $event->sport_id ? [$event->sport_id] : null,
                'New sports event',
                $event->title.' has been scheduled for '.$event->starts_at->format('M j, Y g:i A').'.',
                route('student.schedule'),
                ['event_id' => $event->id],
            );
        }

        return redirect()
            ->to($session
                ? route('admin.attendance', ['session_id' => $session->id]).'#attendance-session-detail'
                : route('events.index'))
            ->with('success', $session
                ? 'Event created successfully with its attendance session. The roster is ready for check-ins.'
                : 'Event created successfully.');
    }

    /**
     * Creates the attendance session that was filled in on the event form.
     *
     * The session is optional: when no attendance title was entered the event
     * is simply saved on its own.
     */
    /**
     * Publish the new event on the public landing page.
     *
     * Only a scheduled event is announced, and the announcement follows the
     * event: it disappears from the page when the event is called off.
     */
    private function announce(Event $event): void
    {
        if ($event->status !== 'Scheduled') {
            return;
        }

        $event->loadMissing('sport');

        Announcement::forEvent($event);
    }
    private function createAttendanceSession(Event $event, array $data): ?AttendanceSession
    {
        if (! filled($data['title'] ?? null)) {
            return null;
        }

        return $this->attendance->createSession([
            'title' => $data['title'],
            'session_date' => $data['session_date'] ?? $event->starts_at?->format('Y-m-d'),
            'start_time' => $data['start_time'] ?? null,
            'end_time' => $data['end_time'] ?? null,
            'sport_id' => $data['sport_id'] ?? null,
            'late_grace_minutes' => $data['late_grace_minutes'] ?? null,
            'venue' => $data['venue'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'Open',
        ], $event);
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationPrefill(Event $event, ?string $subject = null, ?string $message = null): array
    {
        $competitionTypes = ['Competition', 'Tournament', 'Friendly Match', 'District Meet', 'Regional Meet'];
        $category = in_array($event->event_type, $competitionTypes, true) ? 'competition' : 'sports_event';

        $lines = array_filter([
            'Event: '.$event->title,
            $event->event_type ? 'Type: '.$event->event_type : null,
            $event->starts_at ? 'Date/Time: '.$event->starts_at->format('F j, Y g:i A').($event->ends_at ? ' - '.$event->ends_at->format('g:i A') : '') : null,
            $event->venue ? 'Venue: '.$event->venue : null,
            $event->sport?->name ? 'Sport: '.$event->sport->name : null,
            $event->team_name ? 'Team: '.$event->team_name : null,
            $event->description ? 'Description: '.$event->description : null,
        ]);

        return [
            'event_id' => $event->id,
            'message_type' => $category,
            'priority' => 'Normal',
            'recipient_type' => $event->sport_id ? 'sport' : 'all',
            'sport_id' => $event->sport_id,
            'subject' => $subject ?: $event->title,
            'content' => $message ?: implode("\n", $lines),
        ];
    }

    public function edit(Event $event): View
    {
        $this->ensureAdministrator();

        return view('events.edit', $this->portalData(['event' => $event, 'sports' => Sport::orderBy('name')->get(), 'coaches' => Coach::orderBy('name')->get(), 'eventTypes' => self::eventTypes(), 'statuses' => self::statuses()]));
    }

    public function show(Event $event): View
    {
        $this->ensureAdministrator();

        $eventMessages = \App\Models\Message::query()
            ->where('sender_id', auth()->id())
            ->where('event_id', $event->id)
            ->withCount('recipients')
            ->withCount(['recipients as read_recipients_count' => fn ($query) => $query->where('is_read', true)])
            ->latest()
            ->limit(10)
            ->get();

        return view('events.show', $this->portalData([
            'event' => $event->load(['sport', 'coach'])->loadCount('attendanceRecords'),
            'eventMessages' => $eventMessages,
        ]));
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $this->ensureAdministrator();

        $before = $event->only(['title', 'venue', 'starts_at', 'ends_at', 'status', 'sport_id']);
        $previousStatus = $event->status;
        $previousSportId = $event->sport_id;
        $validated = $this->validated($request);
        $notifyChanges = (bool) ($validated['notify_changes'] ?? false);
        unset($validated['send_event_notification'], $validated['notify_changes'], $validated['notify_cancellation']);
        $this->ensureVenueAvailable($validated['venue'], $validated['starts_at'], $validated['ends_at'], $event);
        $event->update($validated);
        $event->refresh();
        $this->announce($event);

        $meaningfulFields = ['title', 'venue', 'starts_at', 'ends_at', 'status'];
        $changed = [];
        foreach ($meaningfulFields as $field) {
            $old = $before[$field] ?? null;
            $new = $event->getAttribute($field);
            $oldValue = $old instanceof \Carbon\CarbonInterface ? $old->format('Y-m-d H:i:s') : (string) $old;
            $newValue = $new instanceof \Carbon\CarbonInterface ? $new->format('Y-m-d H:i:s') : (string) $new;
            if ($oldValue !== $newValue) {
                $changed[] = $field;
            }
        }

        if ($notifyChanges && $changed !== []) {
            $changeLines = [];
            foreach ($changed as $field) {
                $label = match ($field) {
                    'title' => 'Event name',
                    'starts_at', 'ends_at' => 'Date/time',
                    'venue' => 'Venue',
                    'status' => 'Status',
                    default => ucfirst($field),
                };
                $changeLines[$label] = true;
            }

            $subject = $event->status === 'Cancelled' ? 'Event cancelled: '.$event->title : 'Event update: '.$event->title;
            $message = "The following event details have changed: ".implode(', ', array_keys($changeLines)).".\n\n"
                ."Event: {$event->title}\n"
                ."Status: {$event->status}\n"
                .($event->starts_at ? "Date/Time: ".$event->starts_at->format('F j, Y g:i A').($event->ends_at ? ' - '.$event->ends_at->format('g:i A') : '')."\n" : '')
                .($event->venue ? "Venue: {$event->venue}\n" : '');
            if ($event->status === 'Cancelled') {
                $message = "The event \"{$event->title}\" has been cancelled.\n\n".$message;
            }

            try {
                $this->delivery()->send(auth()->user(), array_merge($this->notificationPrefill($event, $subject, trim($message)), [
                    'message_type' => 'event_update',
                    'priority' => $event->status === 'Cancelled' ? 'Important' : 'Normal',
                ]));
                return redirect()->route('events.index')->with('success', 'Event updated and affected athletes were notified.');
            } catch (\Throwable $exception) {
                report($exception);
                return redirect()->route('events.index')->with('success', 'Event updated, but the notification could not be sent. You can send it from the event details page.');
            }
        }

        // Preserve existing automatic in-app notifications for compatibility.
        if ((in_array($previousStatus, ['Scheduled', 'Ongoing'], true) || $event->status === 'Scheduled') && $changed !== []) {
            $sportIds = $previousSportId === null || $event->sport_id === null
                ? null
                : [$previousSportId, $event->sport_id];
            $updateMessage = match ($event->status) {
                'Cancelled' => $event->title.' has been cancelled.',
                'Postponed' => $event->title.' has been postponed.',
                default => $event->title.' has been updated. Check the schedule for details.',
            };

            app(StudentUpdateNotifier::class)->notifyStudents(
                $sportIds,
                'Sports event update',
                $updateMessage,
                route('student.schedule'),
                ['event_id' => $event->id],
            );
        }

        return redirect()->route('events.index')->with('success', 'Event updated successfully.');
    }

    public function cancel(Request $request, Event $event): RedirectResponse
    {
        $this->ensureAdministrator();

        $validated = $request->validate([
            'notify_athletes' => ['nullable', 'boolean'],
        ]);

        if ($event->status === 'Cancelled') {
            return back()->with('success', 'This event is already cancelled.');
        }

        $event->update(['status' => 'Cancelled']);
        $this->announce($event->fresh());

        if (! empty($validated['notify_athletes'])) {
            $message = "The event \"{$event->title}\" has been cancelled.\n\n"
                ."Date/Time: ".($event->starts_at?->format('F j, Y g:i A') ?? 'Not specified')."\n"
                ."Venue: ".($event->venue ?: 'Not specified');
            if ($event->description) {
                $message .= "\n\nOriginal details: ".$event->description;
            }

            try {
                $this->delivery()->send(auth()->user(), array_merge($this->notificationPrefill($event->fresh(), 'Event cancelled: '.$event->title, $message), [
                    'message_type' => 'event_update',
                    'priority' => 'Important',
                ]));
            } catch (\Throwable $exception) {
                report($exception);
                return redirect()->route('events.show', $event)->with('success', 'Event cancelled, but the notification could not be sent. You can send it from the event details page.');
            }
        }

        return redirect()->route('events.show', $event)->with('success', ! empty($validated['notify_athletes'])
            ? 'Event cancelled and affected athletes were notified.'
            : 'Event cancelled. Event history was preserved.');
    }

    public function destroy(Event $event): RedirectResponse
    {
        $this->ensureAdministrator();

        if ($event->status === 'Scheduled' && $event->starts_at?->isFuture()) {
            app(StudentUpdateNotifier::class)->notifyStudents(
                $event->sport_id ? [$event->sport_id] : null,
                'Sports event removed',
                $event->title.' has been removed from the schedule.',
                route('student.schedule'),
                ['event_id' => $event->id],
            );
        }

        $event->delete();

        return redirect()->route('events.index')->with('success', 'Event deleted successfully.');
    }

    private function delivery(): \App\Services\MessageDeliveryService
    {
        return app(\App\Services\MessageDeliveryService::class);
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'sport_id' => ['nullable', 'exists:sports,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'venue' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'event_type' => ['nullable', 'in:'.implode(',', self::eventTypes())],
            'coach_id' => ['nullable', 'exists:coaches,id'],
            'team_name' => ['nullable', 'string', 'max:255'],
            'max_participants' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'in:'.implode(',', self::statuses())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'send_event_notification' => ['nullable', 'boolean'],
            'notify_changes' => ['nullable', 'boolean'],
            'notify_cancellation' => ['nullable', 'boolean'],
            // Optional attendance session created together with the event.
            'attendance' => ['nullable', 'array'],
            'attendance.title' => ['nullable', 'string', 'max:255'],
            'attendance.session_date' => ['nullable', 'date'],
            'attendance.start_time' => ['nullable', 'date_format:H:i'],
            'attendance.end_time' => ['nullable', 'date_format:H:i', 'after_or_equal:attendance.start_time'],
            'attendance.sport_id' => ['nullable', 'exists:sports,id'],
            'attendance.late_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'attendance.venue' => ['nullable', 'string', 'max:255'],
            'attendance.description' => ['nullable', 'string', 'max:2000'],
            'attendance.status' => ['nullable', 'in:Open,Closed,Cancelled'],
        ]);

        $validated['event_type'] = $validated['event_type'] ?? 'Other';

        return $validated;
    }

    private function ensureVenueAvailable(string $venue, string $startsAt, string $endsAt, ?Event $currentEvent = null): void
    {
        $conflict = Event::whereRaw('LOWER(venue) = ?', [strtolower(trim($venue))])
            ->where('starts_at', '<', Carbon::parse($endsAt))
            ->where('ends_at', '>', Carbon::parse($startsAt))
            ->when($currentEvent, fn ($query) => $query->where($query->getModel()->getKeyName(), '!=', $currentEvent->getKey()))
            ->first();

        if ($conflict) {
            request()->validate(['venue' => [function ($attribute, $value, $fail) use ($conflict) {
                $fail("Venue conflict: {$value} is already reserved from {$conflict->starts_at->format('g:i A')} to {$conflict->ends_at->format('g:i A')} on this date.");
            }]]);
        }
    }

    public static function eventTypes(): array
    {
        return ['Training', 'Practice', 'Tournament', 'Competition', 'Tryout', 'Friendly Match', 'District Meet', 'Regional Meet', 'Meeting', 'Other'];
    }

    public static function statuses(): array
    {
        return ['Scheduled', 'Ongoing', 'Completed', 'Cancelled', 'Postponed'];
    }

    private function portalData(array $data = []): array
    {
        return array_merge([
            'title' => 'Event Scheduling',
            'navItems' => PortalNavigation::admin(),
            'active' => 'events',
            'roleLabel' => 'Admin Portal',
            'userName' => auth()->user()->name,
            'userRole' => auth()->user()->role,
        ], $data);
    }

    public function studentShow(Event $event): View
    {
        $student = auth()->user();
        abort_unless($student?->role === 'Student', 403);

        $sportIds = $student->sportIds();
        $visibleBySport = $event->sport_id === null || in_array((int) $event->sport_id, array_map('intval', $sportIds), true);
        $notified = MessageRecipient::query()
            ->where('recipient_id', $student->id)
            ->whereHas('message', fn ($query) => $query->where('status', 'sent')->where('event_id', $event->id))
            ->exists();

        abort_unless($visibleBySport || $notified, 404);

        $event->load(['sport', 'coach']);

        return view('student.page', [
            'page' => 'event',
            'heading' => $event->title,
            'subtitle' => $event->sport?->name ? $event->sport->name.' event details' : 'Sports event details',
            'action' => null,
            'active' => 'schedule',
            'navItems' => PortalNavigation::student(),
            'roleLabel' => 'Athlete/User Portal',
            'userName' => $student->name,
            'userRole' => $student->sport?->name ?? 'Unassigned sport',
            'athlete' => $student,
            'event' => $event,
        ]);
    }

    private function ensureAdministrator(): void
    {
        abort_unless(auth()->user()?->role === 'Administrator', 403);
    }
}