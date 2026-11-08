<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\PreRegistrationStatus;
use App\Models\PreRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RejectPreRegistrationRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only a pending record can be rejected. This ran before validation in the controller, so
     * it still wins over a missing reason.
     */
    protected function prepareForValidation(): void
    {
        /** @var PreRegistration $preRegistration */
        $preRegistration = $this->route('preRegistration');

        if ($preRegistration->status !== PreRegistrationStatus::Pending) {
            throw new HttpResponseException(response()->json(['message' => 'Only pending pre-registrations can be rejected.'], 422));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
