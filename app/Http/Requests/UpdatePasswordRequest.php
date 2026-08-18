<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /api/profile/password` — the owner changing their own password.
 *
 * THE CURRENT PASSWORD IS THE GATE, NOT THE SESSION. The route is already
 * behind `auth`, so there is always a signed-in user here — but a borrowed or
 * stolen phone is signed in too, and a session cookie proves possession of a
 * device rather than knowledge of a secret. `current_password` is the actual
 * check, and it is the one thing that makes this endpoint safe to expose at all.
 *
 * IT IS A CHANGE, NOT A RESET, and routes/web.php's stance is unchanged: there
 * is still no /register, no /forgot-password, no mail-borne token and no way in
 * from the login screen. AuthenticationTest still asserts every one of those is
 * unregistered. Nothing here can be reached without already knowing the password
 * being replaced, so this is a rotation the owner performs, not a recovery an
 * attacker triggers.
 *
 * ---------------------------------------------------------------------------
 * WHY THESE THREE FIELDS ARE snake_case WHEN THE REST OF THIS API IS NOT
 *
 * docs/API.md is camelCase throughout because its only reader is JavaScript,
 * and UpdateSettingsRequest translates at the boundary. This request does not,
 * and the reason is that two of the three names are NOT ours to choose:
 * `confirmed` looks for `{field}_confirmation` and nothing else, and the
 * `current_password` rule reads best when the field it guards carries the rule's
 * own name. Renaming them would mean either re-deriving Laravel's conventions in
 * `prepareForValidation` or hand-writing the confirmation check — a translation
 * layer bought for consistency on the one endpoint whose field names are also
 * what browsers' password managers key on.
 *
 * ---------------------------------------------------------------------------
 * THE STRENGTH FLOOR IS TWELVE CHARACTERS AND NOTHING ELSE
 *
 * No composition policy: one owner picking a password out of a phone keychain
 * does not need mixed case and a symbol argued at them, and rules of that shape
 * push people towards shorter passwords they can retype. Length is the property
 * that actually survives being guessed — and the login route is throttled to
 * five attempts a minute (AppServiceProvider), so an online attack gets nowhere
 * near a twelve-character secret.
 *
 * NO `uncompromised()` BREACH CHECK, unlike health-tracker's copy of this form.
 * That rule makes an outbound HTTPS request to Have I Been Pwned during
 * validation; it fails open, so it can never lock the owner out, but it also
 * turns this suite's tests into ones that talk to a third party and answer
 * differently when the network does. It is a fair addition the day something
 * else here already stubs outbound HTTP by default.
 */
final class UpdatePasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /*
             * `current_password:web` names the guard EXPLICITLY rather than
             * taking the default. The route runs behind `auth` (the session
             * guard), but Laravel's Authenticate middleware calls shouldUse()
             * with whichever guard let the request in, so "the default guard"
             * at validation time is a value set by middleware somewhere else.
             * The session is what authenticated this request and the session is
             * what the password is checked against; saying so removes the
             * question and survives the route being moved into an
             * `auth:sanctum` group later.
             */
            'current_password' => ['required', 'string', 'current_password:web'],

            /*
             * `different:current_password` is the rule that makes this a
             * CHANGE. Without it the form happily accepts the password it
             * already has, reports success, and leaves the owner believing they
             * have rotated a secret they have not.
             */
            'password' => ['required', 'string', 'confirmed', 'different:current_password', 'min:12'],
        ];
    }

    /**
     * Sentences, because that is what appears under the box.
     *
     * They are shown to the person VERBATIM — resources/js/Components/settings/
     * ChangePassword.vue renders whatever the 422 says, the same arrangement
     * every other form in this app has — so each one has to name the field it
     * belongs to and say what to do about it.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required'         => 'Enter your current password.',
            'current_password.current_password' => 'That is not your current password.',
            'password.required'                 => 'Choose a new password.',
            'password.min'                      => 'Use at least 12 characters.',
            'password.confirmed'                => 'The new password and its confirmation do not match.',
            'password.different'                => 'That is already your password. Choose a different one.',
        ];
    }
}
