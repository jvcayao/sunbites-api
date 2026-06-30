<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\StudentDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreRegistrationCheckController extends Controller
{
    public function __construct(
        private readonly StudentDuplicateService $duplicateService,
    ) {}

    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birthday' => ['required', 'date_format:Y-m-d', 'before:today'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $studentStatus = null;

        if ($this->duplicateService->isEnrolledStudent($validated['branch_id'], $validated['first_name'], $validated['last_name'], $validated['birthday'])) {
            $studentStatus = 'enrolled';
        } elseif ($this->duplicateService->hasPendingPreRegistration($validated['branch_id'], $validated['first_name'], $validated['last_name'], $validated['birthday'])) {
            $studentStatus = 'pending';
        }

        $emailExists = isset($validated['email'])
            && $this->duplicateService->parentEmailExists($validated['email']);

        $phoneExists = ! isset($validated['email'])
            && isset($validated['phone'])
            && $this->duplicateService->parentPhoneExists($validated['phone']);

        return response()->json([
            'student' => [
                'is_duplicate' => $studentStatus !== null,
                'status' => $studentStatus,
            ],
            'parent' => [
                'email_exists' => $emailExists,
                'phone_exists' => $phoneExists,
            ],
        ]);
    }
}
