<?php

namespace App\Http\Requests;

use App\Enums\CreditSettlementMethod;
use App\Http\Requests\Concerns\ValidatesOutstandingCredit;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettleCreditRequest extends FormRequest
{
    use ValidatesOutstandingCredit;

    /**
     * Role authorization is enforced by route middleware, consistent with the rest of
     * kitchen-api.php. Settling is open to all authenticated staff.
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
        /** @var Student $student */
        $student = $this->route('student');

        return [
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
                $this->notExceedingOutstandingCredit($student),
            ],
            'payment_method' => ['required', Rule::enum(CreditSettlementMethod::class)],
            'reference_number' => [
                'nullable',
                'required_if:payment_method,gcash,bank_transfer',
                'string',
                'alpha_num',
                'max:50',
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The "nothing outstanding" rejection is raised here rather than as a field rule so the
     * response keeps its top-level `message`, which is the contract callers already rely on.
     * A field rule would bury it under `errors.amount` behind a generic "Validation failed."
     */
    protected function prepareForValidation(): void
    {
        /** @var Student $student */
        $student = $this->route('student');

        abort_if($student->credit_balance <= 0, 422, 'No outstanding credit to settle.');

        if ($this->filled('note')) {
            $this->merge(['note' => strip_tags((string) $this->input('note'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_number.required_if' => 'A reference number is required for GCash and bank transfer payments.',
        ];
    }

    public function settlementMethod(): CreditSettlementMethod
    {
        return CreditSettlementMethod::from($this->validated('payment_method'));
    }
}
