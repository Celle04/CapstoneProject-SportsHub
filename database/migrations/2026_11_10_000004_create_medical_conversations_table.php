<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_conversations');
    }
};
