<?php

namespace App\Http\Requests\Kitchen;

use App\Models\SystemConfiguration;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemConfigurationRequest extends FormRequest
{
    private ?SystemConfiguration $configuration = null;

    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The setting named by the `{key}` route segment. The rules need it, so an unknown key is
     * a 404 before the value is validated.
     */
    public function configuration(): SystemConfiguration
    {
        return $this->configuration ??= SystemConfiguration::where('key', $this->route('key'))->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->configuration()->type) {
            'integer' => ['value' => ['required', 'integer', 'min:0']],
            'decimal' => ['value' => ['required', 'numeric', 'min:0']],
            default => ['value' => ['required', 'string', 'max:255']],
        };
    }
}
