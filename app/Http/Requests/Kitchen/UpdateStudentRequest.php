<?php

namespace App\Http\Requests\Kitchen;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'student_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('students')
                    ->where(fn ($query) => $query->where('branch_id', $student->branch_id))
                    ->ignore($student->id)
                    ->whereNotNull('student_number'),
            ],
            'grade_level' => ['required', 'string', Rule::in(config('sunbites.grade_levels'))],
            'section' => ['nullable', 'string', 'max:100'],
            'birthday' => ['required', 'date', 'before:today'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'file', 'mimes:jpeg,png,webp', 'max:2048'],
        ];
    }
}
