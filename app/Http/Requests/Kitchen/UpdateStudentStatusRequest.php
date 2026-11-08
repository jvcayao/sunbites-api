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
     * `string(..., strict: true)` reads a missing or list value as an empty string, and
     * `tryFrom()` returns null for that or any unknown value, so the `enrollment_status`
     * rule can return a 422 instead of this method throwing.
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
