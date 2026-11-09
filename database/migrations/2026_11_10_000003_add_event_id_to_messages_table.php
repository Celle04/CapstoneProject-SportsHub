<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('medical_record_id')->constrained('events')->nullOnDelete();
            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['event_id']);
            $table->dropIndex(['event_id', 'status']);
            $table->dropColumn('event_id');
        });
    }
};
