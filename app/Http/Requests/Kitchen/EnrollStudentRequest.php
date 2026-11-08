<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\SchoolMonth;
use App\Enums\StudentType;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class EnrollStudentRequest extends FormRequest
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
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'student_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('students')
                    ->where(fn ($query) => $query->where('branch_id', $this->input('branch_id')))
                    ->whereNotNull('student_number'),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'grade_level' => ['required', 'string', Rule::in(config('sunbites.grade_levels'))],
            'section' => ['nullable', 'string', 'max:100'],
            'birthday' => ['required', 'date', 'before:today'],
            'student_type' => ['required', Rule::enum(StudentType::class)],
            'photo' => ['nullable', 'file', 'mimes:jpeg,png,webp', 'max:2048'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'contacts' => ['required', 'array', 'min:1', 'max:3'],
            'contacts.*.full_name' => ['required', 'string', 'max:150'],
            'contacts.*.relationship' => ['required', 'string', 'max:100'],
            'contacts.*.phone' => ['required', 'string', 'max:30'],
            'contacts.*.address' => ['required', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:150'],
            'signature' => ['required', 'string', 'max:255'],
            'permission_meals' => ['required', 'accepted'],
            'permission_dietary' => ['required', 'accepted'],
            'subscription_start_month' => ['required_if:student_type,subscription', Rule::enum(SchoolMonth::class)],
            'subscription_start_year' => ['required_if:student_type,subscription', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'subscription_end_month' => ['required_if:student_type,subscription', Rule::enum(SchoolMonth::class)],
            'subscription_end_year' => ['required_if:student_type,subscription', 'integer', 'digits:4', 'min:2020', 'max:2099'],
        ];
    }

    /**
     * A subscription that ends before it starts is refused with an errors-only body (no
     * `message` key), as it was before the move. `after()` would add a `message`.
     */
    protected function passedValidation(): void
    {
        $validated = $this->validated();

        if (StudentType::from($validated['student_type']) !== StudentType::Subscription) {
            return;
        }

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
