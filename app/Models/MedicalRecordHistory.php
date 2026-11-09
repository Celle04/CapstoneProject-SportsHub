<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecordHistory extends Model
{
    protected $fillable = [
        'medical_record_id',
        'athlete_id',
        'changed_by',
        'action',
        'previous_status',
        'new_status',
        'changes',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
