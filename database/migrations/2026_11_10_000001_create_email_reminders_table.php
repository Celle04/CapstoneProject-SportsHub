<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 64)->default('General Reminder');
            $table->string('subject');
            $table->longText('message');
            $table->string('recipient_type', 24)->default('all');
            $table->foreignId('sport_id')->nullable()->constrained()->nullOnDelete();
            $table->json('recipient_ids')->nullable();
            $table->date('relevant_date')->nullable();
            $table->time('relevant_time')->nullable();
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'status', 'created_at']);
            $table->index(['category', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_reminders');
    }
};
