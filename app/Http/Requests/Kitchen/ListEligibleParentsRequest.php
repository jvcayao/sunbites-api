<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\SchoolMonth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEligibleParentsRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `page` was never validated, so its rule accepts any value (hazard H8); the controller
     * still reads it with `$request->integer('page', 1)`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $schoolMonthValues = collect(SchoolMonth::cases())->map->value->toArray();

        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:all,unsent,partial,sent,overdue'],
            'school_month' => ['nullable', 'string', Rule::in($schoolMonthValues)],
            'school_year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable'],
        ];
    }
}
