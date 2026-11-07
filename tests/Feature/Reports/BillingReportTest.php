<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class BillingReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');
        $this->supervisor->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asManager(): static
    {
        Sanctum::actingAs($this->manager, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asSupervisor(): static
    {
        Sanctum::actingAs($this->supervisor, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function createPayment(Student $student, string $status = 'unpaid', float $amount = 800.00): StudentMonthlyPayment
    {
        return StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => 'june',
            'year' => now()->year,
            'status' => $status,
            'amount' => $amount,
            'recorded_at' => $status === 'paid' ? now() : null,
            'recorded_by' => $status === 'paid' ? $this->admin->id : null,
        ]);
    }

    public function test_billing_report_returns_paginated_payments(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $this->createPayment($student, 'paid');

        $year = now()->year;
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}");

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta', 'summary']);
    }

    public function test_billing_report_includes_student_full_name(): void
    {
        $student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);
        $this->createPayment($student);

        $year = now()->year;
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}");

        $response->assertOk();
        $this->assertSame('Juan Dela Cruz', $response->json('data.0.student.full_name'));
    }

    public function test_default_school_month_and_year_filters_to_current_school_period(): void
    {
        $this->travelTo('2026-11-15');

        $this->assertDefaultFiltersToCurrentSchoolPeriod();
    }

    public function test_default_school_month_and_year_filters_to_current_school_period_in_january(): void
    {
        $this->travelTo('2027-01-20');

        $this->assertDefaultFiltersToCurrentSchoolPeriod();
    }

    public function test_default_school_month_and_year_filters_to_current_school_period_in_october(): void
    {
        $this->travelTo('2026-10-15');

        $this->assertDefaultFiltersToCurrentSchoolPeriod();
    }

    /**
     * T-23 (design §16.7): April and May are not school months, so `withDefaultPeriod()`
     * falls back to the whole school year instead of a single month.
     */
    public function test_default_filters_cover_the_whole_school_year_during_the_summer_break(): void
    {
        $this->travelTo('2027-05-20');

        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        $juneThisSchoolYear = StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => 'june',
            'year' => 2026,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        $marchThisSchoolYear = StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => 'march',
            'year' => 2027,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => 'june',
            'year' => 2025,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/billing');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $expected = collect([$juneThisSchoolYear->id, $marchThisSchoolYear->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);
    }

    /**
     * Payment `year` is the calendar year, as EnrollmentService writes it (Spec 16 B-05).
     */
    private function assertDefaultFiltersToCurrentSchoolPeriod(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        // Current period payment — should appear
        StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => strtolower(now()->format('F')),
            'year' => now()->year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        // Different month payment — must NOT appear
        $otherMonth = now()->month === 6 ? 'july' : 'june';
        StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => $otherMonth,
            'year' => now()->year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        $response = $this->asAdmin()->getJson('/api/v1/reports/billing');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_supervisor_can_view_billing_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/billing');

        $response->assertOk();
    }

    public function test_unpaid_records_appear_first(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student2 = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->createPayment($student, 'paid');
        $this->createPayment($student2, 'unpaid');

        $year = now()->year;
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}");

        $response->assertOk();
        $this->assertEquals('unpaid', $response->json('data.0.status'));
    }

    public function test_status_filter_returns_only_paid(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student2 = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->createPayment($student, 'paid');
        $this->createPayment($student2, 'unpaid');

        $year = now()->year;
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&status=paid");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_summary_collection_rate_is_calculated(): void
    {
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student2 = Student::factory()->create(['branch_id' => $this->branch->id]);

        $this->createPayment($student, 'paid', 800.00);
        $this->createPayment($student2, 'unpaid', 800.00);

        $year = now()->year;
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}");

        $response->assertOk();
        $this->assertEquals(800.0, $response->json('summary.total_collected'));
        $this->assertEquals(800.0, $response->json('summary.total_outstanding'));
        $this->assertEquals(50.0, $response->json('summary.collection_rate'));
    }

    public function test_supervisor_cannot_export_billing_report(): void
    {
        $response = $this->asSupervisor()->getJson('/api/v1/reports/billing/export');

        $response->assertForbidden();
    }

    public function test_manager_can_export_billing_report(): void
    {
        $response = $this->asManager()->getJson('/api/v1/reports/billing/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * The export has no per_page rule, so a value over the list's max:100 is still accepted (H8).
     */
    public function test_export_ignores_a_per_page_above_the_list_limit(): void
    {
        $response = $this->asManager()->getJson('/api/v1/reports/billing/export?per_page=500');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_grade_level_filter_works(): void
    {
        $grade1Student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'grade_level' => 'Grade 1',
        ]);
        $grade3Student = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'grade_level' => 'Grade 3',
        ]);

        $this->createPayment($grade1Student);
        $this->createPayment($grade3Student);

        $year = now()->year;
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&grade_level=Grade+1");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_search_by_first_name_returns_matching_students(): void
    {
        $year = now()->year;

        $maria = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);
        $pedro = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Pedro',
            'last_name' => 'Reyes',
        ]);

        StudentMonthlyPayment::create([
            'student_id' => $maria->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);
        StudentMonthlyPayment::create([
            'student_id' => $pedro->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&search=maria");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Maria Santos', $response->json('data.0.student.full_name'));
    }

    public function test_search_by_student_number_returns_matching_student(): void
    {
        $year = now()->year;

        $target = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_number' => 'STU-2026-001',
        ]);
        $other = Student::factory()->create([
            'branch_id' => $this->branch->id,
            'student_number' => 'STU-2026-002',
        ]);

        StudentMonthlyPayment::create([
            'student_id' => $target->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);
        StudentMonthlyPayment::create([
            'student_id' => $other->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&search=STU-2026-001");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_search_with_no_match_returns_empty_data(): void
    {
        $year = now()->year;
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&search=doesnotexist");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_recorded_by_filter_returns_only_payments_by_that_staff_member(): void
    {
        $year = now()->year;

        $student1 = Student::factory()->create(['branch_id' => $this->branch->id]);
        $student2 = Student::factory()->create(['branch_id' => $this->branch->id]);

        StudentMonthlyPayment::create([
            'student_id' => $student1->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'paid',
            'amount' => 800.00,
            'recorded_at' => now(),
            'recorded_by' => $this->admin->id,
        ]);
        StudentMonthlyPayment::create([
            'student_id' => $student2->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'paid',
            'amount' => 800.00,
            'recorded_at' => now(),
            'recorded_by' => $this->manager->id,
        ]);

        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&recorded_by={$this->admin->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_recorded_from_excludes_payments_recorded_before_date(): void
    {
        $year = now()->year;
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'paid',
            'amount' => 800.00,
            'recorded_at' => now()->subDays(3),
            'recorded_by' => $this->admin->id,
        ]);

        $from = now()->subDay()->toDateString();
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&recorded_from={$from}");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_recorded_to_excludes_payments_recorded_after_date(): void
    {
        $year = now()->year;
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'paid',
            'amount' => 800.00,
            'recorded_at' => now(),
            'recorded_by' => $this->admin->id,
        ]);

        $to = now()->subDay()->toDateString();
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&recorded_to={$to}");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_recorded_from_and_to_together_return_payments_within_range(): void
    {
        $year = now()->year;
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        // Inside range
        StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'paid',
            'amount' => 800.00,
            'recorded_at' => now(),
            'recorded_by' => $this->admin->id,
        ]);

        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&recorded_from={$from}&recorded_to={$to}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_recorded_from_does_not_exclude_unpaid_records(): void
    {
        $year = now()->year;
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);

        // Unpaid payment — no recorded_at; should always pass through date filters
        StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => 'june',
            'year' => $year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);

        // Date filter set to yesterday — unpaid record should still appear
        $from = now()->subDay()->toDateString();
        $response = $this->asAdmin()->getJson("/api/v1/reports/billing?school_month=june&year={$year}&recorded_from={$from}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    // --- School year (Spec 16 B-05): `year` is the school-year start, payment year the calendar year ---

    public function test_january_of_school_year_2026_is_the_january_2027_payment(): void
    {
        $this->travelTo('2027-01-20 09:00:00');
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $january2027 = $this->unpaidPayment($student, 'january', 2027);
        $this->unpaidPayment($student, 'january', 2026);

        $response = $this->asAdmin()->getJson('/api/v1/reports/billing?year=2026&school_month=january');

        $response->assertOk();
        $this->assertSame([$january2027->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_all_months_of_school_year_2025_run_from_june_2025_to_march_2026(): void
    {
        $this->travelTo('2026-04-15 09:00:00');
        $student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $june2025 = $this->unpaidPayment($student, 'june', 2025);
        $march2026 = $this->unpaidPayment($student, 'march', 2026);
        $this->unpaidPayment($student, 'march', 2025);
        $this->unpaidPayment($student, 'june', 2026);

        $response = $this->asAdmin()->getJson('/api/v1/reports/billing?year=2025');

        $response->assertOk();
        $this->assertEqualsCanonicalizing([$june2025->id, $march2026->id], collect($response->json('data'))->pluck('id')->all());
    }

    private function unpaidPayment(Student $student, string $schoolMonth, int $year): StudentMonthlyPayment
    {
        return StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => $schoolMonth,
            'year' => $year,
            'status' => 'unpaid',
            'amount' => 800.00,
        ]);
    }

    /**
     * `%` and `_` in a search are literal characters, not wildcards (Spec 16 B-10).
     */
    public function test_search_matches_percent_and_underscore_literally(): void
    {
        $paymentFor = fn (string $firstName, string $studentNumber): StudentMonthlyPayment => $this->unpaidPayment(
            Student::factory()->create(['branch_id' => $this->branch->id, 'first_name' => $firstName, 'last_name' => 'Reyes', 'student_number' => $studentNumber]),
            'june',
            2026,
        );
        $fiveHundred = $paymentFor('Ana500', 'N-0001');
        $paymentFor('Anaaxb', 'N-0002');
        $underscore = $paymentFor('Anaa_b', 'N-0003');
        $idsFor = fn (string $term): array => collect($this->asAdmin()->getJson('/api/v1/reports/billing?'.http_build_query(['year' => 2026, 'school_month' => 'june', 'search' => $term]))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame(
            ['50%' => [], 'a_b' => [$underscore->id], '500' => [$fiveHundred->id]],
            ['50%' => $idsFor('50%'), 'a_b' => $idsFor('a_b'), '500' => $idsFor('500')],
        );
    }

    /**
     * Rows carry only the student and recorder fields the report shows, not their personal data
     * (Spec 16 B-12, B-13).
     */
    public function test_rows_carry_only_the_student_and_recorder_fields_the_report_shows(): void
    {
        $recorder = User::factory()->create(['first_name' => 'Rhea', 'last_name' => 'Santos', 'daily_rate' => 500, 'phone' => '09170000000', 'birthday' => '1990-01-01']);
        $student = Student::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Ana', 'last_name' => 'Reyes']);
        StudentMonthlyPayment::create([
            'student_id' => $student->id,
            'school_month' => 'june',
            'year' => 2026,
            'status' => 'paid',
            'amount' => 800.00,
            'recorded_by' => $recorder->id,
            'recorded_at' => now(),
        ]);

        $row = $this->asAdmin()->getJson('/api/v1/reports/billing?year=2026&school_month=june')->assertOk()->json('data.0');

        $keys = fn (array $object): array => collect(array_keys($object))->sort()->values()->all();
        $this->assertSame(
            [
                'student' => ['first_name', 'full_name', 'grade_level', 'id', 'last_name', 'section', 'student_number'],
                'recorder' => ['first_name', 'full_name', 'id', 'last_name'],
                'recorder full_name' => 'Rhea Santos',
                'sensitive keys present' => [],
            ],
            [
                'student' => $keys($row['student']),
                'recorder' => $keys($row['recorder']),
                'recorder full_name' => $row['recorder']['full_name'],
                'sensitive keys present' => array_values(array_intersect(
                    [...array_keys($row['student']), ...array_keys($row['recorder'])],
                    ['qr_code', 'daily_rate', 'phone', 'birthday', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'],
                )),
            ],
        );
    }

    /**
     * The export's totals and status cells come from the loaded payments. A string compare
     * against an enum-cast status would make both totals 0 (Spec 16 T-12.3, H2).
     */
    public function test_export_totals_and_status_cells_count_paid_and_unpaid_payments(): void
    {
        $this->unpaidPayment(Student::factory()->create(['branch_id' => $this->branch->id]), 'june', 2026)
            ->update(['status' => 'paid', 'amount' => 800.00, 'recorded_at' => now(), 'recorded_by' => $this->admin->id]);
        $this->unpaidPayment(Student::factory()->create(['branch_id' => $this->branch->id]), 'june', 2026)
            ->update(['amount' => 500.00]);

        $response = $this->asManager()->getJson('/api/v1/reports/billing/export?year=2026&school_month=june');

        $response->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $rows = $sheet->toArray();
        $summary = collect($rows)->filter(fn (array $row): bool => is_string($row[0]))->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
        $this->assertSame(
            ['status cells' => ['paid', 'unpaid'], 'Total Collected' => 800.0, 'Total Outstanding' => 500.0],
            [
                'status cells' => collect(array_slice($rows, 1, 2))->pluck(6)->sort()->values()->all(),
                'Total Collected' => (float) $summary['Total Collected'],
                'Total Outstanding' => (float) $summary['Total Outstanding'],
            ],
        );
    }
}
