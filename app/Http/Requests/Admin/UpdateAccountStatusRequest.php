<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountStatusRequest extends FormRequest
{
    /**
     * Only a super-admin switches accounts on or off, and never a super-admin
     * account. Checked here rather than in a policy because Gate::before grants
     * every ability to a super-admin.
     */
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $this->user()?->isSuperAdmin() === true
            && $target instanceof User
            && ! $target->isSuperAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
        ];
    }
}
