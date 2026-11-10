<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Folds the separate medical conversation module into the unified
     * messaging structure.
     *
     * Nothing is thrown away: every conversation becomes a message in
     * `messages` with its delivery record in `message_recipients`, a follow-up
     * in `medical_follow_ups` and a per athlete row in
     * `medical_follow_up_recipients`. The replies of a conversation are kept
     * as messages that point back at the conversation through
     * `reply_to_message_id`.
     */
    public function up(): void
    {
        // The conversion only writes rows, so it runs inside a transaction:
        // a deployment that stops halfway leaves nothing behind and the
        // migration can simply be run again.
        DB::transaction(function (): void {
            DB::table('messages')->where('message_type', 'medical_reminder')->update(['category' => 'medical_follow_up']);

            $this->migrateMedicalReminders();
            $conversationMessages = $this->migrateConversations();
            $this->repointNotifications($conversationMessages);
        });

        Schema::dropIfExists('medical_messages');
        Schema::dropIfExists('medical_conversations');
    }

    public function down(): void
    {
        Schema::create('medical_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('subject');
            $table->string('category', 80);
            $table->string('status', 20)->default('Pending')->index();
            $table->timestamps();

            $table->index(['athlete_id', 'updated_at']);
            $table->index(['created_by', 'updated_at']);
        });

        Schema::create('medical_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('medical_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_type', 120)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['sender_id', 'read_at']);
        });
    }

    /**
     * A checkup reminder that was already sent becomes a follow-up that is
     * already delivered, so the monitoring tools show it next to the new ones.
     */
    private function migrateMedicalReminders(): void
    {
        foreach (DB::table('messages')->where('message_type', 'medical_reminder')->get() as $message) {
            $status = $message->status === 'sent' ? 'Sent' : 'Scheduled';

            $followUpId = DB::table('medical_follow_ups')->insertGetId([
                'message_id' => $message->id,
                'follow_up_type' => 'Medical Checkup Reminder',
                'scheduled_date' => $message->checkup_date,
                'scheduled_time' => $message->checkup_time,
                'location' => $message->location,
                'additional_instructions' => null,
                'response_required' => false,
                'status' => $status,
                'completed_by' => null,
                'completed_at' => null,
                'cancelled_at' => null,
                'created_at' => $message->created_at,
                'updated_at' => $message->updated_at,
            ]);

            foreach (DB::table('message_recipients')->where('message_id', $message->id)->get() as $recipient) {
                DB::table('medical_follow_up_recipients')->insert([
                    'medical_follow_up_id' => $followUpId,
                    'recipient_id' => $recipient->recipient_id,
                    'read_at' => $recipient->read_at,
                    'acknowledged_at' => null,
                    'completed_at' => null,
                    'completed_by' => null,
                    'status' => $recipient->is_read ? 'Read' : $status,
                    'created_at' => $recipient->created_at,
                    'updated_at' => $recipient->updated_at,
                ]);
            }
        }
    }

    /**
     * @return array<int, int> the legacy conversation id mapped to its new message id
     */
    private function migrateConversations(): array
    {
        if (! Schema::hasTable('medical_conversations')) {
            return [];
        }

        $mapping = [];
        $typeMap = [
            'Medical Check-up Follow-up' => 'Medical Checkup Reminder',
            'Medical Certificate Submission' => 'Medical Document Submission',
            'Medical Clearance' => 'Medical Clearance Follow-up',
            'Health Assessment Update' => 'Medical Re-examination',
            'Missing Medical Requirements' => 'Medical Document Submission',
        ];
        $statusMap = [
            'Pending' => 'Sent',
            'Responded' => 'Acknowledged',
            'Resolved' => 'Completed',
        ];

        foreach (DB::table('medical_conversations')->orderBy('id')->get() as $conversation) {
            $thread = DB::table('medical_messages')
                ->where('conversation_id', $conversation->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $first = $thread->first();
            $athleteReplied = $thread->contains(fn ($entry) => (int) $entry->sender_id === (int) $conversation->athlete_id);
            $readAt = $thread->first(fn ($entry) => $entry->read_at !== null)?->read_at;
            $wasRead = $athleteReplied || $readAt !== null;

            $rootMessageId = DB::table('messages')->insertGetId([
                'sender_id' => $conversation->created_by,
                'sport_id' => null,
                'medical_record_id' => null,
                'event_id' => null,
                'subject' => $conversation->subject,
                'content' => $first?->message ?? $conversation->subject,
                'message_type' => 'medical_follow_up',
                'category' => 'medical_follow_up',
                'priority' => 'Normal',
                'status' => 'sent',
                'recipient_type' => 'individual',
                'recipient_ids' => null,
                'checkup_date' => null,
                'checkup_time' => null,
                'location' => null,
                'reply_to_message_id' => null,
                'sent_at' => $conversation->created_at,
                'created_at' => $conversation->created_at,
                'updated_at' => $conversation->updated_at,
            ]);

            DB::table('message_recipients')->insert([
                'message_id' => $rootMessageId,
                'recipient_id' => $conversation->athlete_id,
                'is_read' => $wasRead,
                'read_at' => $wasRead ? ($readAt ?? $conversation->updated_at) : null,
                'created_at' => $conversation->created_at,
                'updated_at' => $conversation->updated_at,
            ]);

            $followUpStatus = $statusMap[$conversation->status] ?? 'Sent';
            $acknowledgedAt = in_array($conversation->status, ['Responded', 'Resolved'], true) ? $conversation->updated_at : null;
            $completedAt = $conversation->status === 'Resolved' ? $conversation->updated_at : null;

            $followUpId = DB::table('medical_follow_ups')->insertGetId([
                'message_id' => $rootMessageId,
                'follow_up_type' => $typeMap[$conversation->category] ?? 'Other',
                'scheduled_date' => null,
                'scheduled_time' => null,
                'location' => null,
                'additional_instructions' => null,
                'response_required' => false,
                'status' => $followUpStatus,
                'completed_by' => $completedAt ? $conversation->created_by : null,
                'completed_at' => $completedAt,
                'cancelled_at' => null,
                'created_at' => $conversation->created_at,
                'updated_at' => $conversation->updated_at,
            ]);

            DB::table('medical_follow_up_recipients')->insert([
                'medical_follow_up_id' => $followUpId,
                'recipient_id' => $conversation->athlete_id,
                'read_at' => $wasRead ? ($readAt ?? $conversation->updated_at) : null,
                'acknowledged_at' => $acknowledgedAt,
                'completed_at' => $completedAt,
                'completed_by' => $completedAt ? $conversation->created_by : null,
                'status' => $followUpStatus,
                'created_at' => $conversation->created_at,
                'updated_at' => $conversation->updated_at,
            ]);

            // The rest of the conversation is kept as replies to the message
            // that started it, so no reply is lost.
            foreach ($thread->skip(1) as $entry) {
                $replyId = DB::table('messages')->insertGetId([
                    'sender_id' => $entry->sender_id,
                    'sport_id' => null,
                    'medical_record_id' => null,
                    'event_id' => null,
                    'subject' => $conversation->subject,
                    'content' => $entry->message,
                    'message_type' => 'medical_follow_up',
                    'category' => 'medical_follow_up',
                    'priority' => 'Normal',
                    'status' => 'sent',
                    'recipient_type' => 'individual',
                    'recipient_ids' => null,
                    'checkup_date' => null,
                    'checkup_time' => null,
                    'location' => null,
                    'reply_to_message_id' => $rootMessageId,
                    'sent_at' => $entry->created_at,
                    'created_at' => $entry->created_at,
                    'updated_at' => $entry->updated_at,
                ]);

                $replyRecipientId = (int) $entry->sender_id === (int) $conversation->athlete_id
                    ? (int) $conversation->created_by
                    : (int) $conversation->athlete_id;

                DB::table('message_recipients')->insert([
                    'message_id' => $replyId,
                    'recipient_id' => $replyRecipientId,
                    'is_read' => (int) $replyRecipientId === (int) $conversation->athlete_id ? $entry->read_at !== null : true,
                    'read_at' => $entry->read_at,
                    'created_at' => $entry->created_at,
                    'updated_at' => $entry->updated_at,
                ]);
            }

            $mapping[(int) $conversation->id] = $rootMessageId;
        }

        return $mapping;
    }

    /**
     * Notifications that pointed at a conversation now point at the message
     * that replaced it, so no bell entry is left broken.
     *
     * @param  array<int, int>  $mapping
     */
    private function repointNotifications(array $mapping): void
    {
        if ($mapping === []) {
            return;
        }

        foreach (DB::table('notifications')->get() as $notification) {
            $data = json_decode((string) $notification->data, true);

            if (! is_array($data) || ! isset($data['medical_conversation_id'])) {
                continue;
            }

            $messageId = $mapping[(int) $data['medical_conversation_id']] ?? null;

            if ($messageId === null) {
                continue;
            }

            unset($data['medical_conversation_id']);
            $data['message_id'] = $messageId;
            $data['message_type'] = 'medical_follow_up';
            $data['url'] = $this->messageUrl($messageId);

            DB::table('notifications')->where('id', $notification->id)->update([
                'data' => json_encode($data),
            ]);
        }
    }

    /**
     * The address of the message that replaced a conversation.
     *
     * The route is built when the application can build it, and falls back to
     * a relative path, so the migration never fails because of a missing
     * application URL.
     */
    private function messageUrl(int $messageId): string
    {
        try {
            return route('student.messages.show', $messageId);
        } catch (\Throwable) {
            return '/student/messages/'.$messageId;
        }
    }
};
