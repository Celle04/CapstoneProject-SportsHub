<?php

namespace App\Http\Controllers;

use App\Models\MedicalFollowUp;
use App\Models\MedicalFollowUpRecipient;
use App\Models\Message;
use App\Models\User;
use App\Notifications\MessageReceivedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The actions the sports office takes on a medical follow-up.
 *
 * Only an administrator can close or cancel a follow-up: an athlete who read
 * or acknowledged it never completes it.
 */
class FollowUpController extends Controller
{
    /**
     * Marks a follow-up as completed after the sports office verified it.
     */
    public function complete(Request $request, Message $message): RedirectResponse
    {
        $this->authorizeAdmin();
        $followUp = $this->followUp($message);

        abort_if($followUp->status === MedicalFollowUp::STATUS_CANCELLED, 422, 'This follow-up was cancelled and can no longer be completed.');

        DB::transaction(function () use ($followUp, $request): void {
            $now = now();

            foreach ($followUp->recipients()->get() as $recipient) {
                if ($recipient->completed_at === null) {
                    $recipient->forceFill([
                        'completed_at' => $now,
                        'completed_by' => $request->user()->id,
                        'status' => MedicalFollowUp::STATUS_COMPLETED,
                    ])->save();
                }
            }

            $followUp->forceFill([
                'status' => MedicalFollowUp::STATUS_COMPLETED,
                'completed_at' => $now,
                'completed_by' => $request->user()->id,
            ])->save();
        });

        return back()->with('success', 'Follow-up marked as completed.');
    }

    /**
     * Marks the follow-up of a single athlete as completed.
     */
    public function completeRecipient(Request $request, Message $message, User $recipient): RedirectResponse
    {
        $this->authorizeAdmin();
        $followUp = $this->followUp($message);

        abort_if($followUp->status === MedicalFollowUp::STATUS_CANCELLED, 422, 'This follow-up was cancelled and can no longer be completed.');

        $row = $followUp->recipients()->where('recipient_id', $recipient->id)->firstOrFail();

        DB::transaction(function () use ($row, $followUp, $request): void {
            if ($row->completed_at === null) {
                $row->forceFill([
                    'completed_at' => now(),
                    'completed_by' => $request->user()->id,
                    'status' => MedicalFollowUp::STATUS_COMPLETED,
                ])->save();
            }

            $followUp->syncStatus();
        });

        return back()->with('success', $recipient->name.' was marked as completed.');
    }

    /**
     * Cancels a follow-up.
     */
    public function cancel(Request $request, Message $message): RedirectResponse
    {
        $this->authorizeAdmin();
        $followUp = $this->followUp($message);

        abort_if($followUp->status === MedicalFollowUp::STATUS_COMPLETED, 422, 'A completed follow-up can no longer be cancelled.');

        DB::transaction(function () use ($followUp): void {
            $followUp->forceFill([
                'status' => MedicalFollowUp::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            foreach ($followUp->recipients()->get() as $recipient) {
                if ($recipient->completed_at === null) {
                    $recipient->forceFill(['status' => MedicalFollowUp::STATUS_CANCELLED])->save();
                }
            }
        });

        return back()->with('success', 'Follow-up cancelled.');
    }

    /**
     * Archives a follow-up.
     *
     * The record and its history stay complete; the follow-up only leaves the
     * list of the ones that still need work.
     */
    public function archive(Request $request, Message $message): RedirectResponse
    {
        $this->authorizeAdmin();
        $followUp = $this->followUp($message);

        if ($followUp->isArchived()) {
            return back()->with('success', 'This follow-up is already archived.');
        }

        $followUp->forceFill(['archived_at' => now()])->save();

        return back()->with('success', 'Follow-up archived. It is no longer listed among the active follow-ups.');
    }

    /**
     * Brings an archived follow-up back into the list.
     */
    public function restore(Request $request, Message $message): RedirectResponse
    {
        $this->authorizeAdmin();
        $followUp = $this->followUp($message);

        if (! $followUp->isArchived()) {
            return back()->with('success', 'This follow-up is not archived.');
        }

        $followUp->forceFill(['archived_at' => null])->save();

        return back()->with('success', 'Follow-up restored to the active list.');
    }

    /**
     * Deletes a follow-up with the message it belongs to.
     *
     * The notifications that point at the message go with it, so no bell in
     * the portal can lead to a message that no longer exists.
     */
    public function destroy(Request $request, Message $message): RedirectResponse
    {
        $this->authorizeAdmin();
        $followUp = $this->followUp($message);

        DB::transaction(function () use ($followUp, $message): void {
            $messageId = (int) $message->id;

            // The notifications that point at the message go with it, so no
            // bell in the portal can lead to a message that no longer exists.
            DB::table('notifications')
                ->where('type', MessageReceivedNotification::class)
                ->get()
                ->each(function (object $notification) use ($messageId): void {
                    $data = json_decode($notification->data ?? '{}', true) ?: [];

                    if ((int) data_get($data, 'message_id') === $messageId) {
                        DB::table('notifications')->where('id', $notification->id)->delete();
                    }
                });

            $followUp->recipients()->delete();
            $followUp->delete();
            $message->delete();
        });

        return back()->with('success', 'Follow-up deleted.');
    }

    private function followUp(Message $message): MedicalFollowUp
    {
        $followUp = $message->followUp;

        abort_unless($followUp, 404);

        return $followUp;
    }

    private function authorizeAdmin(): void
    {
        abort_unless(Gate::allows('manage-medical-follow-ups'), 403);
    }
}
