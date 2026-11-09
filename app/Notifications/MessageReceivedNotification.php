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
        $this->message->loadMissing('event');
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

        $event = $this->message->event;
        if ($event) {
            $details = $event->title;
            if ($event->starts_at) {
                $details .= ' on '.$event->starts_at->format('M j, Y g:i A');
            }
            if ($event->venue) {
                $details .= ' at '.$event->venue;
            }
            $description = trim($details.'. '.$description);
        }

        $url = route('student.messages.show', $this->message);
        if ($event && \Illuminate\Support\Facades\Route::has('student.events.show')) {
            $url = route('student.events.show', $event);
        }

        return new DatabaseMessage([
            'title' => $this->message->subject ?: ($event?->title ? 'Event update: '.$event->title : 'New SportsHub message'),
            'message' => Str::limit($description, 160),
            'url' => $url,
            'message_id' => $this->message->id,
            'message_type' => $this->message->message_type,
            'priority' => $this->message->priority,
            'event_id' => $event?->id,
        ]);
    }
}
