<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The unified student ledger query, shared by the Kitchen and Portal StudentLedgerController.
 */
class StudentLedgerRequest extends FormRequest
{
    /**
     * Staff access is enforced by route middleware; the portal route checks the policy with
     * `#[Authorize]`, which runs before this request validates.
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
            'entry_type' => ['nullable', 'in:all,topup,purchase,credit'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
