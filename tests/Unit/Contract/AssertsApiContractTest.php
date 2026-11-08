<?php

namespace Tests\Unit\Contract;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\IncompleteTestError;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\Support\Contract\AssertsApiContract;

class AssertsApiContractTest extends TestCase
{
    use AssertsApiContract;

    private string $fixturesDirectory;

    private bool $recording = false;

    private bool $recordingOverrides = false;

    private string $driver = 'mysql';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesDirectory = sys_get_temp_dir().'/contract-fixtures-'.bin2hex(random_bytes(6));
        mkdir($this->fixturesDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixturesDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->fixturesDirectory)) {
            rmdir($this->fixturesDirectory);
        }

        parent::tearDown();
    }

    protected function contractFixturesDirectory(): string
    {
        return $this->fixturesDirectory;
    }

    protected function isRecordingContracts(): bool
    {
        return $this->recording;
    }

    protected function isRecordingDriverOverrides(): bool
    {
        return $this->recordingOverrides;
    }

    protected function contractDatabaseDriver(): string
    {
        return $this->driver;
    }

    public function test_slug_replaces_separators_and_keeps_route_parameter_braces(): void
    {
        $this->assertSame('get__api_v1_students_{student}', $this->contractSlug('GET api/v1/students/{student}'));
        $this->assertSame(
            'post__api_v1_students_{student}_wallet_top_ups_{transaction}_void',
            $this->contractSlug('POST api/v1/students/{student}/wallet/top-ups/{transaction}/void'),
        );
    }

    public function test_record_mode_writes_a_pretty_fixture_and_marks_the_test_incomplete(): void
    {
        $this->recording = true;

        $this->recordJson(['total' => '150.00', 'id' => 7, 'meta' => new stdClass], 201);

        $this->assertSame(
            <<<'JSON'
            {
                "status": 201,
                "body": {
                    "id": "number",
                    "meta": {},
                    "total": "string:decimal2"
                }
            }

            JSON,
            file_get_contents($this->fixturesDirectory.'/get__api_v1_students_{student}@ok.json'),
        );
    }

    public function test_compare_mode_passes_when_only_values_change(): void
    {
        $this->recording = true;
        $this->recordJson(['total' => '150.00', 'meta' => new stdClass]);
        $this->recording = false;

        $this->assertContract($this->jsonResponse(['meta' => new stdClass, 'total' => '99.50']), 'GET api/v1/students/{student}');

        $this->addToAssertionCount(1);
    }

    public function test_compare_mode_fails_when_a_shape_changes(): void
    {
        $this->recording = true;
        $this->recordJson(['total' => '150.00']);
        $this->recording = false;

        $this->expectException(ExpectationFailedException::class);

        $this->assertContract($this->jsonResponse(['total' => 150]), 'GET api/v1/students/{student}');
    }

    public function test_compare_mode_fails_with_instructions_when_the_fixture_is_missing(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No contract fixture for get__api_v1_students_{student}@404; run with CONTRACT_RECORD=1 in the contract-freeze PR only.');

        $this->assertContract($this->jsonResponse(['message' => 'Not found.'], 404), 'GET api/v1/students/{student}', '404');
    }

    public function test_compare_mode_skips_when_the_fixtures_directory_is_absent(): void
    {
        rmdir($this->fixturesDirectory);

        try {
            $this->assertContract($this->jsonResponse(['message' => 'Not found.'], 404), 'GET api/v1/students/{student}', '404');
        } catch (SkippedWithMessageException $skipped) {
            $this->assertSame('Contract fixtures are local only (gitignored, Spec 16 design §4.1.7).', $skipped->getMessage());

            return;
        }

        $this->fail('Expected the contract assertion to skip when the fixtures directory is absent.');
    }

    public function test_sqlite_override_is_written_only_when_the_shape_differs(): void
    {
        $this->recording = true;
        $this->recordJson(['sum' => '10.00', 'count' => 1]);
        $this->driver = 'sqlite';
        $this->recordingOverrides = true;

        $this->assertContract($this->jsonResponse(['sum' => '12.00', 'count' => 2]), 'GET api/v1/students/{student}');
        $this->assertFileDoesNotExist($this->fixturesDirectory.'/get__api_v1_students_{student}@ok.sqlite.json');

        $this->recordJson(['sum' => 10.0, 'count' => 1]);
        $this->assertFileExists($this->fixturesDirectory.'/get__api_v1_students_{student}@ok.sqlite.json');
    }

    public function test_sqlite_run_prefers_the_override_fixture(): void
    {
        $this->recording = true;
        $this->recordJson(['sum' => '10.00']);
        $this->driver = 'sqlite';
        $this->recordingOverrides = true;
        $this->recordJson(['sum' => 10.5]);
        $this->recording = false;
        $this->recordingOverrides = false;

        $this->assertContract($this->jsonResponse(['sum' => 3]), 'GET api/v1/students/{student}');

        $this->addToAssertionCount(1);
    }

    public function test_file_contract_replaces_digits_in_the_disposition(): void
    {
        $this->recording = true;
        $response = TestResponse::fromBaseResponse(new Response('xlsx-bytes', 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename=sales-report-ant-2026-10-01-2026-10-15.xlsx',
        ]));

        try {
            $this->assertFileContract($response, 'GET api/v1/reports/sales/export');
        } catch (IncompleteTestError) {
        }

        $fixture = json_decode((string) file_get_contents($this->fixturesDirectory.'/get__api_v1_reports_sales_export@ok.json'), true);
        $this->assertSame('attachment; filename=sales-report-ant-9999-99-99-9999-99-99.xlsx', $fixture['disposition_pattern']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordJson(array $data, int $status = 200): void
    {
        try {
            $this->assertContract($this->jsonResponse($data, $status), 'GET api/v1/students/{student}');
        } catch (IncompleteTestError) {
            return;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonResponse(array $data, int $status = 200): TestResponse
    {
        return TestResponse::fromBaseResponse(new JsonResponse($data, $status));
    }
}
