<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidWalletTopUpRequest extends FormRequest
{
    /**
     * The `role:admin|manager` middleware on the route is the authorization gate.
     * Self-void and time-window checks happen in the controller — see design.md's
     * "where enforcement logic lives" decision.
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('reason')) {
            $this->merge(['reason' => strip_tags((string) $this->input('reason'))]);
        }
    }
}
