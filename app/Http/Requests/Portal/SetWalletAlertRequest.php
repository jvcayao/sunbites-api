<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class SetWalletAlertRequest extends FormRequest
{
    /**
     * The controller's #[Authorize('view', 'student')] (the `can` middleware) runs before this request is validated.
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
            'threshold' => ['required', 'numeric', 'min:0', 'max:100000'],
        ];
    }
}
