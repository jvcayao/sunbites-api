<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\SchoolMonth;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class AddPaymentRangeRequest extends FormRequest
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
            'subscription_start_month' => ['required', Rule::enum(SchoolMonth::class)],
            'subscription_start_year' => ['required', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'subscription_end_month' => ['required', Rule::enum(SchoolMonth::class)],
            'subscription_end_year' => ['required', 'integer', 'digits:4', 'min:2020', 'max:2099'],
        ];
    }

    /**
     * A range that ends before it starts is refused with an errors-only body (no `message`
     * key), as it was before the move.
     */
    protected function passedValidation(): void
    {
        $validated = $this->validated();

        $start = Carbon::createFromDate(
            $validated['subscription_start_year'],
            SchoolMonth::from($validated['subscription_start_month'])->toMonthNumber(),
            1
        );
        $end = Carbon::createFromDate(
            $validated['subscription_end_year'],
            SchoolMonth::from($validated['subscription_end_month'])->toMonthNumber(),
            1
        );

        if ($end->lt($start)) {
            throw new HttpResponseException(response()->json(['errors' => ['subscription_end_month' => ['End month must be after start month.']]], 422));
        }
    }
}
