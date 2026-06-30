<?php

namespace App\Services;

use App\Enums\PreRegistrationStatus;
use App\Models\ParentUser;
use App\Models\PreRegistration;
use App\Models\Student;

class StudentDuplicateService
{
    public function isEnrolledStudent(int $branchId, string $firstName, string $lastName, string $birthday): bool
    {
        return Student::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [strtolower(trim($firstName))])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [strtolower(trim($lastName))])
            ->whereDate('birthday', $birthday)
            ->whereNull('deleted_at')
            ->exists();
    }

    public function hasPendingPreRegistration(int $branchId, string $firstName, string $lastName, string $birthday): bool
    {
        return PreRegistration::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [strtolower(trim($firstName))])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [strtolower(trim($lastName))])
            ->whereDate('birthday', $birthday)
            ->where('status', PreRegistrationStatus::Pending)
            ->exists();
    }

    public function parentEmailExists(string $email): bool
    {
        return ParentUser::where('email', $email)->exists();
    }

    public function parentPhoneExists(string $phone): bool
    {
        return ParentUser::where('phone', $phone)->exists();
    }
}
