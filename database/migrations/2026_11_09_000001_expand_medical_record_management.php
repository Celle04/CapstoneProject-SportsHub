<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->string('blood_type', 5)->nullable();
            $table->text('allergies')->nullable();
            $table->text('medical_conditions')->nullable();
            $table->text('previous_injuries')->nullable();
            $table->text('current_medications')->nullable();
            $table->text('medical_history')->nullable();
            $table->string('examining_personnel')->nullable();
            $table->text('medical_assessment')->nullable();
            $table->text('medical_remarks')->nullable();
            $table->date('clearance_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->text('clearance_remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('expiration_date');
        });

        Schema::create('medical_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained('medical_records')->cascadeOnDelete();
            $table->string('category', 60);
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['medical_record_id', 'category']);
        });

        Schema::create('medical_record_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->nullable()->constrained('medical_records')->nullOnDelete();
            $table->foreignId('athlete_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('previous_status')->nullable();
            $table->string('new_status')->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();
            $table->index(['medical_record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_histories');
        Schema::dropIfExists('medical_documents');

        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropIndex(['expiration_date']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn([
                'height_cm', 'weight_kg', 'blood_type', 'allergies', 'medical_conditions',
                'previous_injuries', 'current_medications', 'medical_history', 'examining_personnel',
                'medical_assessment', 'medical_remarks', 'clearance_date', 'expiration_date', 'clearance_remarks',
            ]);
        });
    }
};
