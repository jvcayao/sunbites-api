<?php

namespace Tests\Feature\Portal;

use App\Enums\FeedbackCategory;
use App\Mail\FeedbackReplyMail;
use App\Models\Branch;
use App\Models\Feedback;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ParentUser $parent;

    private Branch $branch;

    private Student $student;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'email' => 'parent@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->manager->id,
            'wallet_alert_threshold' => 0,
        ]);
    }

    private function asParent(): static
    {
        $token = $this->parent->createToken('portal-token', ['parent'])->plainTextToken;

        return $this->withToken($token);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    // --- Portal: submit feedback ---

    public function test_parent_can_submit_feedback_for_linked_student(): void
    {
        $response = $this->asParent()->postJson('/api/v1/portal/feedback', [
            'student_id' => $this->student->id,
            'category' => FeedbackCategory::FoodQuality->value,
            'rating' => 4,
            'message' => 'The food quality has been excellent this week.',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'category', 'rating', 'message', 'created_at']);

        $this->assertDatabaseHas('feedbacks', [
            'parent_id' => $this->parent->id,
            'student_id' => $this->student->id,
            'rating' => 4,
        ]);
    }

    public function test_parent_cannot_submit_feedback_for_unlinked_student(): void
    {
        $otherStudent = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->asParent()->postJson('/api/v1/portal/feedback', [
            'student_id' => $otherStudent->id,
            'category' => FeedbackCategory::Service->value,
            'rating' => 3,
            'message' => 'This should fail because student is not linked.',
        ])->assertUnprocessable();
    }

    public function test_feedback_message_is_sanitized(): void
    {
        $this->asParent()->postJson('/api/v1/portal/feedback', [
            'student_id' => $this->student->id,
            'category' => FeedbackCategory::General->value,
            'rating' => 5,
            'message' => 'Great service here! <b>Very good.</b>',
        ])->assertCreated();

        // strip_tags removes HTML tags but preserves text content
        $this->assertDatabaseHas('feedbacks', ['message' => 'Great service here! Very good.']);
    }

    public function test_feedback_requires_minimum_message_length(): void
    {
        $this->asParent()->postJson('/api/v1/portal/feedback', [
            'student_id' => $this->student->id,
            'category' => FeedbackCategory::General->value,
            'rating' => 3,
            'message' => 'Short',
        ])->assertUnprocessable();
    }

    public function test_parent_can_list_their_own_feedbacks(): void
    {
        Feedback::create([
            'parent_id' => $this->parent->id,
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'category' => FeedbackCategory::FoodQuality->value,
            'rating' => 5,
            'message' => 'Great food this week.',
            'is_read' => false,
        ]);

        $this->asParent()->getJson('/api/v1/portal/feedback')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_unauthenticated_cannot_submit_feedback(): void
    {
        $this->postJson('/api/v1/portal/feedback', [
            'student_id' => $this->student->id,
            'category' => FeedbackCategory::General->value,
            'rating' => 3,
            'message' => 'This should be unauthorized.',
        ])->assertUnauthorized();
    }

    // --- Kitchen: feedback management ---

    public function test_manager_can_list_feedbacks(): void
    {
        Feedback::create([
            'parent_id' => $this->parent->id,
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'category' => FeedbackCategory::Cleanliness->value,
            'rating' => 2,
            'message' => 'The canteen was not clean today.',
            'is_read' => false,
        ]);

        $this->asManager()->getJson('/api/v1/references/feedback')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_manager_can_reply_to_feedback_and_sends_mail(): void
    {
        Mail::fake();

        $feedback = Feedback::create([
            'parent_id' => $this->parent->id,
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'category' => FeedbackCategory::Service->value,
            'rating' => 3,
            'message' => 'The service was a little slow today.',
            'is_read' => false,
        ]);

        $this->asManager()
            ->postJson("/api/v1/references/feedback/{$feedback->id}/reply", [
                'reply' => 'Thank you for your feedback. We are working on improving our service speed.',
            ])->assertOk()
            ->assertJsonStructure(['id', 'admin_reply', 'replied_at']);

        $feedback->refresh();
        $this->assertNotNull($feedback->replied_at);
        $this->assertTrue($feedback->is_read);
        Mail::assertQueued(FeedbackReplyMail::class, fn ($mail) => $mail->hasTo('parent@example.com'));
    }

    public function test_reply_message_is_sanitized(): void
    {
        Mail::fake();

        $feedback = Feedback::create([
            'parent_id' => $this->parent->id,
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'category' => FeedbackCategory::General->value,
            'rating' => 4,
            'message' => 'Generally good experience here.',
            'is_read' => false,
        ]);

        $this->asManager()
            ->postJson("/api/v1/references/feedback/{$feedback->id}/reply", [
                'reply' => '<b>Thank you</b> for your feedback!',
            ])->assertOk();

        $this->assertDatabaseHas('feedbacks', ['admin_reply' => 'Thank you for your feedback!']);
    }

    public function test_manager_can_mark_feedback_as_read(): void
    {
        $feedback = Feedback::create([
            'parent_id' => $this->parent->id,
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'category' => FeedbackCategory::PortionSize->value,
            'rating' => 3,
            'message' => 'The portion sizes could be a bit larger.',
            'is_read' => false,
        ]);

        $this->asManager()
            ->patchJson("/api/v1/references/feedback/{$feedback->id}/mark-read")
            ->assertOk();

        $this->assertTrue($feedback->fresh()->is_read);
    }

    public function test_unauthenticated_cannot_access_kitchen_feedback(): void
    {
        $this->getJson('/api/v1/references/feedback')->assertUnauthorized();
    }
}
