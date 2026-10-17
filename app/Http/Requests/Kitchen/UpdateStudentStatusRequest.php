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
     * The status is read before it is validated, so `tryFrom()` lets a missing or unknown
     * value fall through to the `enrollment_status` rule (a 422) instead of throwing.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enrollment_status' => ['required', Rule::enum(EnrollmentStatus::class)],
            'reason' => [
                Rule::when(
                    EnrollmentStatus::tryFrom((string) $this->input('enrollment_status'))?->requiresReason() ?? false,
                    ['required', 'string', 'max:500'],
                    ['nullable'],
                ),
            ],
        ];
    }
}
