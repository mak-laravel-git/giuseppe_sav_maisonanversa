<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSiteSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['required', 'string', 'max:32'],
            'email_hello' => ['required', 'email', 'max:255'],
            'email_press' => ['required', 'email', 'max:255'],
            'instagram_url' => ['required', 'url', 'max:255'],
            'return_address' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
