<?php

namespace App\Http\Requests\Kitchen;

use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Foundation\Http\FormRequest;

class VoidPaymentRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * These checks ran before validation in the controller, so they still win over a missing
     * reason. The past-month check stays in the controller, after validation.
     */
    protected function prepareForValidation(): void
    {
        /** @var Student $student */
        $student = $this->route('student');
        /** @var StudentMonthlyPayment $payment */
        $payment = $this->route('payment');

        abort_if($payment->student_id !== $student->id, 404);
        abort_if(! $payment->isPaid(), 422, 'Only paid payments can be voided.');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
