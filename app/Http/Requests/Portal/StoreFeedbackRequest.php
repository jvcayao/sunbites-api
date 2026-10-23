<?php

namespace App\Http\Requests\Portal;

use App\Enums\FeedbackCategory;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreFeedbackRequest extends FormRequest
{
    /** @var Collection<int, Student>|null */
    private ?Collection $linkedStudents = null;

    /**
     * Parent authentication is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The parent's linked students (id and branch only), loaded once for the guard, the rules
     * and the controller's branch lookup.
     *
     * @return Collection<int, Student>
     */
    public function linkedStudents(): Collection
    {
        return $this->linkedStudents ??= $this->user()->students()->get(['students.id', 'branch_id']);
    }

    /**
     * A parent with no linked students gets that 422 before any validation error (H4). Then
     * sanitize before validating so the length rules apply to what is actually stored.
     */
    protected function prepareForValidation(): void
    {
        if ($this->linkedStudents()->isEmpty()) {
            throw new HttpResponseException(response()->json(['message' => 'You have no linked students.'], 422));
        }

        $message = $this->input('message');

        $this->merge(['message' => is_string($message) ? trim(strip_tags($message)) : $message]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'student_id' => ['nullable', 'integer', Rule::in($this->linkedStudents()->pluck('id')->all())],
            'category' => ['required', Rule::enum(FeedbackCategory::class)],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
