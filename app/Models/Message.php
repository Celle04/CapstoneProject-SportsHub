<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $fillable = [
        'sender_id',
        'sport_id',
        'medical_record_id',
        'event_id',
        'subject',
        'content',
        'message_type',
        'priority',
        'status',
        'recipient_type',
        'recipient_ids',
        'checkup_date',
        'checkup_time',
        'location',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'recipient_ids' => 'array',
            'checkup_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MessageRecipient::class);
    }

    public function isEventNotification(): bool
    {
        return in_array($this->message_type, ['sports_event', 'competition', 'event_update'], true) || $this->event_id !== null;
    }

    public static function categoryLabel(string $type): string
    {
        return match ($type) {
            'medical_reminder' => 'Medical Checkup Reminder',
            'sports_event' => 'Sports Event',
            'competition' => 'Competition',
            'event_update' => 'Event Update',
            default => 'General Message',
        };
    }
}
