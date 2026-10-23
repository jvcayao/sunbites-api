<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class ReplyFeedbackRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sanitize before validating so the length rules apply to what is actually stored —
     * otherwise markup-only input such as "<br><br>" satisfies `min` and then persists as an
     * empty reply.
     */
    protected function prepareForValidation(): void
    {
        $reply = $this->input('reply');

        $this->merge(['reply' => is_string($reply) ? trim(strip_tags($reply)) : $reply]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reply' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
