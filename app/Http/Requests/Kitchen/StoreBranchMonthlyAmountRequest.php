<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\SchoolMonth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchMonthlyAmountRequest extends FormRequest
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
            'school_month' => ['required', Rule::enum(SchoolMonth::class)],
            'year' => ['required', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'days' => ['required', 'integer', 'min:0', 'max:31'],
            'amount' => ['nullable', 'numeric', 'min:0', Rule::prohibitedIf($this->integer('days') === 0)],
        ];
    }
}
