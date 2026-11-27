<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileDeactivateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * A super-admin account can never be switched off, not even by its owner.
     */
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }
}
