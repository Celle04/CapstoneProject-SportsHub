<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailReminder extends Model
{
    public const CATEGORIES = [
        'Medical Check-up Reminder',
        'Training Schedule Reminder',
        'Sports Event Reminder',
        'Competition Reminder',
        'Requirements Submission Reminder',
        'General Reminder',
    ];

    public const RECIPIENT_TYPES = [
        'all' => 'All registered athletes',
        'sport' => 'Athletes from a selected sport',
        'team' => 'Athletes from a selected team',
        'individual' => 'Individually selected athlete',
        'selected' => 'Individually selected athletes',
    ];

    protected $fillable = [
        'admin_id',
        'category',
        'subject',
        'message',
        'recipient_type',
        'sport_id',
        'recipient_ids',
        'relevant_date',
        'relevant_time',
        'status',
        'sent_count',
        'failed_count',
        'recipient_count',
        'queued_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'recipient_ids' => 'array',
            'relevant_date' => 'date',
            'queued_at' => 'datetime',
            'completed_at' => 'datetime',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'recipient_count' => 'integer',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailReminderRecipient::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, ['sent', 'failed', 'partial'], true);
    }

    public function recipientTypeLabel(): string
    {
        $label = self::RECIPIENT_TYPES[$this->recipient_type] ?? ucfirst((string) $this->recipient_type);
        if (in_array($this->recipient_type, ['sport', 'team'], true) && $this->sport) {
            $label .= ' — '.$this->sport->name;
        }

        return $label;
    }
}
