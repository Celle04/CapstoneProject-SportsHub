<?php

namespace App\Services;

use App\Models\MedicalFollowUp;
use App\Models\MedicalFollowUpRecipient;
use App\Models\Message;
use App\Models\User;
use App\Notifications\MessageReceivedNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delivers a message to its recipients.
 *
 * A message and its delivery records are always written together, and a
 * medical follow-up is created in the same transaction, so a follow-up can
 * never exist without the message that carries it.
 */
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

            $this->syncFollowUp($message, $data);

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

            // The follow-up is written before the athletes are notified, so the
            // notification already carries the schedule of the follow-up.
            $this->syncFollowUp($message, $data, $recipients);

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
        $category = $data['category'] ?? Message::CATEGORY_GENERAL;
        $isFollowUp = $category === Message::CATEGORY_MEDICAL_FOLLOW_UP;
        $messageType = $data['message_type'] ?? 'general';
        $recipientType = $data['recipient_type'] ?? 'individual';

        if ($isFollowUp) {
            $messageType = 'medical_follow_up';
        }

        return [
            'sport_id' => in_array($recipientType, ['sport', 'team'], true) ? ($data['sport_id'] ?? null) : null,
            'medical_record_id' => $isFollowUp ? ($data['medical_record_id'] ?? null) : null,
            'event_id' => ! empty($data['event_id']) ? (int) $data['event_id'] : null,
            'subject' => $data['subject'] ?? null,
            'content' => $data['content'] ?? null,
            'message_type' => $messageType,
            'category' => $category,
            'priority' => $data['priority'] ?? 'Normal',
            'recipient_type' => $recipientType,
            'checkup_date' => $isFollowUp ? ($data['scheduled_date'] ?? null) : null,
            'checkup_time' => $isFollowUp ? ($data['scheduled_time'] ?? null) : null,
            'location' => $isFollowUp ? ($data['location'] ?? null) : null,
        ];
    }

    /**
     * Creates, updates or removes the medical follow-up of a message.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, User>|null  $recipients
     */
    private function syncFollowUp(Message $message, array $data, ?Collection $recipients = null): void
    {
        $isFollowUp = ($data['category'] ?? Message::CATEGORY_GENERAL) === Message::CATEGORY_MEDICAL_FOLLOW_UP;

        if (! $isFollowUp) {
            $message->followUp?->delete();

            return;
        }

        $followUp = $message->followUp ?? new MedicalFollowUp(['message_id' => $message->id]);

        $followUp->message_id = $message->id;
        $followUp->follow_up_type = $data['follow_up_type'] ?? MedicalFollowUp::TYPES[0];
        $followUp->scheduled_date = $data['scheduled_date'] ?? null;
        $followUp->scheduled_time = $data['scheduled_time'] ?? null;
        $followUp->location = $data['location'] ?? null;
        $followUp->additional_instructions = $data['additional_instructions'] ?? null;
        $followUp->response_required = (bool) ($data['response_required'] ?? false);
        $followUp->status = $message->status === 'sent' ? MedicalFollowUp::STATUS_SENT : MedicalFollowUp::STATUS_SCHEDULED;
        $followUp->save();

        // The relation was read while it did not exist yet, so it is dropped
        // to make sure the message loads the follow-up that was just written.
        $message->unsetRelation('followUp');

        $followUp->recipients()->delete();

        if ($recipients === null) {
            return;
        }

        foreach ($recipients as $recipient) {
            MedicalFollowUpRecipient::create([
                'medical_follow_up_id' => $followUp->id,
                'recipient_id' => $recipient->id,
                'status' => MedicalFollowUp::STATUS_SENT,
            ]);
        }
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
