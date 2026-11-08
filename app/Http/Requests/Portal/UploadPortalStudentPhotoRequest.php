<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class UploadPortalStudentPhotoRequest extends FormRequest
{
    /**
     * The controller's #[Authorize('view', 'student')] (the `can` middleware) runs before this request is validated.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Parents may upload up to 5 MB; the kitchen's limit is 2 MB on purpose (H13).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ];
    }
}
