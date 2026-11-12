<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets the sports office archive a medical follow-up.
     *
     * An archived follow-up keeps its record but leaves the list, so a
     * follow-up that was dealt with does not compete for attention with the
     * ones that still need work. The column is added only when it is missing,
     * so a deployment that stops halfway through can simply be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('medical_follow_ups')) {
            return;
        }

        if (! Schema::hasColumn('medical_follow_ups', 'archived_at')) {
            Schema::table('medical_follow_ups', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable()->after('cancelled_at');
            });
        }

        if (! $this->hasIndex('medical_follow_ups', 'medical_follow_ups_archived_at_index')) {
            Schema::table('medical_follow_ups', function (Blueprint $table) {
                $table->index('archived_at', 'medical_follow_ups_archived_at_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('medical_follow_ups')) {
            return;
        }

        if ($this->hasIndex('medical_follow_ups', 'medical_follow_ups_archived_at_index')) {
            Schema::table('medical_follow_ups', function (Blueprint $table) {
                $table->dropIndex('medical_follow_ups_archived_at_index');
            });
        }

        if (Schema::hasColumn('medical_follow_ups', 'archived_at')) {
            Schema::table('medical_follow_ups', function (Blueprint $table) {
                $table->dropColumn('archived_at');
            });
        }
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
