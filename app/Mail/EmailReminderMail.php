<?php

namespace App\Mail;

use App\Models\EmailReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EmailReminder $reminder, public string $athleteName)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->reminder->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.email-reminder',
            text: 'mail.email-reminder-text',
        );
    }
}
