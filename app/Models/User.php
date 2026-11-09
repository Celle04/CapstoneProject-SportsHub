<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    public const GRADE_LEVELS = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];

    public const OTHER_GRADE_FILTER = '__other';

    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'username', 'role', 'status', 'sport_id', 'student_id', 'phone', 'grade_level', 'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone', 'profile_photo_path', 'password', 'must_change_password'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'must_change_password' => 'boolean',
    ];

    public function sport()
    {
        return $this->belongsTo(Sport::class);
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages(): HasMany
    {
        return $this->hasMany(MessageRecipient::class, 'recipient_id');
    }

    /**
     * Deliver the password broker's reset token through the SportsHub-branded
     * notification instead of the framework default. The token itself is
     * still created, hashed and expired by the broker.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function attendanceRecords()
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Attendance sessions this athlete is eligible for, based on assigned sport.
     */
    public function attendanceSessions()
    {
        return AttendanceSession::query()->visibleToAthlete($this);
    }

    public function medicalRecords()
    {
        return $this->hasMany(MedicalRecord::class, 'athlete_id');
    }

    public function currentMedicalRecord(): HasOne
    {
        return $this->hasOne(MedicalRecord::class, 'athlete_id')
            ->ofMany(
                ['examination_date' => 'max', 'id' => 'max'],
                fn (Builder $query) => $query->whereNull('archived_at'),
            );
    }

    /**
     * Injuries and medical incidents recorded for this athlete.
     */
    public function medicalIncidents()
    {
        return $this->hasMany(MedicalIncident::class, 'athlete_id');
    }

    public function applications()
    {
        return $this->hasMany(Application::class, 'athlete_id');
    }

    /**
     * Accomplishments recorded for this athlete.
     */
    public function achievements()
    {
        return $this->hasMany(Achievement::class, 'athlete_id');
    }

    /**
     * Sports this athlete belongs to: the sport assigned on the account plus
     * every sport coming from an approved application.
     *
     * @return array<int, int>
     */
    public function sportIds(): array
    {
        return collect([$this->sport_id])
            ->merge($this->applications()
                ->where('status', 'Approved')
                ->whereNotNull('sport_id')
                ->pluck('sport_id'))
            ->filter()
            ->unique()
            ->map(fn ($sportId) => (int) $sportId)
            ->values()
            ->all();
    }

    /**
     * Coaches assigned to the sports this athlete belongs to.
     */
    public function coaches()
    {
        return Coach::query()
            ->with('sport')
            ->whereIn('sport_id', $this->sportIds())
            ->where('status', 'Active')
            ->orderBy('name');
    }

    /**
     * The athlete's own sports, each carrying only its active coaches so the
     * coach module can flag the sports that have no coach yet.
     */
    public function sportsWithCoaches()
    {
        return Sport::query()
            ->whereIn('id', $this->sportIds())
            ->with(['coaches' => fn ($query) => $query->where('status', 'Active')->orderBy('name')])
            ->orderBy('name')
            ->get();
    }

    /**
     * Athlete list filters shared by Athlete Management and the Athlete
     * Report, so both screens always return the same set of athletes for the
     * same search and filter values.
     */
    public function scopeAthleteFilters(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $grade = trim((string) ($filters['grade'] ?? ''));

        return $query
            ->when($search !== '', fn (Builder $nested) => $nested->where(function (Builder $athleteQuery) use ($search) {
                $athleteQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when(! empty($filters['sport_id']), fn (Builder $nested) => $nested->where('sport_id', (int) $filters['sport_id']))
            ->when($grade !== '', function (Builder $nested) use ($grade) {
                if ($grade === self::OTHER_GRADE_FILTER) {
                    $otherGradeValues = array_merge(self::GRADE_LEVELS, ['7', '8', '9', '10', '11', '12']);

                    return $nested->whereHas('applications', fn (Builder $applications) => $applications
                        ->whereNotNull('grade')
                        ->whereNotIn('grade', $otherGradeValues));
                }

                $canonicalGrade = self::canonicalGradeLevel($grade);
                $gradeValues = $canonicalGrade
                    ? [$canonicalGrade, substr($canonicalGrade, strlen('Grade '))]
                    : [$grade];

                return $nested->whereHas('applications', fn (Builder $applications) => $applications->whereIn('grade', $gradeValues));
            })
            ->when(! empty($filters['gender']), fn (Builder $nested) => $nested->whereHas('applications', fn (Builder $applications) => $applications->where('gender', (string) $filters['gender'])))
            ->when(! empty($filters['status']), fn (Builder $nested) => $nested->where('status', (string) $filters['status']))
            ->when(! empty($filters['eligibility']), fn (Builder $nested) => $this->applyEligibilityFilter($nested, (string) $filters['eligibility']));
    }

    /**
     * The eligibility rules shown on the Athlete Management list.
     */
    private function applyEligibilityFilter(Builder $query, string $eligibility): Builder
    {
        $matchesNotEligible = fn (Builder $nested) => $nested
            ->whereHas('applications', fn (Builder $applications) => $applications->where('status', 'Rejected'))
            ->orWhereHas('currentMedicalRecord', fn (Builder $medical) => $medical
                ->whereIn('medical_status', ['Restricted Participation', 'Restricted', 'Not Cleared', 'Expired'])
                ->orWhereRaw('COALESCE(expiration_date, next_checkup_date) < ?', [today()->toDateString()]));

        $matchesEligible = fn (Builder $nested) => $nested
            ->whereHas('applications', fn (Builder $applications) => $applications->where('status', 'Approved'))
            ->whereHas('currentMedicalRecord', fn (Builder $medical) => $medical
                ->where('medical_status', 'Cleared')
                ->where(fn (Builder $valid) => $valid
                    ->whereNull('expiration_date')
                    ->whereNull('next_checkup_date')
                    ->orWhereRaw('COALESCE(expiration_date, next_checkup_date) >= ?', [today()->toDateString()])));

        return match ($eligibility) {
            'Not Eligible' => $query->where($matchesNotEligible),
            'Eligible' => $query->where($matchesEligible)->whereNot($matchesNotEligible),
            default => $query->whereNot($matchesEligible)->whereNot($matchesNotEligible),
        };
    }

    /**
     * Eligibility requires an approved application and a current valid
     * medical clearance. Historical examinations do not override the latest
     * active examination.
     */
    public function eligibilityStatus(): string
    {
        $applications = $this->relationLoaded('applications') ? $this->applications : $this->applications()->get();
        $medicalRecord = $this->relationLoaded('medicalRecords')
            ? $this->medicalRecords->whereNull('archived_at')->sortByDesc(fn (MedicalRecord $record) => [$record->examination_date?->format('Y-m-d') ?? '', $record->id])->first()
            : $this->currentMedicalRecord()->first();

        if ($applications->contains(fn (Application $application) => $application->status === 'Rejected')
            || ($medicalRecord && in_array($medicalRecord->effectiveStatus(), ['Expired', 'Restricted Participation'], true))) {
            return 'Not Eligible';
        }

        if ($applications->contains(fn (Application $application) => $application->status === 'Approved')
            && $medicalRecord?->effectiveStatus() === 'Cleared') {
            return 'Eligible';
        }

        return 'Pending';
    }

    /**
     * Whether the athlete must choose a new password before using the portal,
     * e.g. after an administrator-assisted reset issued a temporary password.
     */
    public function needsPasswordChange(): bool
    {
        return (bool) $this->must_change_password;
    }

    /**
     * Mark the account as requiring a password change on the next login.
     */
    public function markPasswordChangeRequired(): void
    {
        $this->forceFill(['must_change_password' => true])->save();
    }

    /**
     * Clear the forced password-change requirement after the athlete has
     * chosen a new password.
     */
    public function clearPasswordChangeRequirement(): void
    {
        $this->forceFill(['must_change_password' => false])->save();
    }

    /**
     * Public URL of the uploaded profile photo, when the athlete has one.
     */
    public function profilePhotoUrl(): ?string
    {
        return $this->profile_photo_path
            ? Storage::disk('public')->url($this->profile_photo_path)
            : null;
    }

    /**
     * Keep the applicant details on any linked application in step with the
     * athlete account, so the admin Applications list, the application review
     * page and the reports never show stale values after a profile update.
     */
    public function syncLinkedApplications(): void
    {
        if (! $this->applications()->exists()) {
            return;
        }

        $attributes = [
            'name' => $this->name,
            'email' => $this->email,
        ];

        if (filled($this->grade_level)) {
            $attributes['grade'] = $this->grade_level;
        }

        $this->applications()->update($attributes);
    }

    /**
     * The athlete's current grade level.
     *
     * Prefers the value the athlete keeps on their profile and falls back to
     * the grade captured on their most recent application, so the field is
     * never blank for an athlete who applied before it became editable.
     */
    public function gradeLevel(): ?string
    {
        if (filled($this->grade_level)) {
            return $this->grade_level;
        }

        return $this->applications->sortByDesc('created_at')->first()?->grade;
    }

    public static function canonicalGradeLevel(?string $grade): ?string
    {
        $grade = trim((string) $grade);

        if (preg_match('/^(?:Grade\s*)?(7|8|9|10|11|12)$/i', $grade, $matches) !== 1) {
            return null;
        }

        return 'Grade '.$matches[1];
    }

    /**
     * Whether a usable emergency contact has been recorded. A name and a
     * number are what staff actually need to place a call.
     */
    public function hasEmergencyContact(): bool
    {
        return filled($this->emergency_contact_name) && filled($this->emergency_contact_phone);
    }

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    /**
     * Up to two initials used by the default profile avatar.
     */
    protected function initials(): Attribute
    {
        return Attribute::get(function (): string {
            $words = preg_split('/\s+/', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($words === []) {
                return strtoupper(substr((string) $this->email, 0, 2));
            }

            $initials = mb_substr($words[0], 0, 1);
            if (count($words) > 1) {
                $initials .= mb_substr(end($words), 0, 1);
            }

            return mb_strtoupper($initials);
        });
    }
}
