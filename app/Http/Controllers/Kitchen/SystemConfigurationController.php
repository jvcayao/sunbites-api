<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\SystemConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemConfigurationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(SystemConfiguration::orderBy('key')->get());
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $config = SystemConfiguration::where('key', $key)->firstOrFail();

        $rules = match ($config->type) {
            'integer' => ['value' => ['required', 'integer', 'min:0']],
            'decimal' => ['value' => ['required', 'numeric', 'min:0']],
            default => ['value' => ['required', 'string', 'max:255']],
        };

        $validated = $request->validate($rules);
        $config->update(['value' => (string) $validated['value']]);

        return response()->json($config);
    }
}
