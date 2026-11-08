<?php

namespace Tests\Feature\Contract;

use Bavix\Wallet\Internal\Service\ClockServiceInterface;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\Contract\AssertsApiContract;
use Tests\Support\Contract\ContractScenario;
use Tests\TestCase;

/**
 * Base class for every contract test (Spec 16 design §4.1.2).
 *
 * Debug mode is off so fixtures record the production-shaped body: with `app.debug` on, every
 * `abort()` response would gain `exception`/`file`/`line`/`trace`, and the local
 * `.env.testing` turns it on while CI's `.env.example` does not.
 */
abstract class ContractTestCase extends TestCase
{
    use AssertsApiContract;
    use LazilyRefreshDatabase;

    protected ContractScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->bindWalletClockToTestTime();

        $this->setUpScenario();
    }

    /**
     * bavix/laravel-wallet stamps transactions with its own clock, which reads the real wall
     * clock and ignores `travelTo()`. Rebinding it before any wallet code runs makes wallet
     * timestamps equal the frozen scenario time. Contract tests only; production is unchanged.
     */
    private function bindWalletClockToTestTime(): void
    {
        $this->app->singleton(ClockServiceInterface::class, fn () => new class implements ClockServiceInterface
        {
            public function now(): DateTimeImmutable
            {
                return now()->toDateTimeImmutable();
            }
        });
    }

    /**
     * The route coverage and role matrix tests override this; they do not need the full scenario.
     */
    protected function setUpScenario(): void
    {
        $this->scenario = ContractScenario::build($this);
    }
}
