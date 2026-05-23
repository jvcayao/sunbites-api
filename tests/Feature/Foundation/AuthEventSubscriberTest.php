<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuthEventSubscriberTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_login_event_is_logged(): void
    {
        $user = User::factory()->create();

        event(new Login('web', $user, false));

        $activity = Activity::where('log_name', 'auth')->where('description', 'auth.login')->first();

        $this->assertNotNull($activity);
        $this->assertEquals($user->id, $activity->causer_id);
        $this->assertEquals('web', $activity->properties['guard']);
    }

    public function test_logout_event_is_logged(): void
    {
        $user = User::factory()->create();

        event(new Logout('web', $user));

        $activity = Activity::where('log_name', 'auth')->where('description', 'auth.logout')->first();

        $this->assertNotNull($activity);
        $this->assertEquals($user->id, $activity->causer_id);
    }

    public function test_failed_login_event_is_logged(): void
    {
        $email = 'attacker@example.com';
        event(new Failed('web', null, ['email' => $email, 'password' => 'wrong']));

        $activity = Activity::where('log_name', 'auth')->where('description', 'auth.failed')->first();

        $this->assertNotNull($activity);
        $this->assertArrayNotHasKey('email', $activity->properties->toArray());
        $this->assertEquals(hash('sha256', $email), $activity->properties['email_hash']);
        $this->assertNull($activity->causer_id);
    }

    public function test_password_reset_event_is_logged(): void
    {
        $user = User::factory()->create();

        event(new PasswordReset($user));

        $activity = Activity::where('log_name', 'auth')->where('description', 'auth.password_reset')->first();

        $this->assertNotNull($activity);
        $this->assertEquals($user->id, $activity->causer_id);
    }
}
