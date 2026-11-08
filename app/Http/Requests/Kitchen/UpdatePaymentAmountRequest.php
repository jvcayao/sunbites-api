<?php

namespace App\Http\Requests\Kitchen;

use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentAmountRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * These checks ran before validation in the controller, so they still win over an invalid amount.
     */
    protected function prepareForValidation(): void
    {
        /** @var Student $student */
        $student = $this->route('student');
        /** @var StudentMonthlyPayment $payment */
        $payment = $this->route('payment');

        abort_if($payment->student_id !== $student->id, 403);
        abort_if($payment->isPaid() || $payment->isVoided(), 422, 'Can only edit amount on unpaid payments.');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
