<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class ListBranchMonthlyAmountsRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `year` was never validated, so the rule accepts any value (hazard H8); the controller
     * still reads it with `$request->integer('year')`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['nullable'],
        ];
    }
}
