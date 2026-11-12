<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A medical follow-up is the medical side of a message.
 *
 * The message itself carries the delivery and the read tracking for every
 * athlete it reached; this record only holds what is specific to a follow-up:
 * the schedule, the instructions and the acknowledgment and completion state
 * of each athlete.
 */
class MedicalFollowUp extends Model
{
    public const STATUS_SCHEDULED = 'Scheduled';
    public const STATUS_SENT = 'Sent';
    public const STATUS_READ = 'Read';
    public const STATUS_ACKNOWLEDGED = 'Acknowledged';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_OVERDUE = 'Overdue';
    public const STATUS_CANCELLED = 'Cancelled';

    /**
     * The statuses that are stored on a follow-up. `Overdue` is derived from
     * the scheduled date and the work that is still open, so it is never
     * written to the record.
     */
    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_SENT,
        self::STATUS_READ,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const TYPES = [
        'Medical Checkup Reminder',
        'Medical Clearance Follow-up',
        'Medical Document Submission',
        'Medical Re-examination',
        'Other',
    ];

    protected $fillable = [
        'message_id',
        'follow_up_type',
        'scheduled_date',
        'scheduled_time',
        'location',
        'additional_instructions',
        'response_required',
        'status',
        'completed_by',
        'completed_at',
        'cancelled_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'response_required' => 'boolean',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Whether the follow-up was archived and therefore left the list.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The follow-ups that are still in the list, or only the archived ones.
     */
    public function scopeArchived(Builder $query, bool $archived = false): Builder
    {
        return $archived
            ? $query->whereNotNull('archived_at')
            : $query->whereNull('archived_at');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MedicalFollowUpRecipient::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * The status shown to the administrator and the athlete.
     *
     * A follow-up becomes overdue once its date has passed while the work is
     * still open. A follow-up the athlete already acknowledged, or one the
     * administrator completed or cancelled, is never overdue: the first one is
     * waiting for verification and the last two are settled.
     */
    public function effectiveStatus(): string
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            return $this->status;
        }

        if ($this->status === self::STATUS_ACKNOWLEDGED) {
            return $this->status;
        }

        return $this->isOverdue() ? self::STATUS_OVERDUE : $this->status;
    }

    public function isOverdue(): bool
    {
        if (! $this->scheduled_date) {
            return false;
        }

        return $this->scheduled_date->isPast()
            && ! in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_ACKNOWLEDGED], true);
    }

    /**
     * Whether the status is worth showing next to a message.
     *
     * `Sent` and `Read` are states the athlete already sees on the message
     * itself, so a badge for them only repeats the same word.
     */
    public function warrantsBadge(): bool
    {
        return in_array($this->status, [
            self::STATUS_ACKNOWLEDGED,
            self::STATUS_COMPLETED,
            self::STATUS_OVERDUE,
            self::STATUS_CANCELLED,
        ], true);
    }

    /**
     * Recalculates the stored status from what the recipients actually did.
     *
     * Reading or acknowledging never completes a follow-up: only the
     * administrator can close one after verifying it.
     */
    public function syncStatus(): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            return;
        }

        $recipients = $this->recipients()->get();

        if ($recipients->isEmpty()) {
            $this->status = $this->message?->status === 'sent' ? self::STATUS_SENT : self::STATUS_SCHEDULED;
            $this->save();

            return;
        }

        $status = match (true) {
            $recipients->every(fn (MedicalFollowUpRecipient $recipient) => $recipient->completed_at !== null) => self::STATUS_COMPLETED,
            $recipients->every(fn (MedicalFollowUpRecipient $recipient) => $recipient->acknowledged_at !== null) => self::STATUS_ACKNOWLEDGED,
            $recipients->every(fn (MedicalFollowUpRecipient $recipient) => $recipient->read_at !== null) => self::STATUS_READ,
            default => self::STATUS_SENT,
        };

        if ($status !== $this->status) {
            $this->status = $status;
            $this->save();
        }
    }

    public function readCount(): int
    {
        return $this->recipients->filter(fn (MedicalFollowUpRecipient $recipient) => $recipient->read_at !== null)->count();
    }

    public function acknowledgedCount(): int
    {
        return $this->recipients->filter(fn (MedicalFollowUpRecipient $recipient) => $recipient->acknowledged_at !== null)->count();
    }

    public function completedCount(): int
    {
        return $this->recipients->filter(fn (MedicalFollowUpRecipient $recipient) => $recipient->completed_at !== null)->count();
    }

    public function recipientCount(): int
    {
        return $this->recipients->count();
    }

    /**
     * The scheduled date and time as one line of text.
     */
    public function scheduleLabel(): string
    {
        if (! $this->scheduled_date) {
            return 'No schedule set';
        }

        $label = $this->scheduled_date->format('F j, Y');

        if ($this->scheduled_time) {
            $label .= ' at '.\Illuminate\Support\Carbon::parse($this->scheduled_time)->format('g:i A');
        }

        if ($this->location) {
            $label .= ' · '.$this->location;
        }

        return $label;
    }
}
