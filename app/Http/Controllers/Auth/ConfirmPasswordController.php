<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Re-confirms an administrator's password before a high-impact action
 * (resetting an athlete password, deleting a medical record).
 *
 * This backs the `password.confirm` middleware: the middleware redirects to
 * the `password.confirm` route, which is this controller, and stores a
 * timestamp in the session once the password is confirmed.
 */
class ConfirmPasswordController extends Controller
{
    public function show(): View
    {
        abort_unless(request()->user()?->role === 'Administrator', 403);

        return view('auth.confirm-password');
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->role === 'Administrator', 403);

        $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'That password does not match our records.',
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', now()->unix());

        if ($request->expectsJson()) {
            return response()->json(['confirmed' => true]);
        }

        return redirect()->intended(route('dashboard'));
    }
}
