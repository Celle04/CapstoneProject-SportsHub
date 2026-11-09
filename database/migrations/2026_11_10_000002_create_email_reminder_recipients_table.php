<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_reminder_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_reminder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('status', 24)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['email_reminder_id', 'athlete_id']);
            $table->index(['email_reminder_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_reminder_recipients');
    }
};
