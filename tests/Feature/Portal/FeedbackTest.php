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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createFeedback(array $attributes = []): Feedback
    {
        return Feedback::create(array_merge([
            'parent_id' => $this->parent->id,
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'category' => FeedbackCategory::General->value,
            'rating' => 4,
            'message' => 'A generally positive experience at the canteen.',
            'is_read' => false,
        ], $attributes));
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

    public function test_submitted_message_rejects_markup_that_sanitizes_to_nothing(): void
    {
        $this->asParent()->postJson('/api/v1/portal/feedback', [
            'student_id' => $this->student->id,
            'category' => FeedbackCategory::General->value,
            'rating' => 3,
            'message' => '<br><br><br><br><br><br>',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        $this->assertDatabaseCount('feedbacks', 0);
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

    public function test_reply_rejects_markup_that_sanitizes_to_nothing(): void
    {
        Mail::fake();

        $feedback = $this->createFeedback();

        $this->asManager()
            ->postJson("/api/v1/references/feedback/{$feedback->id}/reply", [
                'reply' => '<br><br><br><br>',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reply');

        $this->assertNull($feedback->fresh()->admin_reply);
        Mail::assertNothingQueued();
    }

    public function test_reply_requires_the_reply_field(): void
    {
        $feedback = $this->createFeedback();

        $this->asManager()
            ->postJson("/api/v1/references/feedback/{$feedback->id}/reply", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reply');
    }

    public function test_reply_rejects_a_message_shorter_than_five_characters(): void
    {
        $feedback = $this->createFeedback();

        $this->asManager()
            ->postJson("/api/v1/references/feedback/{$feedback->id}/reply", [
                'reply' => 'Ok',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reply');
    }

    public function test_reply_succeeds_when_the_parent_has_been_soft_deleted(): void
    {
        Mail::fake();

        $feedback = $this->createFeedback();
        $this->parent->delete();

        $this->asManager()
            ->postJson("/api/v1/references/feedback/{$feedback->id}/reply", [
                'reply' => 'Thank you for the feedback, we have acted on it.',
            ])->assertOk();

        $feedback->refresh();
        $this->assertSame('Thank you for the feedback, we have acted on it.', $feedback->admin_reply);
        $this->assertNotNull($feedback->replied_at);
        Mail::assertNothingQueued();
    }

    public function test_listing_feedback_succeeds_when_the_parent_has_been_soft_deleted(): void
    {
        $this->createFeedback();
        $this->parent->delete();

        $this->asManager()->getJson('/api/v1/references/feedback')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.parent', null);
    }

    // --- Kitchen: feedback search ---

    public function test_manager_can_search_feedbacks_by_message(): void
    {
        $this->createFeedback(['message' => 'The adobo was delicious today.']);
        $this->createFeedback(['message' => 'The queue was very long.']);

        $response = $this->asManager()
            ->getJson('/api/v1/references/feedback?search=adobo')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertStringContainsString('adobo', $response->json('data.0.message'));
    }

    public function test_manager_can_search_feedbacks_by_student_number(): void
    {
        $other = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_number' => 'STU-99001',
        ]);

        $this->createFeedback(['message' => 'Feedback from the linked student.']);
        $this->createFeedback([
            'student_id' => $other->id,
            'message' => 'Feedback from the other student.',
        ]);

        $this->asManager()
            ->getJson('/api/v1/references/feedback?search=STU-99001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student.student_number', 'STU-99001');
    }

    public function test_feedback_search_is_case_insensitive(): void
    {
        $this->createFeedback(['message' => 'The Adobo was delicious today.']);

        $this->asManager()
            ->getJson('/api/v1/references/feedback?search=ADOBO')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_feedback_search_combines_with_the_unread_filter(): void
    {
        $this->createFeedback(['message' => 'The adobo was delicious.', 'is_read' => true]);
        $this->createFeedback(['message' => 'The adobo was cold.', 'is_read' => false]);

        $this->asManager()
            ->getJson('/api/v1/references/feedback?search=adobo&is_read=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_read', false);
    }

    public function test_feedback_search_does_not_leak_other_branches(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->create(['branch_id' => $otherBranch->id]);

        $this->createFeedback([
            'branch_id' => $otherBranch->id,
            'student_id' => $otherStudent->id,
            'message' => 'The adobo at the other branch was great.',
        ]);

        $this->asManager()
            ->getJson('/api/v1/references/feedback?search=adobo')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // --- Pagination meta contract (consumed by both frontends) ---

    public function test_pagination_meta_includes_the_from_and_to_row_indexes(): void
    {
        $this->createFeedback();
        $this->createFeedback();

        $this->asManager()->getJson('/api/v1/references/feedback')
            ->assertOk()
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.to', 2)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_pagination_meta_reports_null_from_and_to_on_an_empty_page(): void
    {
        $this->asManager()->getJson('/api/v1/references/feedback')
            ->assertOk()
            ->assertJsonPath('meta.from', null)
            ->assertJsonPath('meta.to', null)
            ->assertJsonPath('meta.total', 0);
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
