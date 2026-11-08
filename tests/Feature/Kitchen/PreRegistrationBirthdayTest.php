<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\PreRegistration;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A pre-registration birthday is a plain date, so the POS edit form can show it and saving
 * the form does not move it back one day (Spec 16 B-22). The app runs in Asia/Manila here,
 * as in production; `phpunit.xml` sets UTC, which hides the shift.
 */
class PreRegistrationBirthdayTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Manila']);
        date_default_timezone_set('Asia/Manila');
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');
        $this->supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
        parent::tearDown();
    }

    public function test_show_returns_the_birthday_as_a_plain_date(): void
    {
        $preRegistration = PreRegistration::factory()->pending()->create(['branch_id' => $this->branch->id, 'birthday' => '2016-02-14']);

        $response = $this->asSupervisor()->getJson("/api/v1/pre-registrations/{$preRegistration->id}");

        $response->assertOk()->assertJsonPath('data.birthday', '2016-02-14');
    }

    public function test_saving_the_birthday_that_show_returned_keeps_the_same_day(): void
    {
        $preRegistration = PreRegistration::factory()->pending()->create(['branch_id' => $this->branch->id, 'birthday' => '2016-02-14', 'first_name' => 'Juan']);
        $shownBirthday = $this->asSupervisor()->getJson("/api/v1/pre-registrations/{$preRegistration->id}")->json('data.birthday');

        $response = $this->asSupervisor()->patchJson("/api/v1/pre-registrations/{$preRegistration->id}", [
            'first_name' => 'Pedro',
            'birthday' => $shownBirthday,
        ]);

        $response->assertOk();
        $preRegistration->refresh();
        $this->assertSame('Pedro', $preRegistration->first_name);
        $this->assertSame('2016-02-14', $preRegistration->birthday->toDateString());
    }

    private function asSupervisor(): static
    {
        Sanctum::actingAs($this->supervisor, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }
}
