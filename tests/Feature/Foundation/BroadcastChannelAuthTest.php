<?php

namespace Tests\Feature\Foundation;

use App\Models\ParentUser;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BroadcastChannelAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.secret' => 's',
            'broadcasting.connections.reverb.app_id' => 'a',
        ]);

        // phpunit.xml sets BROADCAST_CONNECTION=null, so routes/channels.php registered
        // its callbacks on the null broadcaster at boot. Re-require it now that the
        // default connection above is reverb, so the reverb broadcaster gets them too.
        require base_path('routes/channels.php');
    }

    public function test_staff_can_authenticate_on_their_own_private_channel(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('staff', ['staff'])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->post('/api/v1/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => "private-staff.{$user->id}",
            ]);

        $response->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_staff_cannot_authenticate_on_another_staff_members_private_channel(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('staff', ['staff'])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->post('/api/v1/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => "private-staff.{$otherUser->id}",
            ]);

        $response->assertForbidden();
    }

    public function test_parent_can_authenticate_on_their_own_private_channel(): void
    {
        $parent = ParentUser::factory()->create();
        $token = $parent->createToken('portal', ['parent'])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->post('/api/v1/portal/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => "private-parents.{$parent->id}",
            ]);

        $response->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_parent_cannot_authenticate_on_another_parents_private_channel(): void
    {
        $parent = ParentUser::factory()->create();
        $otherParent = ParentUser::factory()->create();
        $token = $parent->createToken('portal', ['parent'])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->post('/api/v1/portal/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => "private-parents.{$otherParent->id}",
            ]);

        $response->assertForbidden();
    }
}
