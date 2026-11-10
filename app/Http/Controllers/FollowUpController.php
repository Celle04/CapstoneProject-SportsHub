<?php

namespace App\Http\Controllers;

use App\Models\MedicalFollowUp;
use App\Models\MedicalFollowUpRecipient;
use App\Models\Message;
use App\Models\User;
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
