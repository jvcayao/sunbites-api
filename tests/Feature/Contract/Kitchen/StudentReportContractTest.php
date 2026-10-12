<?php

namespace Tests\Feature\Contract\Kitchen;

use Tests\Feature\Contract\ContractTestCase;

class StudentReportContractTest extends ContractTestCase
{
    public function test_student_report_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/reports/students');

        $this->assertContract($response, 'GET api/v1/reports/students', mapPaths: ['summary.grade_breakdown', 'summary.status_breakdown']);
    }

    public function test_student_report_with_an_unknown_payment_status_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->supervisor)->getJson('/api/v1/reports/students?payment_status=unknown');

        $this->assertContract($response, 'GET api/v1/reports/students', '422-validation');
    }

    public function test_student_export_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->get('/api/v1/reports/students/export');

        $this->assertFileContract($response, 'GET api/v1/reports/students/export');
    }

    public function test_student_export_with_an_unknown_payment_status_matches_contract(): void
    {
        $response = $this->scenario->asStaff($this->scenario->manager)->getJson('/api/v1/reports/students/export?payment_status=unknown');

        $this->assertContract($response, 'GET api/v1/reports/students/export', '422-validation');
    }
}
