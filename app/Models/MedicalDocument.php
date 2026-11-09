<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MedicalDocument extends Model
{
    public const CATEGORIES = [
        'Medical Certificate',
        'Physical Examination Results',
        'Medical Clearance Document',
        'Other',
    ];

    protected $fillable = [
        'medical_record_id',
        'category',
        'original_name',
        'path',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function hasFile(): bool
    {
        return Storage::disk('private')->exists($this->path);
    }

    public function downloadName(): string
    {
        $extension = match ($this->mime_type) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };

        return 'medical-document-'.$this->id.'.'.$extension;
    }
}
