<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesOutstandingCredit;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

class WaiveCreditRequest extends FormRequest
{
    use ValidatesOutstandingCredit;

    /**
     * The `role:admin` middleware on the route is the authorization gate, consistent with
     * the rest of kitchen-api.php.
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
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * Raised here so the response keeps a top-level `message`, matching the settle contract.
     */
    protected function prepareForValidation(): void
    {
        /** @var Student $student */
        $student = $this->route('student');

        abort_if($student->credit_balance <= 0, 422, 'No outstanding credit to waive.');

        if ($this->filled('reason')) {
            $this->merge(['reason' => strip_tags((string) $this->input('reason'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required when writing off credit.',
            'reason.min' => 'The reason must explain the write-off in at least 5 characters.',
        ];
    }
}
