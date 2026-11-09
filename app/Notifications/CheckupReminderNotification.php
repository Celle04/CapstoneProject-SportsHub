<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class CheckupReminderNotification extends Notification
{
    public function __construct(public Carbon $checkupDate)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SportsHub Checkup Reminder')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('This is a reminder that your next sports medical checkup is scheduled for '.$this->checkupDate->format('F j, Y').'.')
            ->line('Please contact the school sports office if you have questions or need to confirm the appointment.')
            ->action('Sign in to SportsHub', route('login'))
            ->salutation('SNNHS Sports Activity Hub');
    }
}
