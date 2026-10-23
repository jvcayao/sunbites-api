<?php

namespace App\Http\Requests\Kitchen;

use App\Models\Feedback;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
     * Another branch's feedback is not found, so 404 wins over a 422 for its body (H4).
     *
     * Sanitize before validating so the length rules apply to what is actually stored —
     * otherwise markup-only input such as "<br><br>" satisfies `min` and then persists as an
     * empty reply.
     *
     * @throws ModelNotFoundException
     */
    protected function prepareForValidation(): void
    {
        /** @var Feedback $feedback */
        $feedback = $this->route('feedback');

        if ($feedback->branch_id !== app('active_branch')->id) {
            throw (new ModelNotFoundException)->setModel(Feedback::class);
        }

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
