<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /api/profile/password` — the owner changing their own password.
 *
 * DO NOT drop `current_password` — a session proves device possession, not the
 * secret. Deliberately a change endpoint only (no reset/recovery path exists
 * anywhere in this app); 12-char minimum with no composition rules; no breach
 * check (keeps tests offline). Field names are snake_case: two of the three are
 * Laravel's own convention (`confirmed`, `current_password`), not ours to rename.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final class UpdatePasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // `current_password:web` names the guard explicitly — the default guard at validation time is whatever middleware last set, not necessarily the session guard.
            // Why: docs/BUSINESS-LOGIC.md §36.
            'current_password' => ['required', 'string', 'current_password:web'],

            // `different:current_password` is what makes this a CHANGE — without it, resubmitting the same password reports success but rotates nothing.
            // Why: docs/BUSINESS-LOGIC.md §36.
            'password' => ['required', 'string', 'confirmed', 'different:current_password', 'min:12'],
        ];
    }

    /**
     * Sentences, because that is what appears under the box.
     * Shown verbatim (ChangePassword.vue renders the 422 message as-is) — each must name its field and say what to do.
     * Why: docs/BUSINESS-LOGIC.md §36.
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
