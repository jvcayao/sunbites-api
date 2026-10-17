<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\UpdateSystemConfigurationRequest;
use App\Models\SystemConfiguration;
use Illuminate\Http\JsonResponse;

class SystemConfigurationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(SystemConfiguration::orderBy('key')->get());
    }

    public function update(UpdateSystemConfigurationRequest $request, string $key): JsonResponse
    {
        $config = $request->configuration();

        $validated = $request->validated();
        $config->update(['value' => (string) $validated['value']]);

        return response()->json($config);
    }
}
