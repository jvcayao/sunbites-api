<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\PreRegistrationStatus;
use App\Enums\SchoolMonth;
use App\Models\PreRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdatePreRegistrationRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only a pending record can be edited. This ran before validation in the controller, so
     * it still wins over invalid input.
     */
    protected function prepareForValidation(): void
    {
        /** @var PreRegistration $preRegistration */
        $preRegistration = $this->route('preRegistration');

        if ($preRegistration->status !== PreRegistrationStatus::Pending) {
            throw new HttpResponseException(response()->json(['message' => 'Only pending pre-registrations can be edited.'], 422));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'student_number' => ['nullable', 'string', 'max:50'],
            'grade_level' => ['sometimes', 'string', Rule::in(config('sunbites.grade_levels'))],
            'section' => ['nullable', 'string', 'max:100'],
            'birthday' => ['sometimes', 'date', 'before:today'],
            'enrollment_type' => ['sometimes', 'in:subscription,non_subscription'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'subscription_start_month' => ['nullable', Rule::enum(SchoolMonth::class)],
            'subscription_start_year' => ['nullable', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'subscription_end_month' => ['nullable', Rule::enum(SchoolMonth::class)],
            'subscription_end_year' => ['nullable', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'signatory_name' => ['sometimes', 'string', 'max:255'],
            'contacts' => ['sometimes', 'array', 'min:1', 'max:3'],
            'contacts.*.id' => ['nullable', 'integer'],
            'contacts.*.full_name' => ['required_with:contacts', 'string', 'max:150'],
            'contacts.*.relationship' => ['required_with:contacts', 'string', 'max:100'],
            'contacts.*.phone' => ['required_with:contacts', 'string', 'max:30'],
            'contacts.*.address' => ['required_with:contacts', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:150'],
            'contacts.*.is_primary' => ['boolean'],
        ];
    }
}
