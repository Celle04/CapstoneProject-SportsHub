<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('privacy_accepted')->default(false);
            $table->boolean('terms_accepted')->default(false);
            $table->timestamp('policy_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['privacy_accepted', 'terms_accepted', 'policy_accepted_at']);
        });
    }
};
