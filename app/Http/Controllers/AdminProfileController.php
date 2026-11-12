<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesProfilePhotos;
use App\Support\PasswordRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminProfileController extends Controller
{
    use HandlesProfilePhotos;

    public function update(Request $request): RedirectResponse
    {
        $this->ensureAdministrator($request);

        $admin = $request->user();
        $validated = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($admin->id)],
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($admin->id)],
        ], $this->photoRules()), $this->photoMessages());

        $emailChanged = $validated['email'] !== $admin->email;
        $admin->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'username' => filled($validated['username'] ?? null) ? $validated['username'] : null,
        ]);

        if ($emailChanged) {
            $admin->email_verified_at = null;
        }

        $this->syncProfilePhoto($request, $admin);

        $message = $emailChanged
            ? 'Profile updated. The new email address is not marked as verified.'
            : 'Profile updated successfully.';

        return back()->with('success', $message);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $this->ensureAdministrator($request);

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => PasswordRules::change(),
        ], [
            'current_password.current_password' => 'The current password is incorrect.',
            'password.confirmed' => 'The new passwords do not match.',
            'password.different' => 'The new password must be different from your current password.',
        ]);

        $admin = $request->user();
        $admin->forceFill([
            'password' => $validated['password'],
        ])->setRememberToken(Str::random(60));
        $admin->save();
        $request->session()->regenerate();

        return back()->with('success', 'Your password has been changed successfully.');
    }

    private function ensureAdministrator(Request $request): void
    {
        abort_unless($request->user()?->role === 'Administrator', 403);
    }
}
