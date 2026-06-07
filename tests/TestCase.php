<?php

namespace Tests;

use App\Enums\StudentType;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Returns the subscription period fields for enrollment API payloads.
     * Defaults to June of the current school year through March of the next.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function subscriptionFields(array $overrides = []): array
    {
        $currentYear = (int) now()->format('Y');
        $endYear = now()->month >= 6 ? $currentYear + 1 : $currentYear;

        return array_merge([
            'student_type' => StudentType::Subscription->value,
            'subscription_start_month' => 'june',
            'subscription_start_year' => $endYear - 1,
            'subscription_end_month' => 'march',
            'subscription_end_year' => $endYear,
        ], $overrides);
    }
}
