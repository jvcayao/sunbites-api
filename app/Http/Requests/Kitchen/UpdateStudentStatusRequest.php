<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\EnrollmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentStatusRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `EnrollmentStatus::from()` runs before validation, so a missing or unknown status still
     * fails with a 500, exactly as before the move. B-14 fixes it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enrollment_status' => ['required', Rule::enum(EnrollmentStatus::class)],
            'reason' => [
                Rule::when(
                    EnrollmentStatus::from($this->input('enrollment_status'))->requiresReason(),
                    ['required', 'string', 'max:500'],
                    ['nullable'],
                ),
            ],
        ];
    }
}
