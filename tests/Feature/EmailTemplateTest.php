<?php

namespace Tests\Feature;

use App\Enums\FeedbackCategory;
use App\Mail\FeedbackReplyMail;
use App\Mail\ParentWelcomeMail;
use App\Mail\PreRegistrationApprovedMail;
use App\Mail\PreRegistrationReceivedMail;
use App\Mail\PreRegistrationRejectedMail;
use App\Mail\WalletAlertMail;
use App\Models\Branch;
use App\Models\Feedback;
use App\Models\ParentUser;
use App\Models\PreRegistration;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_welcome_mail_renders_with_branding(): void
    {
        $parent = ParentUser::create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.com',
            'password' => bcrypt('password'),
        ]);

        $mailable = new ParentWelcomeMail($parent, 'test-activation-token');

        $mailable
            ->assertSeeInHtml('sunbites.png')
            ->assertSeeInHtml('Welcome to Sunbites, Maria!')
            ->assertSeeInHtml('Activate My Account')
            ->assertSeeInHtml('60 minutes')
            ->assertSeeInHtml('Sunbites School Canteen Management System');
    }

    public function test_wallet_alert_mail_renders_with_branding(): void
    {
        $branch = Branch::factory()->create();
        $parent = ParentUser::create([
            'first_name' => 'Jose',
            'last_name' => 'Reyes',
            'email' => 'jose@example.com',
            'password' => bcrypt('password'),
        ]);
        $student = Student::factory()->create(['branch_id' => $branch->id]);

        $mailable = new WalletAlertMail($parent, $student, 45.00, 100.00);

        $mailable
            ->assertSeeInHtml('sunbites.png')
            ->assertSeeInHtml('Low Wallet Balance Alert')
            ->assertSeeInHtml('45.00')
            ->assertSeeInHtml('100.00')
            ->assertSeeInHtml('View Parent Portal')
            ->assertSeeInHtml('Sunbites School Canteen Management System');
    }

    public function test_feedback_reply_mail_renders_with_branding(): void
    {
        $branch = Branch::factory()->create();
        $parent = ParentUser::create([
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'email' => 'ana@example.com',
            'password' => bcrypt('password'),
        ]);
        $student = Student::factory()->create(['branch_id' => $branch->id]);
        $feedback = Feedback::create([
            'parent_id' => $parent->id,
            'student_id' => $student->id,
            'branch_id' => $branch->id,
            'category' => FeedbackCategory::General->value,
            'rating' => 4,
            'message' => 'Great service today!',
            'admin_reply' => 'Thank you for your kind feedback!',
            'is_read' => true,
        ]);

        $mailable = new FeedbackReplyMail($feedback);

        $mailable
            ->assertSeeInHtml('sunbites.png')
            ->assertSeeInHtml('A Reply to Your Feedback')
            ->assertSeeInHtml('Ana')
            ->assertSeeInHtml('Thank you for your kind feedback!')
            ->assertSeeInHtml('Sunbites School Canteen Management System');
    }

    public function test_pre_registration_received_mail_renders_with_branding(): void
    {
        $preRegistration = PreRegistration::factory()->create([
            'first_name' => 'Carlos',
            'last_name' => 'Bautista',
            'signatory_name' => 'Mario Bautista',
            'grade_level' => 'Grade 3',
            'enrollment_type' => 'non_subscription',
        ]);

        $mailable = new PreRegistrationReceivedMail($preRegistration);

        $mailable
            ->assertSeeInHtml('sunbites.png')
            ->assertSeeInHtml('Pre-Registration Received!')
            ->assertSeeInHtml('Mario Bautista')
            ->assertSeeInHtml('Carlos Bautista')
            ->assertSeeInHtml('Grade 3')
            ->assertSeeInHtml('Sunbites School Canteen Management System');
    }

    public function test_pre_registration_approved_mail_renders_with_branding(): void
    {
        $branch = Branch::factory()->create();
        $preRegistration = PreRegistration::factory()->create([
            'first_name' => 'Luisa',
            'last_name' => 'Dela Cruz',
            'signatory_name' => 'Pedro Dela Cruz',
            'grade_level' => 'Grade 5',
            'enrollment_type' => 'subscription',
        ]);
        $student = Student::factory()->create(['branch_id' => $branch->id]);

        $mailable = new PreRegistrationApprovedMail($preRegistration, $student);

        $mailable
            ->assertSeeInHtml('sunbites.png')
            ->assertSeeInHtml('Enrollment Approved!')
            ->assertSeeInHtml('Pedro Dela Cruz')
            ->assertSeeInHtml('Luisa Dela Cruz')
            ->assertSeeInHtml('Grade 5')
            ->assertSeeInHtml('Sunbites School Canteen Management System');
    }

    public function test_pre_registration_rejected_mail_renders_with_branding(): void
    {
        $preRegistration = PreRegistration::factory()->create([
            'first_name' => 'Marco',
            'last_name' => 'Santiago',
            'signatory_name' => 'Ella Santiago',
            'rejection_reason' => 'Incomplete supporting documents provided.',
        ]);

        $mailable = new PreRegistrationRejectedMail($preRegistration);

        $mailable
            ->assertSeeInHtml('sunbites.png')
            ->assertSeeInHtml('Update on Your Pre-Registration')
            ->assertSeeInHtml('Ella Santiago')
            ->assertSeeInHtml('Incomplete supporting documents provided.')
            ->assertSeeInHtml('Sunbites School Canteen Management System');
    }
}
