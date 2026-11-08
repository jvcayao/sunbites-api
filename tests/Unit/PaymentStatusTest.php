<?php

namespace Tests\Unit;

use App\Enums\PaymentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Spec 16 T-12.3, design §4.5.1.
 */
class PaymentStatusTest extends TestCase
{
    public function test_each_status_has_its_stored_value_and_label(): void
    {
        $this->assertSame(
            ['paid' => 'Paid', 'unpaid' => 'Unpaid', 'voided' => 'Voided'],
            collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $status): array => [$status->value => $status->label()])->all(),
        );
    }
}
