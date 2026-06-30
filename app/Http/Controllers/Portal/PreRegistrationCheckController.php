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

        $branchId = $validated['branch_id'];
        $firstName = $validated['first_name'];
        $lastName = $validated['last_name'];
        $birthday = $validated['birthday'];

        $studentStatus = null;
        $isStudentDuplicate = false;

        if ($this->duplicateService->isEnrolledStudent($branchId, $firstName, $lastName, $birthday)) {
            $isStudentDuplicate = true;
            $studentStatus = 'enrolled';
        } elseif ($this->duplicateService->hasPendingPreRegistration($branchId, $firstName, $lastName, $birthday)) {
            $isStudentDuplicate = true;
            $studentStatus = 'pending';
        }

        $emailExists = isset($validated['email'])
            && $this->duplicateService->parentEmailExists($validated['email']);

        $phoneExists = ! isset($validated['email'])
            && isset($validated['phone'])
            && $this->duplicateService->parentPhoneExists($validated['phone']);

        return response()->json([
            'student' => [
                'is_duplicate' => $isStudentDuplicate,
                'status' => $studentStatus,
            ],
            'parent' => [
                'email_exists' => $emailExists,
                'phone_exists' => $phoneExists,
            ],
        ]);
    }
}
