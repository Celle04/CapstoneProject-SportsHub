<?php

namespace App\Jobs;

use App\Mail\EmailReminderMail;
use App\Models\EmailReminder;
use App\Models\EmailReminderRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendEmailReminderRecipientJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    public int $timeout = 60;

    public function __construct(public int $recipientId)
    {
    }

    public function handle(): void
    {
        $recipient = EmailReminderRecipient::query()
            ->with(['reminder', 'athlete'])
            ->find($this->recipientId);

        if (! $recipient || ! $recipient->reminder) {
            return;
        }

        $reminder = $recipient->reminder;
        if ($reminder->status === 'draft') {
            return;
        }

        if ($recipient->status === 'sent') {
            return;
        }

        $athleteName = $recipient->athlete?->name ?: 'Athlete';
        $email = trim((string) $recipient->email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->markFailed($recipient, $reminder, 'Invalid or missing email address.');
            return;
        }

        $recipient->update(['status' => 'queued', 'error_message' => null]);

        Mail::to($email)->send(new EmailReminderMail($reminder, $athleteName));
        $this->markSent($recipient, $reminder);
    }

    public function failed(?Throwable $exception): void
    {
        $recipient = EmailReminderRecipient::find($this->recipientId);
        if (! $recipient || $recipient->status === 'sent') {
            return;
        }

        $recipient->update([
            'status' => 'failed',
            'error_message' => mb_substr($exception?->getMessage() ?: 'Email delivery failed.', 0, 1000),
        ]);

        $reminder = EmailReminder::find($recipient->email_reminder_id);
        if ($reminder) {
            $reminder->increment('failed_count');
            $this->refreshReminderStatus($reminder->fresh());
        }
    }

    private function markSent(EmailReminderRecipient $recipient, EmailReminder $reminder): void
    {
        DB::transaction(function () use ($recipient, $reminder): void {
            $recipient->update([
                'status' => 'sent',
                'sent_at' => now(),
                'error_message' => null,
            ]);
            $reminder->increment('sent_count');
        });

        $this->refreshReminderStatus($reminder->fresh());
    }

    private function markFailed(EmailReminderRecipient $recipient, EmailReminder $reminder, string $message): void
    {
        $recipient->update([
            'status' => 'failed',
            'error_message' => $message,
            'sent_at' => null,
        ]);
        $reminder->increment('failed_count');
        $this->refreshReminderStatus($reminder->fresh());
    }

    private function refreshReminderStatus(?EmailReminder $reminder): void
    {
        if (! $reminder || $reminder->recipient_count < 1) {
            return;
        }

        $processed = $reminder->sent_count + $reminder->failed_count;
        if ($processed < $reminder->recipient_count) {
            $reminder->update(['status' => 'sending']);
            return;
        }

        $status = 'sent';
        if ($reminder->sent_count === 0 && $reminder->failed_count > 0) {
            $status = 'failed';
        } elseif ($reminder->failed_count > 0) {
            $status = 'partial';
        }

        $reminder->update([
            'status' => $status,
            'completed_at' => now(),
        ]);
    }
}
