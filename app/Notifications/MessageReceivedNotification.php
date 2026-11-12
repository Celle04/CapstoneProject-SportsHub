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
        $this->message->loadMissing('followUp');
        $this->message->loadMissing('replyTo');

        $description = trim(strip_tags((string) $this->message->content));
        $title = $this->message->subject ?: 'New SportsHub message';
        $threadRoot = $this->message->replyTo;

        if ($threadRoot) {
            $title = $notifiable->role === 'Administrator'
                ? 'New athlete reply'
                : 'Sports Office replied';
        }

        if ($this->message->isMedicalFollowUp()) {
            $followUp = $this->message->followUp;
            $schedule = $followUp?->scheduleLabel() ?? 'No schedule set';
            $description = trim($schedule.'. '.$description);
            $title = $this->message->subject ?: 'New medical follow-up';

            if ($followUp) {
                $title = match ($followUp->follow_up_type) {
                    'Medical Checkup Reminder' => 'Medical checkup reminder',
                    'Medical Clearance Follow-up' => 'Medical clearance follow-up',
                    'Medical Document Submission' => 'Medical document submission request',
                    'Medical Re-examination' => 'Upcoming medical examination',
                    default => 'New medical follow-up',
                };
            }
        } elseif ($this->message->message_type === 'medical_reminder') {
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

        $url = $notifiable->role === 'Administrator'
            ? route('admin.messages.show', $threadRoot ?? $this->message)
            : route('student.messages.show', $this->message);
        if ($event && $notifiable->role !== 'Administrator' && \Illuminate\Support\Facades\Route::has('student.events.show')) {
            $url = route('student.events.show', $event);
        }

        return new DatabaseMessage([
            'title' => $title,
            'message' => Str::limit($description, 160),
            'url' => $url,
            'message_id' => $this->message->id,
            'message_type' => $this->message->message_type,
            'category' => $this->message->category,
            'priority' => $this->message->priority,
            'event_id' => $event?->id,
        ]);
    }
}
