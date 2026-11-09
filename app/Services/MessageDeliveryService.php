<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use App\Notifications\MessageReceivedNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageDeliveryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(User $sender, array $data, ?Message $draft = null): Message
    {
        return DB::transaction(function () use ($sender, $data, $draft): Message {
            $message = $draft ?? new Message();
            $message->fill($this->attributes($data));
            $message->sender()->associate($sender);
            $message->status = 'draft';
            $message->sent_at = null;
            $message->recipient_ids = $this->draftRecipientIds($data);
            $message->save();

            return $message;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(User $sender, array $data, ?Message $draft = null): Message
    {
        return DB::transaction(function () use ($sender, $data, $draft): Message {
            $recipients = $this->resolveRecipients($data);
            if ($recipients->isEmpty()) {
                throw ValidationException::withMessages([
                    'recipients' => 'Select at least one active athlete before sending.',
                ]);
            }

            $message = $draft ?? new Message();
            $message->fill($this->attributes($data));
            $message->sender()->associate($sender);
            $message->status = 'sent';
            $message->sent_at = now();
            $message->recipient_ids = null;
            $message->save();

            foreach ($recipients as $recipient) {
                $message->recipients()->create(['recipient_id' => $recipient->id]);
                $recipient->notify(new MessageReceivedNotification($message));
            }

            return $message;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $messageType = $data['message_type'] ?? 'general';
        $isReminder = $messageType === 'medical_reminder';
        $recipientType = $data['recipient_type'] ?? 'individual';

        return [
            'sport_id' => in_array($recipientType, ['sport', 'team'], true) ? ($data['sport_id'] ?? null) : null,
            'medical_record_id' => $isReminder ? ($data['medical_record_id'] ?? null) : null,
            'event_id' => ! empty($data['event_id']) ? (int) $data['event_id'] : null,
            'subject' => $data['subject'] ?? null,
            'content' => $data['content'] ?? null,
            'message_type' => $messageType,
            'priority' => $data['priority'] ?? 'Normal',
            'recipient_type' => $recipientType,
            'checkup_date' => $isReminder ? ($data['checkup_date'] ?? null) : null,
            'checkup_time' => $isReminder ? ($data['checkup_time'] ?? null) : null,
            'location' => $isReminder ? ($data['location'] ?? null) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, int>|null
     */
    private function draftRecipientIds(array $data): ?array
    {
        if (($data['recipient_type'] ?? null) === 'individual' && ! empty($data['athlete_id'])) {
            return [(int) $data['athlete_id']];
        }

        if (($data['recipient_type'] ?? null) === 'selected') {
            return array_values(array_unique(array_map('intval', $data['recipient_ids'] ?? [])));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, User>
     */
    private function resolveRecipients(array $data): Collection
    {
        $query = User::query()->where('role', 'Student')->where('status', 'Active');
        $recipientType = $data['recipient_type'] ?? 'individual';

        return match ($recipientType) {
            'individual' => $query->whereKey((int) ($data['athlete_id'] ?? 0))->get(),
            'selected' => $query->whereKey(array_values(array_unique(array_map('intval', $data['recipient_ids'] ?? []))))->get(),
            'sport', 'team' => $query->where(function ($students) use ($data) {
                $sportId = (int) ($data['sport_id'] ?? 0);
                $students->where('sport_id', $sportId)
                    ->orWhereHas('applications', fn ($applications) => $applications
                        ->where('status', 'Approved')
                        ->where('sport_id', $sportId));
            })->get(),
            'all' => $query->get(),
            default => throw ValidationException::withMessages([
                'recipient_type' => 'Choose a valid recipient group.',
            ]),
        };
    }
}
