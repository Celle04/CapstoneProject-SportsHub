<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('reference_number', 40)->nullable()->unique()->after('id');
            $table->text('admin_instructions')->nullable()->after('review_notes');
        });

        Schema::create('application_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('status', 40);
            $table->text('notes')->nullable();
            $table->text('admin_instructions')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['application_id', 'created_at']);
        });

        DB::table('applications')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                $reference = 'SNNHS-APP-'.now()->format('Y').'-'.strtoupper(Str::random(6)).'-'.str_pad((string) $row->id, 5, '0', STR_PAD_LEFT);
                DB::table('applications')->where('id', $row->id)->update(['reference_number' => $reference]);
            }
        });

        DB::table('applications')->where('status', 'Documents Required')->update(['status' => 'Requires Revision']);
    }

    public function down(): void
    {
        Schema::dropIfExists('application_status_histories');

        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique(['reference_number']);
            $table->dropColumn(['reference_number', 'admin_instructions']);
        });
    }
};
