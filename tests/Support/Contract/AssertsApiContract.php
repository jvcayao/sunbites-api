<?php

namespace Tests\Support\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Compares a response with its recorded contract fixture (Spec 16 design §4.1.2).
 *
 * Fixtures live in `tests/Fixtures/contracts/{slug}@{variant}.json`. They are written only in
 * record mode (`CONTRACT_RECORD=1`), which the contract-freeze commit uses once. A Track A
 * change that alters any fixture is a regression by definition. The directory is gitignored and
 * exists only on the machine that recorded it (design §4.1.7).
 */
trait AssertsApiContract
{
    public const string FIXTURES_ABSENT_MESSAGE = 'Contract fixtures are local only (gitignored, Spec 16 design §4.1.7).';

    /**
     * @param  string  $routeKey  `"{METHOD} {uri}"` exactly as `route:list` prints it
     * @param  list<string>  $mapPaths  object paths whose keys are data (see ShapeExtractor)
     */
    protected function assertContract(
        TestResponse $response,
        string $routeKey,
        string $variant = 'ok',
        array $mapPaths = [],
    ): void {
        $content = (string) $response->getContent();

        $this->assertMatchesContractFixture($routeKey, $variant, [
            'status' => $response->getStatusCode(),
            'body' => ShapeExtractor::shapeOf(
                $content === '' ? null : json_decode($content, false, 512, JSON_THROW_ON_ERROR),
                $mapPaths,
            ),
        ]);
    }

    /**
     * File downloads record headers instead of a body. Digits in the filename become `9`, so a
     * date or id in the name does not change the contract.
     */
    protected function assertFileContract(TestResponse $response, string $routeKey, string $variant = 'ok'): void
    {
        $disposition = $response->headers->get('Content-Disposition');

        $this->assertMatchesContractFixture($routeKey, $variant, [
            'status' => $response->getStatusCode(),
            'content_type' => $response->headers->get('Content-Type'),
            'disposition_pattern' => $disposition === null ? null : preg_replace('/\d/', '9', $disposition),
        ]);
    }

    protected function contractSlug(string $routeKey): string
    {
        [$method, $uri] = explode(' ', $routeKey, 2);

        return strtolower($method).'__'.trim(preg_replace('/[^a-z0-9{}]+/i', '_', $uri), '_');
    }

    protected function contractFixturesDirectory(): string
    {
        return dirname(__DIR__, 2).'/Fixtures/contracts';
    }

    protected function isRecordingContracts(): bool
    {
        return env('CONTRACT_RECORD') === '1';
    }

    protected function isRecordingDriverOverrides(): bool
    {
        return env('CONTRACT_DRIVER_OVERRIDE') === '1';
    }

    protected function contractDatabaseDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * The primary fixture is recorded on MySQL. A `.sqlite.json` override exists only where the
     * SQLite shape differs (hazard H15) and is preferred when running on SQLite.
     *
     * @param  array<string, mixed>  $actual
     */
    private function assertMatchesContractFixture(string $routeKey, string $variant, array $actual): void
    {
        $slug = $this->contractSlug($routeKey);
        $primaryPath = $this->contractFixturesDirectory()."/{$slug}@{$variant}.json";
        $overridePath = $this->contractFixturesDirectory()."/{$slug}@{$variant}.sqlite.json";
        $onSqlite = $this->contractDatabaseDriver() === 'sqlite';

        // Round-trip through JSON so that an empty object compares the same as it reads back.
        $comparableActual = json_decode(ShapeExtractor::encode($actual), true);

        if ($this->isRecordingContracts()) {
            if ($onSqlite && $this->isRecordingDriverOverrides()) {
                if ($this->readContractFixture($primaryPath) === $comparableActual) {
                    $this->assertSame($this->readContractFixture($primaryPath), $comparableActual);

                    return;
                }

                $this->writeContractFixture($overridePath, $actual);
                $this->markTestIncomplete('recorded');
            }

            $this->writeContractFixture($primaryPath, $actual);
            $this->markTestIncomplete('recorded');
        }

        // CI checks out the repository without the gitignored fixtures. A missing directory
        // means "no fixtures here"; a missing file inside an existing directory still fails.
        if (! is_dir($this->contractFixturesDirectory())) {
            $this->markTestSkipped(self::FIXTURES_ABSENT_MESSAGE);
        }

        $fixturePath = $onSqlite && is_file($overridePath) ? $overridePath : $primaryPath;

        if (! is_file($fixturePath)) {
            $this->fail("No contract fixture for {$slug}@{$variant}; run with CONTRACT_RECORD=1 in the contract-freeze PR only.");
        }

        $this->assertSame($this->readContractFixture($fixturePath), $comparableActual);
    }

    private function readContractFixture(string $path): mixed
    {
        if (! is_file($path)) {
            return null;
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function writeContractFixture(string $path, array $fixture): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
