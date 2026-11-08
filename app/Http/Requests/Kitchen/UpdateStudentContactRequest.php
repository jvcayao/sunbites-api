<?php

namespace App\Http\Requests\Kitchen;

use App\Models\Student;
use App\Models\StudentContact;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentContactRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The ownership check ran before validation in the controller, so it still wins over invalid input.
     */
    protected function prepareForValidation(): void
    {
        /** @var Student $student */
        $student = $this->route('student');
        /** @var StudentContact $contact */
        $contact = $this->route('contact');

        if ($contact->student_id !== $student->id) {
            abort(404, 'Contact does not belong to this student.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:150'],
            'relationship' => ['sometimes', 'string', 'max:100'],
            'phone' => ['sometimes', 'string', 'max:30'],
            'address' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
