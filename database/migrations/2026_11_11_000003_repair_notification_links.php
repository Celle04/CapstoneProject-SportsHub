<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs the notification links of the unified messaging module.
 *
 * The first unification migration pointed every legacy medical conversation
 * notice at the athlete page. A conversation notice that was addressed to the
 * sports office therefore opened a page the sports office may not enter, and
 * the bell returned a 403. This migration sends every notice to the page of
 * the account it is addressed to: the athlete inbox for an athlete, the
 * administrator module for the sports office.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('notifications')->orderBy('id')->get() as $notification) {
            $data = json_decode((string) $notification->data, true);

            if (! is_array($data) || empty($data['message_id']) || empty($data['url'])) {
                continue;
            }

            $messageId = (int) $data['message_id'];
            $role = DB::table('users')->where('id', $notification->notifiable_id)->value('role');
            $expected = $role === 'Administrator'
                ? $this->adminMessageUrl($messageId)
                : $this->studentMessageUrl($messageId);

            if ((string) $data['url'] === $expected) {
                continue;
            }

            $data['url'] = $expected;

            DB::table('notifications')->where('id', $notification->id)->update([
                'data' => json_encode($data),
            ]);
        }
    }

    public function down(): void
    {
        // The links cannot be restored to their old conversation addresses.
    }

    private function studentMessageUrl(int $messageId): string
    {
        try {
            return route('student.messages.show', $messageId);
        } catch (\Throwable) {
            return '/student/messages/'.$messageId;
        }
    }

    private function adminMessageUrl(int $messageId): string
    {
        try {
            return route('admin.messages.show', $messageId);
        } catch (\Throwable) {
            return '/admin/messages/'.$messageId;
        }
    }
};
