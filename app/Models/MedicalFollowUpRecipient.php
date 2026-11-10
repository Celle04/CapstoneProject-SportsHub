<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The follow-up state of one athlete.
 *
 * The delivery and the read flag stay on `message_recipients`; this record
 * only holds what a follow-up adds on top of that: the acknowledgment the
 * athlete gave, and the completion an administrator verified.
 */
class MedicalFollowUpRecipient extends Model
{
    protected $fillable = [
        'medical_follow_up_id',
        'recipient_id',
        'read_at',
        'acknowledged_at',
        'completed_at',
        'completed_by',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(MedicalFollowUp::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
