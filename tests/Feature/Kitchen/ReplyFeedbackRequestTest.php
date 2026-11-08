<?php

namespace Tests\Feature\Kitchen;

use App\Enums\FeedbackCategory;
use App\Models\Branch;
use App\Models\Feedback;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A reply is stripped of markup before its length is checked (Spec 16 T-9.6).
 */
class ReplyFeedbackRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(PermissionSeeder::class);
    }

    public function test_markup_only_reply_returns_422_on_reply_and_saves_nothing(): void
    {
        Mail::fake();
        $branch = Branch::factory()->create(['is_active' => true]);
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');
        $supervisor->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $feedback = Feedback::create([
            'parent_id' => ParentUser::factory()->create()->id,
            'student_id' => Student::factory()->create(['branch_id' => $branch->id])->id,
            'branch_id' => $branch->id,
            'rating' => 4,
            'category' => FeedbackCategory::FoodQuality,
            'message' => 'The adobo was great.',
            'is_read' => false,
        ]);
        Sanctum::actingAs($supervisor, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $branch->id])
            ->postJson("/api/v1/references/feedback/{$feedback->id}/reply", ['reply' => '<br><br>']);

        $response->assertUnprocessable()->assertExactJson([
            'message' => 'Validation failed.',
            'errors' => ['reply' => ['The reply field is required.']],
        ]);
        $this->assertNull($feedback->fresh()->admin_reply);
        Mail::assertNothingQueued();
    }
}
