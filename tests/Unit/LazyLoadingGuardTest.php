<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Student;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Lazy loading throws in the test suite and is only logged in other non-production
 * environments such as `local`, so an untested lazy load never breaks a page (Spec 16
 * T-13, design §4.5.4, H14).
 */
class LazyLoadingGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * The violation callback is static on Model; reset it so a test that switched the
     * environment cannot turn later tests' violations into log lines.
     */
    protected function tearDown(): void
    {
        Model::handleLazyLoadingViolationUsing(null);

        parent::tearDown();
    }

    public function test_a_lazy_load_throws_in_the_test_suite(): void
    {
        $students = $this->twoStudents();

        $this->expectException(LazyLoadingViolationException::class);

        $students->first()->branch;
    }

    public function test_a_lazy_load_is_logged_not_thrown_in_local(): void
    {
        $this->assertLazyLoadIsLoggedIn('local');
    }

    public function test_a_lazy_load_is_logged_not_thrown_in_any_other_environment(): void
    {
        $this->assertLazyLoadIsLoggedIn('preview');
    }

    private function assertLazyLoadIsLoggedIn(string $environment): void
    {
        $this->app->detectEnvironment(fn (): string => $environment);
        $provider = new AppServiceProvider($this->app);
        (new ReflectionMethod($provider, 'configureDefaults'))->invoke($provider);
        Log::spy();
        $students = $this->twoStudents();

        $branch = $students->first()->branch;

        $this->assertSame($students->first()->branch_id, $branch->id);
        Log::shouldHaveReceived('warning')->once()->with('Lazy loading violation', ['model' => Student::class, 'relation' => 'branch']);
    }

    /**
     * Lazy loading is only prevented on models hydrated as part of a multi-model result.
     *
     * @return Collection<int, Student>
     */
    private function twoStudents(): Collection
    {
        Student::factory()->count(2)->create(['branch_id' => Branch::factory()->create()->id]);

        return Student::query()->orderBy('id')->get();
    }
}
