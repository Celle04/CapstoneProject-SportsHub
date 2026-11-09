<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class MedicalRecord extends Model
{
    /**
     * Clearance statuses used across the Medical module and the dashboard.
     *
     * Cleared     - the athlete may participate normally.
     * Pending     - the medical evaluation is incomplete.
     * Restricted  - the athlete may participate with restrictions.
     * Not Cleared - the athlete should not participate.
     *
     * @var array<int, string>
     */
    public const STATUSES = ['For Evaluation', 'Cleared', 'Restricted Participation', 'Expired'];

    public const LEGACY_STATUSES = ['Pending', 'Restricted', 'Not Cleared'];

    /**
     * Expiration states a certificate or checkup deadline can be in.
     *
     * @var array<int, string>
     */
    public const CERTIFICATE_STATES = ['Valid', 'Expiring Soon', 'Expired', 'Missing'];

    public const CERTIFICATE_VALID = 'Valid';

    public const CERTIFICATE_EXPIRING = 'Expiring Soon';

    public const CERTIFICATE_EXPIRED = 'Expired';

    public const CERTIFICATE_MISSING = 'Missing';

    protected $fillable = [
        'athlete_id',
        'user_id',
        'examination_date',
        'medical_status',
        'next_checkup_date',
        'findings',
        'restrictions',
        'notes',
        'medical_certificate',
        'last_checkup',
        'clearance',
        'archived_at',
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
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'examination_date' => 'date',
            'next_checkup_date' => 'date',
            'last_checkup' => 'date',
            'clearance_date' => 'date',
            'expiration_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Every record belongs to an athlete account, and the schema keeps both
     * athlete_id and user_id in sync. Filling user_id here means the module
     * and any other writer can never trip the NOT NULL constraint.
     */
    protected static function booted(): void
    {
        static::creating(function (MedicalRecord $record) {
            if ($record->user_id === null) {
                $record->user_id = $record->athlete_id;
            }
        });
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, $this->athlete_id ? 'athlete_id' : 'user_id');
    }

    /**
     * Injuries recorded for this record's athlete. Incidents are stored per
     * athlete so a new medical record never has to re-describe old injuries.
     *
     * @return HasMany<MedicalIncident, $this>
     */
    public function injuries(): HasMany
    {
        return $this->hasMany(MedicalIncident::class, 'athlete_id', 'athlete_id');
    }

    /**
     * @return HasMany<MedicalDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(MedicalDocument::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return HasMany<MedicalRecordHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(MedicalRecordHistory::class)->latest();
    }

    /**
     * Records that are still listed on the module (not archived).
     *
     * @param  Builder<MedicalRecord>  $query
     * @return Builder<MedicalRecord>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Applies the records table filters in a single query.
     *
     * @param  Builder<MedicalRecord>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<MedicalRecord>
     */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $sportId = $filters['sport_id'] ?? null;
        $status = $filters['status'] ?? null;
        $certificate = $filters['certificate'] ?? null;
        $examinationDate = $filters['examination_date'] ?? null;
        $archived = (bool) ($filters['archived'] ?? false);

        return $query
            ->when(
                $archived,
                fn (Builder $nested) => $nested->whereNotNull('archived_at'),
                fn (Builder $nested) => $nested->active(),
            )
            ->when($search !== '', fn (Builder $nested) => $nested->where(function (Builder $recordQuery) use ($search) {
                $recordQuery->whereHas('athlete', fn (Builder $athleteQuery) => $athleteQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%"));
            }))
            ->when($sportId, fn (Builder $nested) => $nested->whereHas('athlete', fn (Builder $athleteQuery) => $athleteQuery->where('sport_id', $sportId)))
            ->when($status, function (Builder $nested) use ($status) {
                if ($status === 'Expired') {
                    return $nested->where(function (Builder $expired) {
                        $expired->where('medical_status', 'Expired')
                            ->orWhereRaw('COALESCE(expiration_date, next_checkup_date) < ?', [today()->toDateString()]);
                    });
                }

                return $nested->where('medical_status', $status)
                    ->where(function (Builder $valid) {
                        $valid->whereNull('expiration_date')
                            ->whereNull('next_checkup_date')
                            ->orWhereRaw('COALESCE(expiration_date, next_checkup_date) >= ?', [today()->toDateString()]);
                    });
            })
            ->when($examinationDate, fn (Builder $nested) => $nested->whereDate('examination_date', $examinationDate))
            ->when($certificate, fn (Builder $nested, string $state) => $this->applyCertificateFilter($nested, $state));
    }

    /**
     * Certificate status is derived from the uploaded file and the recorded
     * expiration date, so it has to be expressed as SQL conditions.
     *
     * @param  Builder<MedicalRecord>  $query
     * @return Builder<MedicalRecord>
     */
    private function applyCertificateFilter(Builder $query, string $state): Builder
    {
        $warningEnd = today()->addDays($this->expiringDays())->toDateString();

        return match ($state) {
            self::CERTIFICATE_MISSING => $query->whereNull('medical_certificate'),
            self::CERTIFICATE_EXPIRED => $query
                ->whereNotNull('medical_certificate')
                ->whereRaw('COALESCE(expiration_date, next_checkup_date) < ?', [today()->toDateString()]),
            self::CERTIFICATE_EXPIRING => $query
                ->whereNotNull('medical_certificate')
                ->whereRaw('COALESCE(expiration_date, next_checkup_date) between ? and ?', [today()->toDateString(), $warningEnd]),
            default => $query
                ->whereNotNull('medical_certificate')
                ->where(fn (Builder $expiryQuery) => $expiryQuery
                    ->whereNull('expiration_date')
                    ->whereNull('next_checkup_date')
                    ->orWhereRaw('COALESCE(expiration_date, next_checkup_date) > ?', [$warningEnd])),
        };
    }

    /**
     * Checkups that are due from today onwards.
     *
     * @param  Builder<MedicalRecord>  $query
     * @return Builder<MedicalRecord>
     */
    public function scopeUpcomingCheckups(Builder $query): Builder
    {
        return $query->whereNotNull('next_checkup_date')->whereDate('next_checkup_date', '>=', today()->toDateString());
    }

    /**
     * Checkups whose date has already passed.
     *
     * @param  Builder<MedicalRecord>  $query
     * @return Builder<MedicalRecord>
     */
    public function scopeOverdueCheckups(Builder $query): Builder
    {
        return $query->whereNotNull('next_checkup_date')->whereDate('next_checkup_date', '<', today()->toDateString());
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Certificates live on the private disk, so they are served through an
     * authorized route instead of a public URL.
     */
    public function hasCertificate(): bool
    {
        return filled($this->medical_certificate)
            && Storage::disk('private')->exists($this->medical_certificate);
    }

    public function certificateDiskName(): string
    {
        return 'medical-certificate-'.$this->id.'.'.pathinfo((string) $this->medical_certificate, PATHINFO_EXTENSION);
    }

    /**
     * The date the certificate or clearance stops being valid. The next checkup
     * date is the deadline the school already tracks, so it doubles as the
     * expiration date for the expiration panel.
     */
    public function certificateExpiryDate(): ?Carbon
    {
        return $this->expiration_date ?? $this->next_checkup_date;
    }

    /**
     * Derived from the stored column and the recorded expiration date so the
     * badge always agrees with the certificate filter in the query builder.
     */
    public function certificateState(): string
    {
        if (! filled($this->medical_certificate)) {
            return self::CERTIFICATE_MISSING;
        }

        $expiry = $this->certificateExpiryDate();

        if ($expiry === null) {
            return self::CERTIFICATE_VALID;
        }

        if ($expiry->lt(today())) {
            return self::CERTIFICATE_EXPIRED;
        }

        if ($expiry->lte(today()->addDays($this->expiringDays()))) {
            return self::CERTIFICATE_EXPIRING;
        }

        return self::CERTIFICATE_VALID;
    }

    /**
     * Whole days until the deadline, negative once it has passed.
     */
    public function daysUntilExpiry(): ?int
    {
        $expiry = $this->certificateExpiryDate();

        if ($expiry === null) {
            return null;
        }

        $days = (int) $expiry->copy()->startOfDay()->diffInDays(today()->startOfDay(), true);

        return $expiry->lt(today()) ? -$days : $days;
    }

    public function isExpiringSoon(): bool
    {
        $expiry = $this->certificateExpiryDate();

        return $expiry !== null
            && $expiry->gte(today())
            && $expiry->lte(today()->addDays($this->expiringDays()));
    }

    public function isExpired(): bool
    {
        $expiry = $this->certificateExpiryDate();

        return $expiry !== null && $expiry->lt(today());
    }

    public function statusBadgeClass(): string
    {
        return 'badge medical-status-'.str_replace(' ', '-', strtolower($this->effectiveStatus()));
    }

    public function effectiveStatus(): string
    {
        if ($this->isExpired() || $this->medical_status === 'Expired') {
            return 'Expired';
        }

        return match ($this->medical_status) {
            'Pending' => 'For Evaluation',
            'Restricted', 'Not Cleared' => 'Restricted Participation',
            default => (string) $this->medical_status,
        };
    }

    public function statusDescription(): string
    {
        return match ($this->effectiveStatus()) {
            'Cleared' => 'Cleared for participation, subject to other school requirements.',
            'For Evaluation' => 'Further medical assessment is required.',
            'Restricted Participation' => 'Participation is limited by the recorded restrictions.',
            'Expired' => 'Clearance has expired and needs renewal.',
            default => 'No clearance decision has been recorded.',
        };
    }

    public function certificateBadgeClass(): string
    {
        return match ($this->certificateState()) {
            self::CERTIFICATE_VALID => 'document-badge medical-certificate-valid',
            self::CERTIFICATE_EXPIRING => 'document-badge medical-certificate-expiring',
            self::CERTIFICATE_EXPIRED => 'document-badge medical-certificate-expired',
            default => 'document-badge medical-certificate-missing',
        };
    }

    public function sportName(): string
    {
        return $this->athlete?->sport?->name ?? 'Unassigned';
    }

    public function examinationDateLabel(): string
    {
        return $this->examination_date?->format('M j, Y') ?? 'Not recorded';
    }

    public function nextCheckupLabel(): string
    {
        return $this->next_checkup_date?->format('M j, Y') ?? 'Not scheduled';
    }

    private function expiringDays(): int
    {
        return max(1, (int) config('medical.expiring_days', 30));
    }
}
