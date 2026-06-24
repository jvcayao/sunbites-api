<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class KioskLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qr_code' => ['required', 'string', 'starts_with:SB-'],
        ];
    }
}
