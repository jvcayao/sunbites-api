<?php

namespace App\Policies;

use App\Models\ParentUser;
use App\Models\Student;

class ParentStudentPolicy
{
    public function view(ParentUser $parent, Student $student): bool
    {
        return $parent->students()->wherePivot('student_id', $student->id)->exists();
    }
}
