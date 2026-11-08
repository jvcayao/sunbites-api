<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Student;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Policies\ParentStudentPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PolicyResolutionTest extends TestCase
{
    public function test_order_resolves_to_the_order_policy(): void
    {
        $this->assertInstanceOf(OrderPolicy::class, Gate::getPolicyFor(Order::class));
    }

    public function test_user_resolves_to_the_user_policy(): void
    {
        $this->assertInstanceOf(UserPolicy::class, Gate::getPolicyFor(User::class));
    }

    /**
     * Policy discovery would guess a class named after the model; students are authorized
     * for parents, so the attribute must decide.
     */
    public function test_student_resolves_to_the_parent_student_policy(): void
    {
        $this->assertInstanceOf(ParentStudentPolicy::class, Gate::getPolicyFor(Student::class));
    }
}
