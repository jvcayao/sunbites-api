<?php

namespace Tests\Feature\Kitchen;

use App\Models\PreRegistration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PreRegistrationApprovalDuplicateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pre_registration_has_duplicate_check_columns(): void
    {
        $preReg = PreRegistration::factory()->create([
            'duplicate_check_passed_at' => now(),
            'parent_email_exists' => true,
            'parent_phone_exists' => false,
        ]);

        $this->assertNotNull($preReg->duplicate_check_passed_at);
        $this->assertTrue($preReg->parent_email_exists);
        $this->assertFalse($preReg->parent_phone_exists);
    }
}
