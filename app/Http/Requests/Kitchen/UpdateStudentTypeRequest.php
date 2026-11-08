<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\StudentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentTypeRequest extends FormRequest
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
        return [
            'student_type' => ['required', Rule::enum(StudentType::class)],
        ];
    }
}
