<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    /**
     * The two categories the unified module works with. A general message
     * covers everyday communication, a medical follow-up covers a medical
     * request that is tracked until it is acknowledged and verified.
     */
    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_MEDICAL_FOLLOW_UP = 'medical_follow_up';

    public const CATEGORIES = [
        self::CATEGORY_GENERAL,
        self::CATEGORY_MEDICAL_FOLLOW_UP,
    ];

    protected $fillable = [
        'sender_id',
        'sport_id',
        'medical_record_id',
        'event_id',
        'subject',
        'content',
        'message_type',
        'category',
        'priority',
        'status',
        'recipient_type',
        'recipient_ids',
        'checkup_date',
        'checkup_time',
        'location',
        'reply_to_message_id',
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

    /**
     * The medical follow-up attached to this message, when it has one.
     */
    public function followUp(): HasOne
    {
        return $this->hasOne(MedicalFollowUp::class);
    }

    /**
     * The replies that were written back to this message.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_message_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function isEventNotification(): bool
    {
        return in_array($this->message_type, ['sports_event', 'competition', 'event_update'], true) || $this->event_id !== null;
    }

    public function isMedicalFollowUp(): bool
    {
        return $this->category === self::CATEGORY_MEDICAL_FOLLOW_UP;
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

    /**
     * The label of the category the message belongs to.
     */
    public static function categoryName(string $category): string
    {
        return match ($category) {
            self::CATEGORY_MEDICAL_FOLLOW_UP => 'Medical Follow-up',
            default => 'General Message',
        };
    }
}
