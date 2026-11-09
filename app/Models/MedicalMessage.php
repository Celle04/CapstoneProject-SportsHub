<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MedicalMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'message',
        'attachment_path',
        'attachment_name',
        'attachment_type',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MedicalConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function hasAttachment(): bool
    {
        return filled($this->attachment_path)
            && Storage::disk('private')->exists($this->attachment_path);
    }

    public function attachmentDownloadName(): string
    {
        $name = $this->attachment_name ?: basename((string) $this->attachment_path);

        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'medical-attachment';
    }
}
