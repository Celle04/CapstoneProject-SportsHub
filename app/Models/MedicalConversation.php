<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MedicalConversation extends Model
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_RESPONDED = 'Responded';
    public const STATUS_RESOLVED = 'Resolved';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RESPONDED,
        self::STATUS_RESOLVED,
    ];

    public const CATEGORIES = [
        'Medical Check-up Follow-up',
        'Medical Certificate Submission',
        'Medical Clearance',
        'Health Assessment Update',
        'Missing Medical Requirements',
        'General Medical Inquiry',
    ];

    protected $fillable = [
        'athlete_id',
        'created_by',
        'subject',
        'category',
        'status',
    ];

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'athlete_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MedicalMessage::class, 'conversation_id')->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(MedicalMessage::class, 'conversation_id')->latestOfMany();
    }

    public function scopeForAthlete(Builder $query, User $athlete): Builder
    {
        return $query->where('athlete_id', $athlete->id);
    }

    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        if ($user->role === 'Student') {
            return $query->where('athlete_id', $user->id);
        }

        return $query;
    }

    public function isParticipant(User $user): bool
    {
        if ($user->role === 'Student') {
            return (int) $this->athlete_id === (int) $user->id;
        }

        return $user->role === 'Administrator';
    }

    public function hasUnreadFor(User $user): bool
    {
        return $this->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $user->id)
            ->exists();
    }

    public function unreadCountFor(User $user): int
    {
        return $this->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $user->id)
            ->count();
    }

    public function markReadFor(User $user): void
    {
        $this->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $user->id)
            ->update(['read_at' => now()]);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_RESPONDED => 'status-approved',
            self::STATUS_RESOLVED => 'status-secondary',
            default => 'status-pending',
        };
    }
}
