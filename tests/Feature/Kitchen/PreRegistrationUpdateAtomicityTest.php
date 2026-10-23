<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\PreRegistration;
use App\Models\PreRegistrationContact;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Editing a pre-registration replaces its contacts in the same transaction as the record
 * (Spec 16 B-09).
 */
class PreRegistrationUpdateAtomicityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(PermissionSeeder::class);
    }

    public function test_a_failed_contact_write_leaves_the_record_and_its_old_contacts_untouched(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');
        $supervisor->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $preRegistration = PreRegistration::factory()->pending()->create(['branch_id' => $branch->id, 'first_name' => 'Juan']);
        $oldContact = PreRegistrationContact::factory()->primary()->create(['pre_registration_id' => $preRegistration->id]);
        PreRegistrationContact::creating(fn () => throw new RuntimeException('Contact insert failed.'));
        Sanctum::actingAs($supervisor, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $branch->id])->patchJson("/api/v1/pre-registrations/{$preRegistration->id}", [
            'first_name' => 'Pedro',
            'contacts' => [[
                'full_name' => 'Ana Reyes',
                'relationship' => 'Aunt',
                'phone' => '09170000000',
                'address' => '1 Rizal St',
                'is_primary' => true,
            ]],
        ]);

        $response->assertInternalServerError();
        $this->assertSame('Juan', $preRegistration->fresh()->first_name);
        $this->assertModelExists($oldContact);
        $this->assertSame(1, $preRegistration->contacts()->count());
    }
}
