<?php

namespace Tests\Feature\Kitchen;

use App\Enums\StudentType;
use App\Mail\ParentWelcomeMail;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Enrolment keeps its contacts, provisioning, photo and activity behaviour (Spec 16 T-5.1,
 * design §4.3.6).
 */
class EnrollStudentActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    public function test_only_the_first_contact_is_primary(): void
    {
        Mail::fake();

        $response = $this->enroll(['contacts' => [$this->contact('Maria'), $this->contact('Jose')]]);

        $response->assertCreated();
        $student = Student::findOrFail($response->json('id'));
        $this->assertSame(
            ['Maria' => true, 'Jose' => false],
            $student->contacts()->orderBy('id')->get()->mapWithKeys(fn ($contact) => [$contact->full_name => $contact->is_primary])->all(),
        );
    }

    public function test_every_contact_with_an_email_gets_a_portal_account(): void
    {
        Mail::fake();

        $response = $this->enroll(['contacts' => [
            $this->contact('Maria', 'maria@example.com'),
            $this->contact('Jose', 'jose@example.com'),
            $this->contact('Lola'),
        ]]);

        $response->assertCreated();
        $studentId = $response->json('id');
        $this->assertSame(
            ['jose@example.com', 'maria@example.com'],
            ParentUser::whereHas('students', fn ($q) => $q->where('students.id', $studentId))->orderBy('email')->pluck('email')->all(),
        );
        Mail::assertQueued(ParentWelcomeMail::class, 2);
    }

    public function test_photo_is_stored_on_the_private_disk(): void
    {
        Mail::fake();
        Storage::fake('private');

        $response = $this->enroll(['photo' => UploadedFile::fake()->image('student.jpg')], multipart: true);

        $response->assertCreated();
        $photoPath = Student::findOrFail($response->json('id'))->photo_path;
        $this->assertStringStartsWith('photos/students/', $photoPath);
        Storage::disk('private')->assertExists($photoPath);
    }

    public function test_enrolment_logs_the_students_enrolled_activity(): void
    {
        Mail::fake();

        $response = $this->enroll();

        $response->assertCreated();
        $activity = Activity::where('log_name', 'students')->where('description', 'students.enrolled')->sole();
        $this->assertTrue($activity->causer->is($this->manager));
        $this->assertSame($response->json('id'), $activity->subject_id);
        $this->assertSame(
            ['branch' => $this->branch->id, 'student_type' => StudentType::NonSubscription->value],
            $activity->properties->sortKeys()->all(),
        );
    }

    public function test_end_month_before_start_month_returns_422_with_errors_only(): void
    {
        $response = $this->enroll($this->subscription(['subscription_start_year' => 2026, 'subscription_end_year' => 2025]));

        $response->assertUnprocessable()->assertExactJson([
            'errors' => ['subscription_end_month' => ['End month must be after start month.']],
        ]);
        $this->assertDatabaseCount('students', 0);
    }

    /**
     * Guards hazard H16: an immutable start date would never advance and the request would hang.
     */
    public function test_june_to_march_subscription_creates_ten_monthly_payments(): void
    {
        Mail::fake();

        $response = $this->enroll($this->subscription());

        $response->assertCreated();
        $this->assertDatabaseCount('student_monthly_payments', 10);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function enroll(array $overrides = [], bool $multipart = false): TestResponse
    {
        Sanctum::actingAs($this->manager, ['staff']);
        $payload = [
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'grade_level' => 'Grade 3',
            'birthday' => '2015-01-15',
            'student_type' => StudentType::NonSubscription->value,
            'contacts' => [$this->contact('Maria', 'maria@example.com')],
            'signature' => 'Maria Dela Cruz',
            'permission_meals' => true,
            'permission_dietary' => true,
            ...$overrides,
        ];
        $request = $this->withHeaders(['X-Branch-Id' => $this->branch->id, 'Accept' => 'application/json']);

        return $multipart ? $request->post('/api/v1/enrollment', $payload) : $request->postJson('/api/v1/enrollment', $payload);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function subscription(array $overrides = []): array
    {
        return [
            'student_type' => StudentType::Subscription->value,
            'subscription_start_month' => 'june',
            'subscription_start_year' => 2025,
            'subscription_end_month' => 'march',
            'subscription_end_year' => 2026,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function contact(string $name, ?string $email = null): array
    {
        return ['full_name' => $name, 'relationship' => 'Guardian', 'phone' => '09171234567', 'address' => '123 Main St', 'email' => $email];
    }
}
