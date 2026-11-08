<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class SendRemindersRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_ids' => ['required', 'array', 'min:1'],
            'parent_ids.*' => ['required', 'integer'],
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
