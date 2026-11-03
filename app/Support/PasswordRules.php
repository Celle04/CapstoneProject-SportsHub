<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The password rule SportsHub applies everywhere a password is chosen.
 *
 * A single definition keeps the reset screen, the forced/unforced change
 * screens and the administrator's account-creation form from drifting apart.
 * `uncompromised()` is left out on purpose: it needs an outbound call to the
 * HaveIBeenPwned API, which may not be reachable from the school network.
 */
class PasswordRules
{
    /**
     * @return array<int, mixed>
     */
    public static function default(): array
    {
        return [
            'required',
            'string',
            'confirmed',
            Password::min(10)->mixedCase()->numbers(),
        ];
    }

    /**
     * The rule for the change password screens, where the new password must
     * also differ from the one being replaced.
     *
     * @return array<int, mixed>
     */
    public static function change(): array
    {
        return [
            'required',
            'string',
            'confirmed',
            'different:current_password',
            Password::min(10)->mixedCase()->numbers(),
        ];
    }
}
