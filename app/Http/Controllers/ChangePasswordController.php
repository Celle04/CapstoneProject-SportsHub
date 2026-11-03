<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FailedPasswordAttemptService;
use App\Support\PasswordRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Lets an athlete whose account was marked for a mandatory password change
 * (for example after an administrator-assisted reset) choose a new password.
 *
 * The athlete must supply their current (temporary) password before the new
 * one is accepted, and the new password must differ from it.
 */
class ChangePasswordController extends Controller
{
    public function show(): View
    {
        $this->ensureAthlete();

        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $this->ensureAthlete();

        $athlete = $request->user();

        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'current_password'],
            'password' => PasswordRules::change(),
        ], [
            'current_password.current_password' => 'The temporary password is incorrect.',
            'password.confirmed' => 'The new passwords do not match.',
            'password.different' => 'The new password must be different from the temporary password.',
        ]);

        if ($validator->fails()) {
            /*
             * A wrong password is warned about by email as well as on the
             * screen: the athlete and the sports coordinators are both
             * notified, so somebody working on the account knows about it
             * even when the athlete walks away from the screen.
             */
            if ($validator->errors()->has('current_password')) {
                app(FailedPasswordAttemptService::class)->reportChangePasswordFailure($athlete, $request);
            }

            return back()
                ->withErrors($validator)
                ->withInput($request->except(['current_password', 'password', 'password_confirmation']));
        }

        $validated = $validator->validated();

        $athlete->forceFill([
            'password' => $validated['password'],
        ])->setRememberToken(Str::random(60));
        $athlete->clearPasswordChangeRequirement();
        $athlete->save();

        $request->session()->regenerate();

        return redirect()->route('student.dashboard')
            ->with('success', 'Your password has been changed successfully. You can now use it to log in.');
    }

    private function ensureAthlete(): void
    {
        abort_unless(request()->user()?->role === 'Student', 403);
    }
}
