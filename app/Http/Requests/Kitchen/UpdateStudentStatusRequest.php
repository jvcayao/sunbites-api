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
     * `string(..., strict: true)` reads the status before it is validated as an empty
     * string for a missing, unknown or non-scalar value, so `tryFrom()` always receives a
     * string and `requiresReason()` falls through to the `enrollment_status` rule (a 422)
     * instead of throwing.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enrollment_status' => ['required', Rule::enum(EnrollmentStatus::class)],
            'reason' => [
                Rule::when(
                    EnrollmentStatus::tryFrom($this->string('enrollment_status', strict: true)->value())?->requiresReason() ?? false,
                    ['required', 'string', 'max:500'],
                    ['nullable'],
                ),
            ],
        ];
    }
}
