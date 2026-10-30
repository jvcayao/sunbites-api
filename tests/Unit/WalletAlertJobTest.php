<?php

namespace Tests\Unit;

use App\Jobs\WalletAlertJob;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The wallet alert mail retries a transient mail failure (Spec 16 T-14.3, design §4.7).
 */
class WalletAlertJobTest extends TestCase
{
    public function test_it_is_tried_three_times_with_a_10_then_60_second_backoff(): void
    {
        $job = new ReflectionClass(WalletAlertJob::class);

        $this->assertSame(
            ['tries' => 3, 'backoff' => [10, 60]],
            [
                'tries' => $job->getAttributes(Tries::class)[0]->newInstance()->tries,
                'backoff' => $job->getAttributes(Backoff::class)[0]->newInstance()->backoff,
            ],
        );
    }
}
