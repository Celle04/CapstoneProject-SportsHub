<?php

use App\Http\Controllers\AchievementController;
use App\Http\Controllers\AdminProfileController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AthleteController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\CertificateRequestController;
use App\Http\Controllers\ChangePasswordController;
use App\Http\Controllers\CoachController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\MedicalController;
use App\Http\Controllers\MedicalIncidentController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicPolicyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SportController;
use App\Models\Achievement;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\Attendance;
use App\Models\AttendanceSession;
use App\Models\CertificateRequest;
use App\Models\Event;
use App\Models\MedicalRecord;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Sport;
use App\Models\User;
use App\Notifications\CertificateRequestNotification;
use App\Services\AttendanceSessionService;
use App\Services\FailedPasswordAttemptService;
use App\Services\MedicalClearanceNotifier;
use App\Support\PortalNavigation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Route::get('/', function () {
    return view('landing', [
        'sports' => Sport::orderBy('name')->get(),
        // The announcements the portal published, newest first.
        'announcements' => Announcement::query()
            ->published()
            ->with(['event.sport'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (Announcement $announcement) => $announcement->event_id ? 'event:'.$announcement->event_id : 'announcement:'.$announcement->id)
            ->take(6)
            ->values(),
    ]);
})->name('landing');

Route::get('/privacy-policy', [PublicPolicyController::class, 'privacyPolicy'])->name('privacy-policy');
Route::get('/terms-and-conditions', [PublicPolicyController::class, 'termsAndConditions'])->name('terms-and-conditions');

Route::get('/login', function () {
    return view('auth.login');
})->name('login');

Route::post('/login', function () {
    $credentials = request()->validate([
        'username' => ['required', 'string'],
        'password' => ['required', 'string'],
        'role' => ['required', 'in:Administrator,Student'],
    ]);

    $loginIdentifier = trim($credentials['username']);

    $user = User::where('role', $credentials['role'])
        ->where(function ($query) use ($loginIdentifier) {
            $query->where('email', $loginIdentifier)
                ->orWhere('username', $loginIdentifier);
        })
        ->first();

    if ($user && Hash::check($credentials['password'], $user->password)) {
        auth()->login($user, request()->boolean('remember'));
        request()->session()->regenerate();

        if ($credentials['role'] === 'Student' && $user->needsPasswordChange()) {
            return redirect()->route('student.change-password');
        }

        return redirect()->intended($credentials['role'] === 'Student' ? route('student.dashboard') : route('dashboard'));
    }

    /*
     * A wrong password is warned about by email as well as on the screen: the
     * holder of the account and the sports coordinators are both notified.
     * The service throttles the warnings, so repeated attempts cannot be
     * used to flood a mailbox. The screen still shows the same message for
     * every failed attempt, so the form cannot be used to find out which
     * accounts exist.
     */
    app(FailedPasswordAttemptService::class)->reportLoginFailure(
        $user,
        $loginIdentifier,
        $credentials['role'],
        request(),
    );

    return redirect()->route('login')->withErrors(['username' => 'Invalid email or password.'])->withInput(request()->only('username', 'role'));
})->middleware('throttle:login')->name('login.submit');

// Password reset runs entirely through Laravel's password broker: the broker
// creates and hashes the token, delivers the branded notification, enforces
// the 60 minute expiry and the per-user resend throttle, and invalidates the
// token once it has been used. The throttles below add per-IP rate limiting.
Route::get('/forgot-password', [PasswordResetController::class, 'showLinkRequestForm'])->name('password.request');

Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
    ->middleware('throttle:5,1')
    ->name('password.email');

Route::get('/reset-password', [PasswordResetController::class, 'showResetForm'])->name('password.reset');

Route::post('/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:5,1')
    ->name('password.update');

/*
 * Password confirmation for high-impact administrator actions. Laravel's
 * `password.confirm` middleware redirects here when the administrator has
 * not confirmed their password recently.
 */
Route::get('/confirm-password', [\App\Http\Controllers\Auth\ConfirmPasswordController::class, 'show'])
    ->middleware('auth')
    ->name('password.confirm');

Route::post('/confirm-password', [\App\Http\Controllers\Auth\ConfirmPasswordController::class, 'store'])
    ->middleware('auth')
    ->name('password.confirm.store');

Route::post('/logout', function () {
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

Route::get('/apply', function () {
    return view('application.create', ['sports' => Sport::orderBy('name')->get()]);
})->name('application.create');

Route::get('/application/status', [ApplicationController::class, 'showStatusForm'])
    ->middleware('throttle:30,1')
    ->name('application.status');
Route::post('/application/status', [ApplicationController::class, 'checkStatus'])
    ->middleware('throttle:10,1')
    ->name('application.status.check');

/*
 * Public applications carry three file uploads each, so the submission is
 * rate limited per network source. A genuine applicant submits once (twice if
 * they fix a mistake), and a shared school or home network can still put a
 * handful of students through in the same window.
 */
Route::post('/apply', [ApplicationController::class, 'store'])
    ->middleware('throttle:5,10')
    ->name('application.store');

/*
 * PortalNavigation is the single source of truth for both portals, so the
 * grouped sidebar never drifts between modules. The group key is presentation
 * only: every route name below is unchanged.
 */
$studentNav = PortalNavigation::student();

$calendarData = function (?string $month = null): array {
    $monthValue = $month ?: request('month', now()->format('Y-m'));
    $calendarMonth = Carbon::createFromFormat('Y-m', $monthValue)->startOfMonth();
    $monthStart = $calendarMonth->copy()->startOfMonth();
    $monthEnd = $calendarMonth->copy()->endOfMonth();

    $studentSportIds = auth()->user()?->role === 'Student' ? auth()->user()->sportIds() : null;

    $eventsQuery = Event::with(['sport', 'coach'])
        ->when($studentSportIds !== null, fn ($query) => $query->where('status', 'Scheduled'))
        ->when(request('sport_id'), fn ($query, $sportId) => $query->where('sport_id', $sportId))
        ->when(request('venue'), fn ($query, $venue) => $query->where('venue', $venue))
        ->when(request('event_type'), fn ($query, $eventType) => $query->where('event_type', $eventType))
        ->when(request('status'), fn ($query, $status) => $query->where('status', $status))
        ->where(function ($query) use ($monthStart, $monthEnd) {
            $query->whereBetween('starts_at', [$monthStart, $monthEnd])
                ->orWhereBetween('ends_at', [$monthStart, $monthEnd]);
        })
        ->when($studentSportIds !== null, function ($query) use ($studentSportIds) {
            $query->where(function ($sportQuery) use ($studentSportIds) {
                $sportQuery->whereNull('sport_id');
                if ($studentSportIds !== []) {
                    $sportQuery->orWhereIn('sport_id', $studentSportIds);
                }
            });
        });

    $events = $eventsQuery->orderBy('starts_at')->get();

    // Build the six week grid first so events can be clamped to it.
    $days = [];
    $dayCursor = $calendarMonth->copy()->startOfWeek(Carbon::SUNDAY);
    $lastDay = $calendarMonth->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);
    while ($dayCursor->lte($lastDay)) {
        $days[] = $dayCursor->copy();
        $dayCursor->addDay();
    }

    $gridStart = $days[0]->copy()->startOfDay();
    $gridEnd = $days[count($days) - 1]->copy()->endOfDay();

    // Place an event on every day it covers, not only on its start date, so a
    // tournament that runs from the 30th into the 2nd is visible on all of its
    // days. The span is clamped to the visible grid so an event with a distant
    // end date cannot expand into thousands of entries.
    $placements = collect();
    foreach ($events as $event) {
        $spanStart = $event->starts_at->copy()->startOfDay()->max($gridStart);
        $spanEnd = ($event->ends_at ?? $event->starts_at)->copy()->endOfDay()->min($gridEnd);

        for ($day = $spanStart->copy(); $day->lte($spanEnd); $day->addDay()) {
            $placements->push(['day' => $day->toDateString(), 'event' => $event]);
        }
    }

    $calendarEvents = $placements
        ->sortBy(fn (array $placement) => $placement['event']->starts_at->timestamp)
        ->groupBy('day')
        ->map(fn ($dayPlacements) => $dayPlacements->pluck('event')->values());

    $studentEvents = Event::query()
        ->when($studentSportIds !== null, function ($query) use ($studentSportIds) {
            $query->where('status', 'Scheduled')->where(function ($sportQuery) use ($studentSportIds) {
                $sportQuery->whereNull('sport_id');
                if ($studentSportIds !== []) {
                    $sportQuery->orWhereIn('sport_id', $studentSportIds);
                }
            });
        });

    return [
        'calendarMonth' => $calendarMonth,
        'calendarDays' => $days,
        'calendarEvents' => $calendarEvents,
        'summary' => [
            'month' => $events->count(),
            'week' => (clone $studentEvents)->whereBetween('starts_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'today' => (clone $studentEvents)->where(fn ($query) => $query->whereDate('starts_at', today())->orWhereDate('ends_at', today()))->count(),
        ],
        'sports' => Sport::orderBy('name')->get(),
        'venues' => Event::query()->whereNotNull('venue')->distinct()->orderBy('venue')->pluck('venue'),
        'eventTypes' => EventController::eventTypes(),
        'statuses' => EventController::statuses(),
        'upcomingEvents' => Event::with('sport')->where('status', 'Scheduled')->where('starts_at', '>=', now())->when($studentSportIds !== null, function ($query) use ($studentSportIds) {
            $query->where(function ($sportQuery) use ($studentSportIds) {
                $sportQuery->whereNull('sport_id');
                if ($studentSportIds !== []) {
                    $sportQuery->orWhereIn('sport_id', $studentSportIds);
                }
            });
        })->orderBy('starts_at')->limit(5)->get(),
    ];
};

$studentPage = function (string $page, array $data = []) use ($studentNav) {
    abort_unless(auth()->user()?->role === 'Student', 403);
    $athlete = auth()->user();
    $studentApplication = $athlete->applications()->with('sportCategory')->latest()->first();
    $studentSportIds = $athlete->sportIds();
    $upcomingStudentEventsQuery = Event::with(['sport', 'coach'])
        ->where('status', 'Scheduled')
        ->where('starts_at', '>=', now())
        ->where(function ($query) use ($studentSportIds) {
            $query->whereNull('sport_id');
            if ($studentSportIds !== []) {
                $query->orWhereIn('sport_id', $studentSportIds);
            }
        })
        ->orderBy('starts_at');
    $upcomingStudentEvents = (clone $upcomingStudentEventsQuery)->limit(5)->get();
    $dashboardData = [];

    if ($page === 'dashboard') {
        app(MedicalClearanceNotifier::class)->notifyFor($athlete);
        $attendanceService = app(AttendanceSessionService::class);
        $attendanceCounts = $athlete->attendanceRecords()
            ->counted()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $attendanceTotal = (int) $attendanceCounts->sum();
        $eligibleSessions = $athlete->sport_id
            ? AttendanceSession::query()
                ->with(['event.sport', 'event.coach', 'sport', 'attendanceRecords' => fn ($query) => $query->where('user_id', $athlete->id)])
                ->visibleToAthlete($athlete)
                ->whereIn('status', ['Open', 'Closed'])
                ->whereDate('session_date', '>=', today()->toDateString())
                ->orderBy('session_date')
                ->orderBy('start_time')
                ->get()
            : collect();
        $eligibleOpenSessions = $eligibleSessions->filter(fn ($session) => $session->isOpen() && $session->session_date?->isToday())->values();
        $nextEvent = (clone $upcomingStudentEventsQuery)->first();
        $nextTraining = $athlete->sport_id
            ? $eligibleSessions->first(fn ($session) => ! $session->session_date?->isToday() || ! $session->start_time || Carbon::parse($session->session_date->toDateString().' '.$session->start_time)->gte(now()))
            : null;
        $activityOptions = collect();

        $dashboardAchievements = $athlete->achievements()
            ->with('sport')
            ->orderByDesc('date_achieved')
            ->orderByDesc('id')
            ->get();

        if ($nextEvent) {
            $activityOptions->push([
                'title' => $nextEvent->title,
                'sport' => $nextEvent->sport?->name ?? 'All sports',
                'startsAt' => $nextEvent->starts_at,
                'endsAt' => $nextEvent->ends_at,
                'venue' => $nextEvent->venue,
                'coach' => $nextEvent->coach?->name,
            ]);
        }

        if ($nextTraining) {
            $activityOptions->push([
                'title' => $nextTraining->title,
                'sport' => $nextTraining->sport?->name ?? 'Unassigned sport',
                'startsAt' => Carbon::parse($nextTraining->session_date->toDateString().' '.($nextTraining->start_time ?: '00:00')),
                'endsAt' => $nextTraining->end_time ? Carbon::parse($nextTraining->session_date->toDateString().' '.$nextTraining->end_time) : null,
                'venue' => $nextTraining->venue,
                'coach' => null,
            ]);
        }

        $dashboardData = [
            'dashboardUpcomingCount' => (clone $upcomingStudentEventsQuery)->count(),
            'dashboardAttendanceCounts' => [
                'present' => (int) $attendanceCounts->get('Present', 0),
                'late' => (int) $attendanceCounts->get('Late', 0),
                'absent' => (int) $attendanceCounts->get('Absent', 0),
                'excused' => (int) $attendanceCounts->get('Excused', 0),
                'percentage' => $attendanceService->rateFor($athlete),
            ],
            'dashboardOpenSessions' => $eligibleOpenSessions,
            'dashboardUpcomingAttendance' => $eligibleSessions->filter(fn ($session) => ! $session->session_date?->isToday())->values(),
            'dashboardNextActivity' => $activityOptions->sortBy('startsAt')->first(),
            'dashboardAchievementCount' => $dashboardAchievements->count(),
            'dashboardMedalCount' => $dashboardAchievements->filter(fn ($achievement) => $achievement->isMedal())->count(),
            'dashboardRecentAchievements' => $dashboardAchievements->take(3),
            'dashboardUpcomingMedicalReminders' => MessageRecipient::query()
                ->where('recipient_id', $athlete->id)
                ->whereHas('message', fn ($query) => $query
                    ->where('status', 'sent')
                    ->where('category', \App\Models\Message::CATEGORY_MEDICAL_FOLLOW_UP)
                    ->whereHas('followUp', fn ($followUp) => $followUp->whereDate('scheduled_date', '>=', today())))
                ->with('message.sender', 'message.followUp')
                ->get()
                ->sortBy(fn ($delivery) => $delivery->message?->followUp?->scheduled_date?->format('Y-m-d').' '.($delivery->message?->followUp?->scheduled_time ?? '00:00'))
                ->take(5)
                ->values(),
        ];
    }
    $content = [
        'dashboard' => ['Dashboard', "Here's what's happening with your sports activities", null],
        'sports' => ['Sports Programs', 'View sports programs managed by SNNHS', null],
        'calendar' => ['Calendar', 'View your sports activities and important dates', null],
        'schedule' => ['My Schedule', 'Your upcoming training sessions and events', null],
        'attendance' => ['Attendance Record', 'Track your attendance for training sessions and events', null],
        'achievements' => ['My Achievements', 'Your medals, awards and accomplishments', null],
        'application' => ['My Application', 'View your sports application status and feedback', null],
        'coach' => ['Coach Information', 'Learn more about your coach', null],
        'profile' => ['My Profile', 'Manage your personal information', ['label' => 'Edit Profile', 'url' => '#']],
    ][$page];

    return view('student.page', array_merge(['page' => $page, 'heading' => $content[0], 'subtitle' => $content[1], 'action' => $content[2], 'active' => $page, 'navItems' => $studentNav,          'studentApplication' => $studentApplication, 'upcomingStudentEvents' => $upcomingStudentEvents, 'athlete' => $athlete, 'roleLabel' => 'Athlete/User Portal', 'userName' => $athlete->name, 'userRole' => $athlete->sport?->name ?? 'Unassigned sport'], $dashboardData, $data));
};

/**
 * Data every student achievements page renders with.
 *
 * The records are resolved from the authenticated account only. No achievement
 * id supplied by the browser is ever used to pick the set, so an athlete can
 * never widen the result to somebody else's rows.
 */
$studentAchievementData = function (?int $selectedId = null) use ($studentPage) {
    $athlete = auth()->user();
    abort_unless($athlete?->role === 'Student', 403);

    $achievements = $athlete->achievements()
        ->with(['sport', 'event'])
        ->orderByDesc('date_achieved')
        ->orderByDesc('id')
        ->get();

    $selectedAchievement = null;

    if ($selectedId !== null) {
        // Scoped lookup: the URL id is matched against this athlete's own rows,
        // so another athlete's achievement resolves to a 404 instead of a page.
        $selectedAchievement = Achievement::query()
            ->forAthlete($athlete)
            ->with(['sport', 'event'])
            ->findOrFail($selectedId);
    }

    // Most relevant certificate request per achievement (prefer issued/released,
    // then open requests, then latest), so the UI can hide the request button
    // once the student already has an issued certificate.
    $certificateRequests = CertificateRequest::query()
        ->forStudent($athlete)
        ->whereIn('achievement_id', $achievements->pluck('id'))
        ->orderByRaw("CASE WHEN status = 'Issued' THEN 0 WHEN status IN ('Approved','Pending') THEN 1 WHEN status = 'Rejected' THEN 2 ELSE 3 END")
        ->orderByDesc('handled_at')
        ->orderByDesc('id')
        ->get()
        ->groupBy('achievement_id')
        ->map(fn ($requests) => $requests->first());

    return $studentPage('achievements', [
        'heading' => $selectedAchievement?->title ?? 'My Achievements',
        'subtitle' => $selectedAchievement
            ? $selectedAchievement->sportLabel().' · '.$selectedAchievement->dateAchievedLabel()
            : 'Your medals, awards and accomplishments',
        'achievements' => $achievements,
        'achievementSummary' => [
            'total' => $achievements->count(),
            'medals' => $achievements->filter(fn ($achievement) => $achievement->isMedal())->count(),
            'titles' => $achievements->whereIn('achievement_type', ['Champion', 'Runner-up'])->count(),
            'sports' => $achievements->pluck('sport_id')->filter()->unique()->count(),
        ],
        'achievementTimeline' => $achievements->groupBy(fn ($achievement) => $achievement->year()),
        'certificateRequests' => $certificateRequests,
        'selectedAchievement' => $selectedAchievement,
        'achievementViewMode' => $selectedAchievement ? 'show' : 'index',
    ]);
};

Route::middleware(['force.password.change'])->group(function () use ($studentPage, $studentNav, $calendarData, $studentAchievementData) {
    Route::get('/student/messages', [MessageController::class, 'inbox'])->middleware('auth')->name('student.messages');
    Route::get('/student/messages/{message}', [MessageController::class, 'studentShow'])->middleware('auth')->name('student.messages.show');
    Route::post('/student/messages/{message}/acknowledge', [MessageController::class, 'acknowledge'])->middleware('auth')->name('student.messages.acknowledge');
    Route::post('/student/messages/{message}/reply', [MessageController::class, 'reply'])->middleware('auth')->name('student.messages.reply');

    /*
     * Acknowledging and replying are form actions and only accept a POST.
     * A pasted or bookmarked address lands on the message itself instead of
     * an error page.
     */
    Route::get('/student/messages/{message}/acknowledge', fn (Message $message) => redirect()->route('student.messages.show', $message))->middleware('auth')->name('student.messages.acknowledge.form');
    Route::get('/student/messages/{message}/reply', fn (Message $message) => redirect()->route('student.messages.show', $message))->middleware('auth')->name('student.messages.reply.form');

    /*
     * The medical follow-ups live in the unified module now. The old links
     * keep working and send the athlete to the same place.
     */
    Route::get('/student/medical-follow-ups', fn () => redirect()->route('student.messages', ['tab' => 'medical']))->middleware('auth')->name('student.medical-follow-ups');
    Route::get('/student/medical-follow-ups/{conversation}', fn () => redirect()->route('student.messages', ['tab' => 'medical']))->middleware('auth')->name('student.medical-follow-ups.show');
    Route::post('/student/medical-follow-ups/{conversation}/reply', fn () => redirect()->route('student.messages', ['tab' => 'medical']))->middleware('auth')->name('student.medical-follow-ups.reply');
    Route::get('/student/medical-follow-ups/{conversation}/attachments/{message}/view', fn () => redirect()->route('student.messages', ['tab' => 'medical']))->middleware('auth')->name('student.medical-follow-ups.attachments.view');
    Route::get('/student/medical-follow-ups/{conversation}/attachments/{message}', fn () => redirect()->route('student.messages', ['tab' => 'medical']))->middleware('auth')->name('student.medical-follow-ups.attachments.download');
    Route::get('/student/events/{event}', [EventController::class, 'studentShow'])->middleware('auth')->name('student.events.show');
    Route::get('/student/achievements', fn () => $studentAchievementData())->middleware('auth')->name('student.achievements');
Route::get('/student/achievements/{achievement}', fn (int $achievement) => $studentAchievementData($achievement))->middleware('auth')->name('student.achievements.show');
Route::get('/student/achievements/{achievement}/certificate', function (int $achievement) {
    $student = auth()->user();
    $record = Achievement::query()->forAthlete($student)->with('sport')->findOrFail($achievement);

    /*
     * The certificate is only available once an administrator generated it
     * and marked it ready for release, and only for the athlete who owns
     * the achievement.
     */
    $certificateRequest = CertificateRequest::query()
        ->forStudent($student)
        ->where('achievement_id', $record->id)
        ->where('status', CertificateRequest::STATUS_ISSUED)
        ->latest('handled_at')
        ->first();

    /*
     * A certificate is available once an administrator generated it and marked
     * it ready for release. Records that were issued before the workflow
     * existed keep their own file, so nothing that was already handed out
     * disappears.
     */
    $file = $certificateRequest?->isGenerated()
        ? $certificateRequest->certificate_path
        : ($record->hasCertificate() && ! $record->certificateRequests()->whereNotNull('certificate_path')->exists()
            ? $record->certificate_path
            : null);

    abort_unless($file, 404, 'Your certificate is not available yet.');

    return Storage::disk('private')->download(
        $file,
        $record->certificateFileName(),
    );
})->middleware('auth')->name('student.achievements.certificate');

Route::post('/student/achievements/{achievement}/certificate-request', function (int $achievement) {
    $student = auth()->user();
    abort_unless($student?->role === 'Student', 403);

    // The lookup goes through the forAthlete scope, so the student can only
    // ever request a certificate for one of their own achievements.
    $record = Achievement::query()->forAthlete($student)->findOrFail($achievement);

    if ($record->hasCertificate()) {
        return back()->withErrors(['certificate' => 'This achievement already has a certificate attached.']);
    }

    $openRequestExists = CertificateRequest::query()
        ->forStudent($student)
        ->where('achievement_id', $record->id)
        ->whereIn('status', [CertificateRequest::STATUS_PENDING, CertificateRequest::STATUS_APPROVED])
        ->exists();

    if ($openRequestExists) {
        return back()->withErrors(['certificate' => 'You already have a pending certificate request for this achievement.']);
    }

    $request = CertificateRequest::create([
        'achievement_id' => $record->id,
        'student_id' => $student->id,
        'status' => CertificateRequest::STATUS_PENDING,
    ]);

    foreach (User::where('role', 'Administrator')->get() as $admin) {
        $admin->notify(new CertificateRequestNotification(
            'New Certificate Request',
            $student->name.' requested a certificate for "'.$record->title.'".',
            route('admin.certificate-requests'),
            ['dedupe_key' => 'certificate-request|submitted|'.$request->id],
        ));
    }

    return back()->with('success', 'Your certificate request has been submitted for approval.');
})->middleware('auth')->name('student.achievements.certificate-request');

Route::get('/student', fn () => redirect()->route('student.dashboard'));
Route::get('/student/dashboard', fn () => $studentPage('dashboard'))->middleware('auth')->name('student.dashboard');
Route::get('/student/sports', function () use ($studentPage) {
    $sports = Sport::where('status', 'Active')->with('coaches')->orderBy('name')->get();

    $sports->each(function (Sport $sport): void {
        $forSportApplications = static fn ($query) => $query->where(static fn ($sportApplications) => $sportApplications
            ->where('sport_id', $sport->id)
            ->orWhere(static fn ($legacyApplications) => $legacyApplications
                ->whereNull('sport_id')
                ->where('sport', $sport->name)));

        $sport->members_count = User::query()
            ->where('role', 'Student')
            ->where('status', 'Active')
            ->where('sport_id', $sport->id)
            ->whereDoesntHave('applications', static fn ($query) => $forSportApplications($query)->where('status', '!=', 'Approved'))
            ->count();
    });

    return $studentPage('sports', ['studentSports' => $sports]);
})->middleware('auth')->name('student.sports');
Route::get('/student/sports/{sport}/members', [SportController::class, 'studentMembers'])->middleware('auth')->name('student.sports.members');
Route::get('/student/sports/{sport}/members/{athlete}', [SportController::class, 'studentMemberProfile'])->middleware('auth')->name('student.sports.member-profile');
Route::get('/student/calendar', fn () => $studentPage('calendar', $calendarData(request('month'))))->middleware('auth')->name('student.calendar');
Route::get('/student/schedule', fn () => $studentPage('schedule', [
    'events' => Event::where('status', 'Scheduled')
        ->where('starts_at', '>=', now())
        ->where(function ($query) {
            $sportIds = auth()->user()->sportIds();
            $query->whereNull('sport_id');
            if ($sportIds !== []) {
                $query->orWhereIn('sport_id', $sportIds);
            }
        })
        ->orderBy('starts_at')
        ->get(),
]))->middleware('auth')->name('student.schedule');
Route::get('/student/attendance', function () use ($studentPage) {
    $athlete = auth()->user();
    abort_unless($athlete?->role === 'Student', 403);

    $attendanceService = app(AttendanceSessionService::class);

    $sessions = $athlete->sport_id
        ? AttendanceSession::query()
            ->with(['sport', 'event.sport', 'attendanceRecords' => fn ($query) => $query->where('user_id', $athlete->id)])
            ->visibleToAthlete($athlete)
            ->whereIn('status', ['Open', 'Closed'])
            ->whereDate('session_date', '>=', today()->toDateString())
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->get()
        : collect();

    $records = Attendance::query()
        ->with(['session.sport', 'session.event.sport', 'event.sport'])
        ->where('user_id', $athlete->id)
        ->orderByDesc('attended_on')
        ->orderByDesc('id')
        ->get();

    $counted = $records->filter(fn ($record) => in_array($record->status, config('attendance.counted_statuses'), true)
        && (! $record->session || $record->session->status !== 'Cancelled'));

    $presentCount = $counted->whereIn('status', ['Present', 'Late'])->count();

    return $studentPage('attendance', [
        'attendanceRecords' => $records,
        'openAttendanceSessions' => $sessions->filter(fn ($session) => $session->isOpen() && $session->session_date?->isToday()),
        'upcomingAttendanceSessions' => $sessions->filter(fn ($session) => ! $session->session_date?->isToday()),
        'attendanceStats' => [
            'total' => $counted->count(),
            'present' => $records->where('status', 'Present')->count(),
            'late' => $records->where('status', 'Late')->count(),
            'absent' => $records->where('status', 'Absent')->count(),
            'excused' => $records->where('status', 'Excused')->count(),
            'pending' => $records->where('status', 'Pending')->count(),
            'rate' => $attendanceService->rateFor($athlete),
        ],
    ]);
})->middleware('auth')->name('student.attendance');
Route::post('/student/attendance/sessions/{session}/check-in', function (AttendanceSession $session) {
    $student = auth()->user();

    $result = app(AttendanceSessionService::class)->checkIn($session, $student);

    if (! $result['checkedIn']) {
        return back()->withErrors(['attendance' => 'You have already checked in for this session.']);
    }

    app(AttendanceSessionService::class)->notifyAdministrators(
        $student->name.' checked in for '.$result['label'].'.',
        route('admin.attendance', ['session_id' => $session->id])
    );

    return back()->with('success', 'You are checked in for '.$result['label'].' at '.($result['time'] ?? now())->format('g:i A').'.');
})->middleware('auth')->name('student.attendance.check-in');
Route::get('/student/application', fn () => $studentPage('application'))->middleware('auth')->name('student.application');
Route::get('/student/coach', fn () => $studentPage('coach', ['coachSports' => auth()->user()->sportsWithCoaches()]))->middleware('auth')->name('student.coach');
Route::get('/student/profile', fn () => $studentPage('profile', ['athlete' => auth()->user()]))->middleware('auth')->name('student.profile');
Route::patch('/student/profile', [ProfileController::class, 'update'])->middleware('auth')->name('student.profile.update');
Route::patch('/student/profile/password', [ProfileController::class, 'updatePassword'])->middleware('auth')->name('student.profile.password');
Route::get('/student/change-password', [ChangePasswordController::class, 'show'])->middleware('auth')->name('student.change-password');
Route::post('/student/change-password', [ChangePasswordController::class, 'update'])->middleware('auth')->name('student.change-password.update');
});

Route::post('/notifications/{notification}/read', function (Request $request, string $notification) {
    abort_unless(auth()->check(), 403);
    $record = auth()->user()->notifications()->whereKey($notification)->firstOrFail();
    $record->markAsRead();
    if ($messageId = data_get($record->data, 'message_id')) {
        MessageRecipient::markReadFor((int) $messageId, (int) auth()->id());
    }

    if ($request->wantsJson()) {
        return response()->json([
            'ok' => true,
            'unread' => auth()->user()->unreadNotifications()->count(),
        ]);
    }

    return back();
})->middleware('auth')->name('notifications.read');

/**
 * The live state of the notification bell.
 *
 * The portal asks for it now and then, so a new notification shows up in the
 * bell without the page having to be reloaded. The panel is rendered by the
 * same partial the page uses, so both look the same.
 *
 * This route is declared before the one that opens a single notification, so
 * "feed" is never read as a notification id.
 */
Route::get('/notifications/feed', function () {
    abort_unless(auth()->check(), 403);

    $user = auth()->user();
    $unread = $user->unreadNotifications()->count();
    $recent = $user->notifications()->latest()->limit(10)->get();

    return response()->json([
        'unread' => $unread,
        'badge' => $unread > 99 ? '99+' : (string) $unread,
        'latest_id' => $recent->first()?->id,
        'panel' => view('partials.notifications.menu', [
            'unreadNotificationCount' => $unread,
            'recentNotifications' => $recent,
        ])->render(),
    ]);
})->middleware('auth')->name('notifications.feed');

/**
 * Open a notification: marks it read and forwards the user to whatever the
 * notification points at. Scoping the lookup to the signed in user's own
 * notifications means one athlete can never open or clear another's.
 */
Route::get('/notifications/{notification}', function (string $notification) {
    abort_unless(auth()->check(), 403);
    $record = auth()->user()->notifications()->whereKey($notification)->firstOrFail();
    $target = (string) (data_get($record->data, 'url') ?: '');
    $record->markAsRead();
    if ($messageId = data_get($record->data, 'message_id')) {
        MessageRecipient::markReadFor((int) $messageId, (int) auth()->id());
    }

    /*
     * A notification only ever points at an in-app page. Anything that is not
     * a path on this site (for example an external host slipped into the
     * notification payload) is dropped, so this route can never be used as an
     * open redirect for phishing.
     */
    if (! Str::startsWith($target, url('/')) && ! Str::startsWith($target, '/')) {
        $target = route('notifications.index');
    }

    return redirect()->to($target);
})->middleware('auth')->name('notifications.open');

Route::post('/notifications/read-all', function (Request $request) {
    abort_unless(auth()->check(), 403);
    auth()->user()->unreadNotifications->each(function ($notification): void {
        $notification->markAsRead();
        if ($messageId = data_get($notification->data, 'message_id')) {
            MessageRecipient::markReadFor((int) $messageId, (int) auth()->id());
        }
    });

    if ($request->wantsJson()) {
        return response()->json([
            'ok' => true,
            'unread' => auth()->user()->unreadNotifications()->count(),
        ]);
    }

    return back();
})->middleware('auth')->name('notifications.read-all');


$adminNav = PortalNavigation::admin();

Route::get('/notifications', function () use ($studentNav, $adminNav) {
    $user = auth()->user();
    $isStudent = $user->role === 'Student';

    return view('notifications.index', [
        'notifications' => $user->notifications()->latest()->simplePaginate(20),
        'title' => 'Notifications',
        'navItems' => PortalNavigation::for($user->role),
        'active' => 'notifications',
        'roleLabel' => $isStudent ? 'Athlete/User Portal' : 'Admin Portal',
        'userName' => $user->name,
        'userRole' => $user->sport?->name ?? $user->role,
        'homeUrl' => $isStudent ? route('student.dashboard') : route('dashboard'),
    ]);
})->middleware('auth')->name('notifications.index');

$adminPage = function (string $page, array $data = []) use ($adminNav) {
    abort_unless(auth()->user()?->role === 'Administrator', 403);
    $content = [
        'dashboard' => ['Admin Dashboard', 'Overview of SNNHS SportsHub', null],
        'calendar' => ['Calendar', 'View scheduled sports activities and events', null],
        'applications' => ['Applications Management', 'Review and process athlete applications', null],
        'sports' => ['Sports Management', 'Manage sports programs and categories', 'Add New Sport'],
        'athletes' => ['Athlete Management', 'Manage registered athlete accounts', null],
        'medical' => ['Medical Records', 'Manage athlete health information and clearances', 'Add Medical Record'],
        'coaches' => ['Coach Management', 'Manage coach records and their sport assignments', 'Add New Coach'],
        'events' => ['Event Scheduling', 'Manage training sessions and competitions', 'Create Event'],
        'attendance' => ['Attendance Management', 'Track and record athlete attendance', null],
        'reports' => ['Reports', 'Generate and download reports', null],
        'profile' => ['My Profile', 'Manage your personal administrator account', null],
    ][$page];

    return view('admin.page', array_merge(['page' => $page, 'heading' => $content[0], 'subtitle' => $content[1], 'action' => $content[2], 'active' => $page, 'navItems' => $adminNav, 'roleLabel' => 'Admin Portal', 'userName' => auth()->user()->name, 'userRole' => 'Administrator'], $data));
};

Route::middleware(['auth', 'administrator'])->group(function () use ($adminPage, $calendarData) {
Route::get('/admin/profile', fn () => $adminPage('profile', ['admin' => auth()->user()]))->name('admin.profile');
Route::patch('/admin/profile', [AdminProfileController::class, 'update'])->name('admin.profile.update');
Route::patch('/admin/profile/password', [AdminProfileController::class, 'updatePassword'])->name('admin.profile.password');
Route::get('/dashboard', function () use ($adminPage) {
    $today = today();

    /*
     * Every figure on the command centre is resolved once here and then reused
     * by the metric cards and the panels, so a single aggregate query backs
     * each number instead of the same count being re-run per card.
     */
    $athleteCount = User::where('role', 'Student')->count();
    $activeAthleteCount = User::where('role', 'Student')->where('status', 'Active')->count();
    $sportCount = Sport::count();
    $activeSportCount = Sport::where('status', 'Active')->count();
    $upcomingEventCount = Event::where('status', 'Scheduled')->where('starts_at', '>=', now())->count();
    $todaySessionCount = AttendanceSession::whereDate('session_date', $today)->count();
    $openSessionCount = AttendanceSession::whereDate('session_date', $today)->where('status', 'Open')->count();
    $pendingApplicationCount = Application::where('status', 'Pending')->count();
    $recordedAchievementCount = Achievement::count();
    $medalCounts = Achievement::query()
        ->whereIn('achievement_type', Achievement::MEDAL_TYPES)
        ->selectRaw('achievement_type, COUNT(*) as total')
        ->groupBy('achievement_type')
        ->pluck('total', 'achievement_type');
    $medalCount = (int) $medalCounts->sum();

    $attendanceToday = Attendance::whereDate('attended_on', $today)
        ->whereNotNull('attendance_session_id')
        ->whereIn('status', config('attendance.counted_statuses'))
        ->selectRaw('status, COUNT(*) as total')
        ->groupBy('status')
        ->pluck('total', 'status');
    $countedToday = (int) $attendanceToday->sum();
    $presentTodayCount = (int) $attendanceToday->get('Present', 0) + (int) $attendanceToday->get('Late', 0);
    $attendanceRate = $countedToday > 0
        ? round($presentTodayCount / $countedToday * 100, 1)
        : 0;
    $pendingCheckInCount = Attendance::whereDate('attended_on', $today)
        ->where('status', 'Pending')
        ->whereNotNull('attendance_session_id')
        ->count();

    $medicalLatestRecords = MedicalRecord::query()->active()
        ->get(['id', 'athlete_id', 'medical_status', 'expiration_date', 'next_checkup_date', 'examination_date'])
        ->groupBy('athlete_id')
        ->map(fn ($records) => $records->sortByDesc(fn (MedicalRecord $record) => [
            $record->examination_date?->format('Y-m-d') ?? '',
            $record->id,
        ])->first())
        ->filter();
    $medicalStatusCounts = collect(MedicalRecord::STATUSES)
        ->mapWithKeys(fn (string $status) => [$status => $medicalLatestRecords->filter(fn (MedicalRecord $record) => $record->effectiveStatus() === $status)->count()]);
    $clearedAthletes = (int) ($medicalStatusCounts->get('Cleared') ?? 0);

    $athletesMissingMedical = User::where('role', 'Student')
        ->whereDoesntHave('medicalRecords', fn ($query) => $query->whereNull('archived_at'))
        ->count();
    $checkupsDue = MedicalRecord::query()->active()
        ->whereRaw('COALESCE(next_checkup_date, expiration_date) between ? and ?', [$today->toDateString(), $today->copy()->addDays(30)->toDateString()])
        ->count();
    $pendingCertificateRequests = CertificateRequest::where('status', CertificateRequest::STATUS_PENDING)->count();

    $achievementsBySport = Achievement::query()
        ->select('sport_id')
        ->selectRaw('COUNT(*) as total')
        ->whereNotNull('sport_id')
        ->with('sport:id,name')
        ->groupBy('sport_id')
        ->orderByDesc('total')
        ->get();
    $recentAchievements = Achievement::with(['athlete:id,name,student_id', 'sport:id,name', 'event:id,title'])
        ->orderByDesc('date_achieved')
        ->orderByDesc('id')
        ->limit(6)
        ->get();

    /*
     * The metric cards. Every value comes from the counts resolved above, so
     * the command centre never shows a figure the database cannot back.
     */
    $metrics = [
        [
            'value' => $athleteCount,
            'label' => 'Total Athletes',
            'hint' => 'Active Athletes: '.$activeAthleteCount,
            'action' => 'View athletes',
            'route' => 'athletes.index',
            'icon' => 'users',
            'tone' => 'primary',
        ],
        [
            'value' => $sportCount,
            'label' => 'Total Sports',
            'hint' => $activeSportCount.' active program'.($activeSportCount === 1 ? '' : 's'),
            'action' => 'View programs',
            'route' => 'sports.index',
            'icon' => 'trophy',
            'tone' => 'accent',
        ],
        [
            'value' => $upcomingEventCount,
            'label' => 'Upcoming Events',
            'hint' => 'Scheduled from today onward',
            'action' => 'View events',
            'route' => 'events.index',
            'icon' => 'calendar',
            'tone' => 'info',
        ],
        [
            'value' => $todaySessionCount,
            'label' => "Today's Sessions",
            'hint' => $openSessionCount.' still open for check-in',
            'action' => 'Take attendance',
            'route' => 'admin.attendance',
            'icon' => 'clipboard',
            'tone' => 'success',
        ],
        [
            'value' => $pendingApplicationCount,
            'label' => 'Pending Applications',
            'hint' => 'Waiting for a decision',
            'action' => 'Review queue',
            'route' => 'admin.applications',
            'icon' => 'bell',
            'tone' => 'warning',
        ],
        [
            'value' => $recordedAchievementCount,
            'label' => 'Total Achievements',
            'hint' => 'Recorded across all sports',
            'action' => 'View achievements',
            'route' => 'admin.achievements',
            'icon' => 'certificate',
            'tone' => 'primary',
        ],
        [
            'value' => $medalCount,
            'label' => 'Medals Awarded',
            'hint' => 'Gold, silver and bronze',
            'action' => 'View achievements',
            'route' => 'admin.achievements',
            'icon' => 'trophy',
            'tone' => 'warning',
        ],
        [
            'value' => $attendanceRate.'%',
            'label' => 'Attendance Rate',
            'hint' => $presentTodayCount.' present today',
            'action' => 'Full report',
            'route' => 'reports.index',
            'icon' => 'chart',
            'tone' => 'success',
        ],
    ];

    return $adminPage('dashboard', [
        'metrics' => $metrics,
        'sportsStats' => [
            'athletes' => $athleteCount,
            'sports' => $sportCount,
            'achievements' => $recordedAchievementCount,
            'gold' => (int) $medalCounts->get('Gold Medal', 0),
            'silver' => (int) $medalCounts->get('Silver Medal', 0),
            'bronze' => (int) $medalCounts->get('Bronze Medal', 0),
        ],
        'achievementsBySport' => $achievementsBySport,
        'recentAchievements' => $recentAchievements,
        'upcomingEvents' => Event::with('sport')->where('status', 'Scheduled')->where('starts_at', '>=', now())->orderBy('starts_at')->limit(5)->get(),
        'athletesBySport' => Sport::withCount(['athletes' => fn ($query) => $query->where('role', 'Student')])->orderBy('name')->get(),
        'medicalStatusCounts' => [
            'Cleared' => (int) ($medicalStatusCounts->get('Cleared') ?? 0),
            'For Evaluation' => (int) ($medicalStatusCounts->get('For Evaluation') ?? 0),
            'Restricted Participation' => (int) ($medicalStatusCounts->get('Restricted Participation') ?? 0),
            'Expired' => (int) ($medicalStatusCounts->get('Expired') ?? 0),
        ],
        'attendanceToday' => [
            'counted' => $countedToday,
            'present' => (int) $attendanceToday->get('Present', 0),
            'late' => (int) $attendanceToday->get('Late', 0),
            'absent' => (int) $attendanceToday->get('Absent', 0),
            'excused' => (int) $attendanceToday->get('Excused', 0),
            'pending' => $pendingCheckInCount,
            'rate' => $attendanceRate,
            'sessions' => $todaySessionCount,
            'openSessions' => $openSessionCount,
        ],
        'medicalClearance' => [
            'cleared' => $clearedAthletes,
            'athletes' => $athleteCount,
        ],
        'pendingApplications' => Application::with('sportCategory')->where('status', 'Pending')->latest()->limit(5)->get(),
        'attentionItems' => collect([
            $pendingApplicationCount ? ['tone' => 'warning', 'message' => $pendingApplicationCount.' application(s) waiting for review', 'route' => 'admin.applications', 'action' => 'Review'] : null,
            $athletesMissingMedical ? ['tone' => 'danger', 'message' => $athletesMissingMedical.' athlete(s) need medical clearance', 'route' => 'admin.medical', 'action' => 'View'] : null,
            $checkupsDue ? ['tone' => 'warning', 'message' => $checkupsDue.' medical checkup(s) due within 30 days', 'route' => 'admin.medical', 'action' => 'View'] : null,
            $pendingCertificateRequests ? ['tone' => 'warning', 'message' => $pendingCertificateRequests.' certificate request(s) waiting for approval', 'route' => 'admin.certificate-requests', 'action' => 'Review'] : null,
        ])->filter()->values(),
        'recentActivity' => collect([
            ...User::where('role', 'Student')->latest('created_at')->limit(2)->get()->map(fn ($athlete) => ['label' => $athlete->name.' was added as an athlete', 'date' => $athlete->created_at])->all(),
            ...Event::with('sport')->latest('created_at')->limit(2)->get()->map(fn ($event) => ['label' => $event->title.' was created', 'date' => $event->created_at])->all(),
            ...Application::latest('created_at')->limit(2)->get()->map(fn ($application) => ['label' => $application->name.' submitted a sports application', 'date' => $application->created_at])->all(),
            ...MedicalRecord::active()->with('athlete')->latest('updated_at')->limit(2)->get()->map(fn ($record) => ['label' => 'Medical record updated'.($record->athlete?->name ? ' for '.$record->athlete->name : ''), 'date' => $record->updated_at])->all(),
            ...AttendanceSession::latest('created_at')->limit(2)->get()->map(fn ($session) => ['label' => 'Attendance session created for '.$session->effectiveSportName(), 'date' => $session->created_at])->all(),
            ...Achievement::with('athlete')->latest('created_at')->limit(2)->get()->map(fn ($achievement) => ['label' => $achievement->title.' awarded to '.($achievement->athlete?->name ?? 'an athlete'), 'date' => $achievement->created_at])->all(),
        ])->sortByDesc('date')->take(5)->values(),
    ]);
})->middleware('auth')->name('dashboard');
Route::get('/admin/calendar', fn () => $adminPage('calendar', $calendarData(request('month'))))->middleware('auth')->name('admin.calendar');
Route::get('/applications', [ApplicationController::class, 'index'])->middleware('auth')->name('admin.applications');
Route::get('/applications/{application}', [ApplicationController::class, 'show'])->middleware('auth')->name('applications.show');
Route::patch('/applications/{application}', [ApplicationController::class, 'update'])->middleware('auth')->name('applications.update');
Route::post('/applications/{application}/approve', [ApplicationController::class, 'approve'])->middleware('auth')->name('applications.approve');
Route::post('/applications/{application}/reject', [ApplicationController::class, 'reject'])->middleware('auth')->name('applications.reject');
Route::post('/applications/{application}/under-review', [ApplicationController::class, 'markUnderReview'])->middleware('auth')->name('applications.under-review');
Route::post('/applications/{application}/request-documents', [ApplicationController::class, 'requestDocuments'])->middleware('auth')->name('applications.request-documents');
Route::post('/applications/{application}/documents/{document}/verify', [ApplicationController::class, 'verifyDocument'])->middleware('auth')->name('applications.documents.verify');
Route::post('/applications/{application}/documents/{document}/reject', [ApplicationController::class, 'rejectDocument'])->middleware('auth')->name('applications.documents.reject');
Route::get('/applications/{application}/documents/{document}', function (Application $application, string $document) {
    abort_unless(auth()->user()?->role === 'Administrator', 403);
    $columns = ['medical' => 'medical_certificate_path', 'birth' => 'birth_certificate_path', 'consent' => 'parent_consent_path'];
    abort_unless(isset($columns[$document]) && $application->{$columns[$document]}, 404);
    $path = $application->{$columns[$document]};
    abort_unless(Storage::disk('private')->exists($path), 404, 'The requested document is no longer available.');

    return Storage::disk('private')->download($path);
})->middleware('auth')->name('applications.documents.download');
Route::resource('sports', SportController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy'])->middleware('auth');
Route::get('/sports/{sport}', [SportController::class, 'show'])->middleware('auth')->name('sports.show');
Route::get('/athletes', [AthleteController::class, 'index'])->middleware('auth')->name('athletes.index');
Route::get('/athletes/create/{application}', [AthleteController::class, 'create'])->middleware('auth')->name('athletes.create');
Route::post('/athletes', [AthleteController::class, 'store'])->middleware('auth')->name('athletes.store');
Route::get('/athletes/{athlete}', [AthleteController::class, 'show'])->middleware('auth')->name('athletes.show');
Route::get('/athletes/{athlete}/edit', [AthleteController::class, 'edit'])->middleware('auth')->name('athletes.edit');
Route::put('/athletes/{athlete}', [AthleteController::class, 'update'])->middleware('auth')->name('athletes.update');
Route::delete('/athletes/{athlete}', [AthleteController::class, 'destroy'])->middleware('auth')->name('athletes.destroy');

Route::post('/athletes/{athlete}/password-reset', [AthleteController::class, 'resetPassword'])
    ->middleware(['auth', 'administrator', 'password.confirm'])->name('athletes.password.reset');

Route::get('/admin/medical', [MedicalController::class, 'index'])->middleware('auth')->name('admin.medical');
Route::get('/admin/medical/create', [MedicalController::class, 'create'])->middleware('auth')->name('admin.medical.create');
Route::post('/admin/medical', [MedicalController::class, 'store'])->middleware('auth')->name('admin.medical.store');
Route::post('/admin/medical/{medical}/documents', [MedicalController::class, 'storeDocument'])->middleware('auth')->name('admin.medical.documents.store');
Route::get('/admin/medical/{medical}/documents/{document}/view', [MedicalController::class, 'viewDocument'])->middleware('auth')->name('admin.medical.documents.view');
Route::get('/admin/medical/{medical}/documents/{document}', [MedicalController::class, 'downloadDocument'])->middleware('auth')->name('admin.medical.documents.download');
Route::delete('/admin/medical/{medical}/documents/{document}', [MedicalController::class, 'destroyDocument'])->middleware('auth')->name('admin.medical.documents.destroy');
Route::post('/admin/medical/incidents', [MedicalIncidentController::class, 'store'])->middleware('auth')->name('admin.medical.incidents.store');
Route::put('/admin/medical/incidents/{incident}', [MedicalIncidentController::class, 'update'])->middleware('auth')->name('admin.medical.incidents.update');
Route::delete('/admin/medical/incidents/{incident}', [MedicalIncidentController::class, 'destroy'])->middleware('auth')->name('admin.medical.incidents.destroy');
Route::patch('/admin/medical/{medical}/archive', [MedicalController::class, 'archive'])->middleware('auth')->name('admin.medical.archive');
Route::patch('/admin/medical/{medical}/restore', [MedicalController::class, 'restore'])->middleware('auth')->name('admin.medical.restore');
Route::get('/admin/medical/{medical}/certificate/view', [MedicalController::class, 'viewCertificate'])->middleware('auth')->name('admin.medical.certificate.view');
Route::delete('/admin/medical/{medical}/certificate', [MedicalController::class, 'destroyCertificate'])->middleware('auth')->name('admin.medical.certificate.destroy');
Route::get('/admin/medical/{medical}', [MedicalController::class, 'show'])->middleware('auth')->name('admin.medical.show');
Route::get('/admin/medical/{medical}/edit', [MedicalController::class, 'edit'])->middleware('auth')->name('admin.medical.edit');
Route::put('/admin/medical/{medical}', [MedicalController::class, 'update'])->middleware('auth')->name('admin.medical.update');
Route::post('/admin/medical/{medical}/checkup-reminder', [MedicalController::class, 'sendCheckupReminder'])->middleware('auth')->name('admin.medical.checkup-reminder');
Route::delete('/admin/medical/{medical}', [MedicalController::class, 'destroy'])->middleware(['auth', 'administrator', 'password.confirm'])->name('admin.medical.destroy');
Route::get('/admin/medical/{medical}/certificate', [MedicalController::class, 'downloadCertificate'])->middleware('auth')->name('admin.medical.certificate');

/*
 * The medical follow-ups are part of the unified Messages & Follow-ups module
 * now. The old links keep working and send the sports office to the same
 * place.
 */
Route::get('/admin/medical-follow-ups', fn () => redirect()->route('admin.messages', ['tab' => 'medical']))->middleware('auth')->name('admin.medical.follow-ups');
Route::get('/admin/medical-follow-ups/create', fn () => redirect()->route('admin.messages.create', ['category' => 'medical_follow_up']))->middleware('auth')->name('admin.medical.follow-ups.create');
Route::post('/admin/medical-follow-ups', fn () => redirect()->route('admin.messages', ['tab' => 'medical']))->middleware('auth')->name('admin.medical.follow-ups.store');
Route::get('/admin/medical-follow-ups/{conversation}', fn () => redirect()->route('admin.messages', ['tab' => 'medical']))->middleware('auth')->name('admin.medical.follow-ups.show');
Route::post('/admin/medical-follow-ups/{conversation}/reply', fn () => redirect()->route('admin.messages', ['tab' => 'medical']))->middleware('auth')->name('admin.medical.follow-ups.reply');
Route::patch('/admin/medical-follow-ups/{conversation}/status', fn () => redirect()->route('admin.messages', ['tab' => 'medical']))->middleware('auth')->name('admin.medical.follow-ups.status');
Route::get('/admin/medical-follow-ups/{conversation}/attachments/{message}/view', fn () => redirect()->route('admin.messages', ['tab' => 'medical']))->middleware('auth')->name('admin.medical.follow-ups.attachments.view');
Route::get('/admin/medical-follow-ups/{conversation}/attachments/{message}', fn () => redirect()->route('admin.messages', ['tab' => 'medical']))->middleware('auth')->name('admin.medical.follow-ups.attachments.download');

Route::get('/admin/messages', [MessageController::class, 'index'])->middleware('auth')->name('admin.messages');
Route::get('/admin/messages/compose', [MessageController::class, 'create'])->middleware('auth')->name('admin.messages.create');
Route::post('/admin/messages', [MessageController::class, 'store'])->middleware('auth')->name('admin.messages.store');
Route::get('/admin/messages/{message}/edit', [MessageController::class, 'edit'])->middleware('auth')->name('admin.messages.edit');
Route::get('/admin/messages/{message}', [MessageController::class, 'show'])->middleware('auth')->name('admin.messages.show');
Route::post('/admin/messages/{message}/reply', [MessageController::class, 'adminReply'])->middleware('auth')->name('admin.messages.reply');
Route::put('/admin/messages/{message}', [MessageController::class, 'update'])->middleware('auth')->name('admin.messages.update');
Route::delete('/admin/messages/{message}', [MessageController::class, 'destroy'])->middleware('auth')->name('admin.messages.destroy');

Route::patch('/admin/messages/{message}/follow-up/complete', [FollowUpController::class, 'complete'])->middleware('auth')->name('admin.follow-ups.complete');
Route::patch('/admin/messages/{message}/follow-up/cancel', [FollowUpController::class, 'cancel'])->middleware('auth')->name('admin.follow-ups.cancel');
Route::patch('/admin/messages/{message}/follow-up/recipients/{recipient}/complete', [FollowUpController::class, 'completeRecipient'])->middleware('auth')->name('admin.follow-ups.recipient.complete');

/*
 * The follow-up actions are form actions and only accept a PATCH. A pasted
 * or bookmarked address lands on the message itself instead of an error page.
 */
Route::get('/admin/messages/{message}/follow-up/complete', fn (Message $message) => redirect()->route('admin.messages.show', $message))->middleware('auth')->name('admin.follow-ups.complete.form');
Route::get('/admin/messages/{message}/follow-up/cancel', fn (Message $message) => redirect()->route('admin.messages.show', $message))->middleware('auth')->name('admin.follow-ups.cancel.form');
Route::get('/admin/messages/{message}/follow-up/recipients/{recipient}/complete', fn (Message $message) => redirect()->route('admin.messages.show', $message))->middleware('auth')->name('admin.follow-ups.recipient.complete.form');

Route::get('/admin/achievements', [AchievementController::class, 'index'])->middleware('auth')->name('admin.achievements');
Route::get('/admin/achievements/create', [AchievementController::class, 'create'])->middleware('auth')->name('admin.achievements.create');
Route::post('/admin/achievements', [AchievementController::class, 'store'])->middleware('auth')->name('admin.achievements.store');
Route::get('/admin/achievements/certificate', [AchievementController::class, 'generate'])->middleware('auth')->name('admin.achievements.certificate.generate');
Route::get('/admin/achievements/{achievement}/certificate/print', [AchievementController::class, 'renderCertificate'])->middleware('auth')->name('admin.achievements.certificate.print');
Route::get('/admin/achievements/{achievement}/certificate/pdf', [AchievementController::class, 'downloadCertificate'])->middleware('auth')->name('admin.achievements.certificate.pdf');
Route::get('/admin/achievements/{achievement}', [AchievementController::class, 'show'])->middleware('auth')->name('admin.achievements.show');
Route::get('/admin/achievements/{achievement}/edit', [AchievementController::class, 'edit'])->middleware('auth')->name('admin.achievements.edit');
Route::put('/admin/achievements/{achievement}', [AchievementController::class, 'update'])->middleware('auth')->name('admin.achievements.update');
Route::delete('/admin/achievements/{achievement}', [AchievementController::class, 'destroy'])->middleware('auth')->name('admin.achievements.destroy');
Route::get('/admin/achievements/{achievement}/certificate', [AchievementController::class, 'certificate'])->middleware('auth')->name('admin.achievements.certificate');
Route::post('/admin/achievements/{achievement}/certificate/issue', [AchievementController::class, 'issueCertificate'])->middleware('auth')->name('admin.achievements.certificate.issue');
Route::get('/admin/certificate-requests', [CertificateRequestController::class, 'index'])->middleware('auth')->name('admin.certificate-requests');
Route::post('/admin/certificate-requests/{certificateRequest}/approve', [CertificateRequestController::class, 'approve'])->middleware('auth')->name('admin.certificate-requests.approve');
Route::post('/admin/certificate-requests/{certificateRequest}/generate', [CertificateRequestController::class, 'generate'])->middleware('auth')->name('admin.certificate-requests.generate');
Route::post('/admin/certificate-requests/{certificateRequest}/release', [CertificateRequestController::class, 'release'])->middleware('auth')->name('admin.certificate-requests.release');
Route::post('/admin/certificate-requests/{certificateRequest}/issue', [CertificateRequestController::class, 'issue'])->middleware('auth')->name('admin.certificate-requests.issue');
Route::post('/admin/certificate-requests/{certificateRequest}/reject', [CertificateRequestController::class, 'reject'])->middleware('auth')->name('admin.certificate-requests.reject');
Route::resource('coaches', CoachController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'])->middleware('auth');
Route::resource('events', EventController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy'])->middleware('auth');
Route::get('/events/{event}', [EventController::class, 'show'])->middleware('auth')->name('events.show');
Route::post('/events/{event}/cancel', [EventController::class, 'cancel'])->middleware('auth')->name('events.cancel');
Route::get('/admin/events', fn () => redirect()->route('events.index'))->middleware('auth')->name('admin.events.index');
Route::get('/admin/attendance', [AttendanceController::class, 'index'])->middleware('auth')->name('admin.attendance');
Route::post('/admin/attendance/sessions', [AttendanceController::class, 'store'])->middleware('auth')->name('admin.attendance.sessions.store');
Route::patch('/admin/attendance/sessions/{session}/open', [AttendanceController::class, 'open'])->middleware('auth')->name('admin.attendance.sessions.open');
Route::patch('/admin/attendance/sessions/{session}/close', [AttendanceController::class, 'close'])->middleware('auth')->name('admin.attendance.sessions.close');
Route::patch('/admin/attendance/sessions/{session}/cancel', [AttendanceController::class, 'cancel'])->middleware('auth')->name('admin.attendance.sessions.cancel');
Route::patch('/admin/attendance/sessions/{session}/sync', [AttendanceController::class, 'sync'])->middleware('auth')->name('admin.attendance.sessions.sync');
Route::delete('/admin/attendance/sessions/{session}', [AttendanceController::class, 'destroy'])->middleware('auth')->name('admin.attendance.sessions.destroy');
Route::get('/admin/attendance/sessions/{session}/export', [AttendanceController::class, 'export'])->middleware('auth')->name('admin.attendance.sessions.export');
Route::get('/admin/attendance/sessions/{session}/export/pdf', [AttendanceController::class, 'exportPdf'])->middleware('auth')->name('admin.attendance.sessions.export.pdf');
Route::put('/admin/attendance/records/{attendance}', [AttendanceController::class, 'updateRecord'])->middleware('auth')->name('admin.attendance.records.update');

Route::get('/reports', [ReportController::class, 'index'])->middleware('auth')->name('reports.index');

Route::get('/reports/export', [ReportController::class, 'export'])->middleware('auth')->name('reports.export');
});
