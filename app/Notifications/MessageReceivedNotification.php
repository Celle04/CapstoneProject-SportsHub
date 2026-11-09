<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class MessageReceivedNotification extends Notification
{
    public function __construct(private readonly Message $message)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $description = trim(strip_tags((string) $this->message->content));
        if ($this->message->message_type === 'medical_reminder') {
            $schedule = 'Checkup: '.$this->message->checkup_date?->format('M j, Y');
            if ($this->message->checkup_time) {
                $schedule .= ' at '.\Illuminate\Support\Carbon::parse($this->message->checkup_time)->format('g:i A');
            }
            if ($this->message->location) {
                $schedule .= ' · '.$this->message->location;
            }
            $description = trim($schedule.'. '.$description);
        }

        return new DatabaseMessage([
            'title' => $this->message->subject ?: 'New SportsHub message',
            'message' => Str::limit($description, 140),
            'url' => route('student.messages.show', $this->message),
            'message_id' => $this->message->id,
            'message_type' => $this->message->message_type,
            'priority' => $this->message->priority,
        ]);
    }
}
