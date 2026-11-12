<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The unified messaging structure.
     *
     * `messages` and `message_recipients` already carry the delivery and read
     * tracking for every message, so the medical follow-up tables only hold
     * what is specific to a follow-up: its schedule, its instructions and the
     * acknowledgment and completion state of each athlete it was sent to.
     *
     * The migration is written so it can be run again after a deployment
     * that stopped halfway through it: every step checks whether it still
     * has to happen.
     */
    public function up(): void
    {
        if (! Schema::hasTable('medical_follow_ups')) {
            Schema::create('medical_follow_ups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('message_id')->unique()->constrained('messages')->cascadeOnDelete();
                $table->string('follow_up_type', 64);
                $table->date('scheduled_date')->nullable();
                $table->time('scheduled_time')->nullable();
                $table->string('location')->nullable();
                $table->text('additional_instructions')->nullable();
                $table->boolean('response_required')->default(false);
                $table->string('status', 20)->default('Scheduled')->index();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'scheduled_date']);
            });
        }

        if (! Schema::hasTable('medical_follow_up_recipients')) {
            Schema::create('medical_follow_up_recipients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('medical_follow_up_id')->constrained('medical_follow_ups')->cascadeOnDelete();
                $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 20)->default('Scheduled')->index();
                $table->timestamps();

                // The name is given explicitly: the generated one is longer
                // than the 64 characters MySQL allows for an identifier.
                $table->unique(['medical_follow_up_id', 'recipient_id'], 'medical_follow_up_recipient_unique');
                $table->index(['recipient_id', 'status']);
            });
        }

        if (! $this->hasIndex('medical_follow_ups', 'medical_follow_ups_message_id_unique')) {
            Schema::table('medical_follow_ups', function (Blueprint $table) {
                $table->unique('message_id', 'medical_follow_ups_message_id_unique');
            });
        }

        if (! $this->hasIndex('medical_follow_up_recipients', 'medical_follow_up_recipient_unique')) {
            Schema::table('medical_follow_up_recipients', function (Blueprint $table) {
                $table->unique(['medical_follow_up_id', 'recipient_id'], 'medical_follow_up_recipient_unique');
            });
        }

        if (! Schema::hasColumn('messages', 'category')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->string('category', 32)->default('general')->after('message_type');
            });
        }

        if (! $this->hasIndex('messages', 'messages_category_index')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->index('category');
            });
        }

        if (! Schema::hasColumn('messages', 'reply_to_message_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->unsignedBigInteger('reply_to_message_id')->nullable()->after('category');
            });
        }

        if (! $this->hasIndex('messages', 'messages_reply_to_message_id_index')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->index('reply_to_message_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages', 'category')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropIndex('messages_category_index');
                $table->dropIndex('messages_reply_to_message_id_index');
                $table->dropColumn(['category', 'reply_to_message_id']);
            });
        }

        Schema::dropIfExists('medical_follow_up_recipients');
        Schema::dropIfExists('medical_follow_ups');
    }

    /**
     * Whether the table already carries the named index.
     */
    private function hasIndex(string $table, string $index): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};
