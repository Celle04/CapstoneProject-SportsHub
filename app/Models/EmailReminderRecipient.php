<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailReminderRecipient extends Model
{
    protected $fillable = [
        'email_reminder_id',
        'athlete_id',
        'email',
        'status',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(EmailReminder::class, 'email_reminder_id');
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'athlete_id');
    }
}
