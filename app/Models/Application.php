<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    protected $fillable = ['reference_number', 'name', 'first_name', 'middle_name', 'last_name', 'student_id', 'grade', 'gender', 'email', 'sport', 'sport_id', 'medical_certificate_path', 'birth_certificate_path', 'parent_consent_path', 'privacy_accepted', 'terms_accepted', 'policy_accepted_at', 'medical_document_status', 'birth_document_status', 'consent_document_status', 'status', 'rejection_reason', 'review_notes', 'admin_instructions', 'reviewed_by', 'reviewed_at', 'documents_requested', 'document_rejection_notes', 'review_history', 'athlete_id'];

    public static function generateReferenceNumber(): string
    {
        do {
            $reference = 'SNNHS-APP-'.now()->format('Y').'-'.strtoupper(\Illuminate\Support\Str::random(8));
        } while (self::query()->where('reference_number', $reference)->exists());

        return $reference;
    }

    public static function normalizeStatus(?string $status): ?string
    {
        return match ($status) {
            'Documents Required' => 'Requires Revision',
            default => $status,
        };
    }

    protected static function booted(): void
    {
        static::creating(function (self $application) {
            if (blank($application->reference_number)) {
                $application->reference_number = self::generateReferenceNumber();
            }
            $application->status = self::normalizeStatus($application->status) ?: 'Pending';
        });

        static::saving(function (self $application) {
            if (filled($application->status)) {
                $application->status = self::normalizeStatus($application->status);
            }
        });
    }

    /**
     * Compose the display name from parts when available.
     */
    public function getNameAttribute($value): ?string
    {
        $first = trim((string) ($this->attributes['first_name'] ?? ''));
        $last = trim((string) ($this->attributes['last_name'] ?? ''));

        if ($first !== '' || $last !== '') {
            $middle = trim((string) ($this->attributes['middle_name'] ?? ''));

            return trim(implode(' ', array_filter([$first, $middle !== '' ? $middle : null, $last], fn ($part) => $part !== null && $part !== '')));
        }

        return $value;
    }

    public static function composeName(?string $firstName, ?string $middleName, ?string $lastName): string
    {
        return trim(implode(' ', array_filter([
            trim((string) $firstName),
            trim((string) $middleName) !== '' ? trim((string) $middleName) : null,
            trim((string) $lastName),
        ], fn ($part) => $part !== null && $part !== '')));
    }

    protected function casts(): array
    {
        return ['privacy_accepted' => 'boolean', 'terms_accepted' => 'boolean', 'policy_accepted_at' => 'datetime', 'reviewed_at' => 'datetime', 'documents_requested' => 'array', 'document_rejection_notes' => 'array', 'review_history' => 'array'];
    }

    public function sportCategory()
    {
        return $this->belongsTo(Sport::class, 'sport_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function athlete()
    {
        return $this->belongsTo(User::class, 'athlete_id');
    }

    public function statusHistories()
    {
        return $this->hasMany(ApplicationStatusHistory::class)->latest();
    }
}
