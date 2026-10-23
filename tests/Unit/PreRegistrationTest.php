<?php

namespace Tests\Unit;

use App\Models\PreRegistration;
use PHPUnit\Framework\TestCase;

/**
 * Spec 16 T-12.1, design §4.5.3: `full_name` is an accessor only. It is not in `$appends`, so
 * the pre-registration `show` payload (`toArray()`) stays without it.
 */
class PreRegistrationTest extends TestCase
{
    public function test_full_name_joins_first_and_last_name(): void
    {
        $preRegistration = new PreRegistration(['first_name' => 'Ana', 'last_name' => 'Reyes']);

        $this->assertSame('Ana Reyes', $preRegistration->full_name);
    }

    public function test_to_array_has_no_full_name(): void
    {
        $preRegistration = new PreRegistration(['first_name' => 'Ana', 'last_name' => 'Reyes']);

        $this->assertArrayNotHasKey('full_name', $preRegistration->toArray());
    }
}
