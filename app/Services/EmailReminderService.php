<?php

namespace App\Services;

use App\Jobs\SendEmailReminderRecipientJob;
use App\Mail\EmailReminderMail;
use App\Models\EmailReminder;
use App\Models\EmailReminderRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class EmailReminderService
{
    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, User>
     */
    public function resolveRecipients(array $data): Collection
    {
        $type = (string) ($data['recipient_type'] ?? 'all');
        $query = User::query()
            ->where('role', 'Student')
            ->whereNotNull('email')
            ->where('email', '!=', '');

        $users = match ($type) {
            'all' => $query->orderBy('name')->get(),
            'individual' => $query->whereKey((int) ($data['athlete_id'] ?? 0))->get(),
            'selected' => $query->whereKey(array_values(array_unique(array_map('intval', $data['recipient_ids'] ?? []))))->get(),
            'sport', 'team' => $this->sportRecipients($query, (int) ($data['sport_id'] ?? 0)),
            default => throw ValidationException::withMessages([
                'recipient_type' => 'Choose a valid recipient group.',
            ]),
        };

        return $users
            ->filter(fn (User $user) => filter_var((string) $user->email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique('id')
            ->values();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     * @return Collection<int, User>
     */
    private function sportRecipients($query, int $sportId): Collection
    {
        if ($sportId < 1) {
            return new Collection();
        }

        return $query->where(function ($students) use ($sportId) {
            $students->where('sport_id', $sportId)
                ->orWhereHas('applications', fn ($applications) => $applications
                    ->where('status', 'Approved')
                    ->where('sport_id', $sportId));
        })->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function queueReminder(User $admin, array $data): EmailReminder
    {
        $recipients = $this->resolveRecipients($data);
        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages([
                'recipients' => 'No athletes with valid email addresses matched the selected recipients.',
            ]);
        }

        return DB::transaction(function () use ($admin, $data, $recipients): EmailReminder {
            $reminder = EmailReminder::create([
                'admin_id' => $admin->id,
                'category' => $data['category'],
                'subject' => $data['subject'],
                'message' => $data['message'],
                'recipient_type' => $data['recipient_type'],
                'sport_id' => in_array($data['recipient_type'], ['sport', 'team'], true) ? ($data['sport_id'] ?? null) : null,
                'recipient_ids' => in_array($data['recipient_type'], ['individual', 'selected'], true)
                    ? array_values(array_unique(array_map('intval', $data['recipient_ids'] ?? ($data['athlete_id'] ? [(int) $data['athlete_id']] : []))))
                    : null,
                'relevant_date' => $data['relevant_date'] ?? null,
                'relevant_time' => $data['relevant_time'] ?? null,
                'status' => 'queued',
                'recipient_count' => $recipients->count(),
                'sent_count' => 0,
                'failed_count' => 0,
                'queued_at' => now(),
            ]);

            foreach ($recipients as $athlete) {
                $recipient = EmailReminderRecipient::create([
                    'email_reminder_id' => $reminder->id,
                    'athlete_id' => $athlete->id,
                    'email' => $athlete->email,
                    'status' => 'pending',
                ]);

                SendEmailReminderRecipientJob::dispatch($recipient->id);
            }

            return $reminder;
        });
    }

    public function retryFailed(EmailReminder $reminder): int
    {
        $failed = $reminder->recipients()->where('status', 'failed')->get();
        if ($failed->isEmpty()) {
            return 0;
        }

        $reminder->update([
            'status' => 'queued',
            'completed_at' => null,
            'failed_count' => max(0, $reminder->failed_count - $failed->count()),
            'queued_at' => now(),
        ]);

        foreach ($failed as $recipient) {
            $recipient->update(['status' => 'pending', 'error_message' => null, 'sent_at' => null]);
            SendEmailReminderRecipientJob::dispatch($recipient->id);
        }

        return $failed->count();
    }

    public function sendTest(User $admin, string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['test_email' => 'Enter a valid email address for the test message.']);
        }

        $reminder = new EmailReminder([
            'category' => 'General Reminder',
            'subject' => 'SportsHub Email Test',
            'message' => "This is a test email from {$admin->name}.\n\nIf you received this message, your SportsHub email configuration is working.",
            'relevant_date' => now()->toDateString(),
            'relevant_time' => now()->format('H:i'),
        ]);

        Mail::to($email)->send(new EmailReminderMail($reminder, $admin->name));
    }
}
