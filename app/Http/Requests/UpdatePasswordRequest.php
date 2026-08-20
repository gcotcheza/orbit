<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /api/profile/password`. DO NOT drop `current_password` — a session proves device
 * possession, not the secret. 12-char minimum, no breach check (docs/BUSINESS-LOGIC.md §36).
 */
final class UpdatePasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // `current_password:web` names the guard explicitly — the default at validation time
            // is whatever middleware last set.
            'current_password' => ['required', 'string', 'current_password:web'],

            // `different:current_password` is what makes this a CHANGE: without it, resubmitting
            // the same password reports success and rotates nothing.
            'password' => ['required', 'string', 'confirmed', 'different:current_password', 'min:12'],
        ];
    }

    /**
     * Sentences, because that is what appears under the box. Shown verbatim, so each must
     * name its field and say what to do (docs/BUSINESS-LOGIC.md §36).
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
