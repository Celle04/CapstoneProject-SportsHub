<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
        });

        DB::table('applications')
            ->select('id', 'name')
            ->orderBy('id')
            ->chunkById(100, function ($applications): void {
                foreach ($applications as $application) {
                    $parts = $this->splitName((string) ($application->name ?? ''));
                    if ($parts['first_name'] === '' && $parts['last_name'] === '') {
                        continue;
                    }

                    DB::table('applications')
                        ->where('id', $application->id)
                        ->update($parts);
                }
            });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'middle_name', 'last_name']);
        });
    }

    /**
     * @return array{first_name: string, middle_name: ?string, last_name: string}
     */
    private function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return ['first_name' => '', 'middle_name' => null, 'last_name' => ''];
        }

        $tokens = explode(' ', $name);
        if (count($tokens) === 1) {
            return ['first_name' => $tokens[0], 'middle_name' => null, 'last_name' => $tokens[0]];
        }

        $firstName = array_shift($tokens);
        $lastName = array_pop($tokens);
        $middleName = $tokens !== [] ? implode(' ', $tokens) : null;

        return [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => (string) $lastName,
        ];
    }
};
