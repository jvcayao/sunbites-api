<?php

namespace App\Services;

use App\Mail\ParentWelcomeMail;
use App\Models\ParentUser;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class ParentProvisioningService
{
    public function provision(string $email, string $name, int $studentId, int $linkedBy): void
    {
        $parts = explode(' ', trim($name), 2);
        $firstName = $parts[0];
        $lastName = $parts[1] ?? '';

        $parent = ParentUser::firstOrCreate(
            ['email' => $email],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password' => null,
                'email_verified_at' => null,
            ]
        );

        $alreadyLinked = $parent->students()
            ->wherePivot('student_id', $studentId)
            ->exists();

        if (! $alreadyLinked) {
            $parent->students()->attach($studentId, [
                'linked_at' => now(),
                'linked_by' => $linkedBy,
                'wallet_alert_threshold' => 0,
            ]);
        }

        if ($parent->wasRecentlyCreated) {
            $token = Password::broker('parents')->createToken($parent);
            Mail::to($parent->email)->queue(new ParentWelcomeMail($parent, $token));
        }
    }

    public function detachStudent(string $email, int $studentId): void
    {
        $parent = ParentUser::where('email', $email)->first();

        $parent?->students()->detach($studentId);
    }
}
