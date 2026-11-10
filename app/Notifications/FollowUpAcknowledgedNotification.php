<?php

namespace App\Notifications;

use App\Models\MedicalFollowUp;
use App\Models\User;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the sports office that an athlete acknowledged a medical follow-up.
 */
class FollowUpAcknowledgedNotification extends Notification
{
    public function __construct(
        private readonly MedicalFollowUp $followUp,
        private readonly User $athlete,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $followUp = $this->followUp->loadMissing('message');

        return new DatabaseMessage([
            'title' => 'Medical follow-up acknowledged',
            'message' => $this->athlete->name.' acknowledged "'.($followUp->message?->subject ?: 'your medical follow-up').'".',
            'url' => route('admin.messages.show', $followUp->message_id),
            'message_id' => $followUp->message_id,
            'category' => 'medical_follow_up',
        ]);
    }
}
