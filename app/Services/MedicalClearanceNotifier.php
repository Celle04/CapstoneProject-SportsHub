<?php

namespace App\Services;

use App\Models\MedicalRecord;
use App\Models\User;

class MedicalClearanceNotifier
{
    public function __construct(private readonly StudentUpdateNotifier $notifier)
    {
    }

    public function notifyFor(User $student): void
    {
        if ($student->role !== 'Student' || $student->status !== 'Active') {
            return;
        }

        $record = $student->currentMedicalRecord;
        if (! $record instanceof MedicalRecord) {
            return;
        }

        $status = $record->effectiveStatus();
        $expiryDate = $record->certificateExpiryDate();

        if ($status === 'Expired') {
            $title = 'Medical clearance expired';
            $message = $expiryDate
                ? 'Your medical clearance expired on '.$expiryDate->format('F j, Y').'. Please contact the Sports Office to arrange renewal.'
                : 'Your medical clearance needs renewal. Please contact the Sports Office.';
        } elseif ($status === 'Cleared' && $record->isExpiringSoon() && $expiryDate) {
            $title = 'Medical clearance expiring soon';
            $message = 'Your medical clearance expires on '.$expiryDate->format('F j, Y').'. Please contact the Sports Office to arrange renewal.';
        } else {
            return;
        }

        $dedupeKey = implode('-', [
            'medical-clearance',
            $record->id,
            strtolower(str_replace(' ', '-', $status)),
            $expiryDate?->toDateString() ?? 'no-date',
        ]);

        $this->notifier->notifyStudent(
            $student,
            $title,
            $message,
            route('student.messages'),
            ['dedupe_key' => $dedupeKey],
        );
    }
}
