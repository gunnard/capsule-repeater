<?php

declare(strict_types=1);

namespace App\Tests\Phase;

use App\Agent\RepairAgent;
use App\Phase\TestAuthorPhase;
use App\Sandbox\Sandbox;
use App\Tests\Support\ScriptedAgent;
use PHPUnit\Framework\TestCase;

/**
 * The authoring phase decides whether a reproduction is trustworthy.
 *
 * Every test here corresponds to a failure mode that shipped once and had to be
 * fixed: a test that cannot run being counted as a failure, a passing test not
 * being discarded, an existing test being overwritten, and a repository without
 * a `tests/` directory crashing the run.
 */
final class TestAuthorPhaseTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir().'/capsule-author-'.bin2hex(random_bytes(6));
        mkdir($this->repo.'/src', 0o755, true);

        // A dependency-free repository whose check exits non-zero on a bug.
        file_put_contents($this->repo.'/src/Counter.php', <<<'PHP'
            <?php
            declare(strict_types=1);

            namespace App;

            final class Counter
            {
                public function increment(int $value): int
                {
                    return $value;
                }
            }
            PHP);

        file_put_contents($this->repo.'/check.php', <<<'PHP'
            <?php
            require __DIR__.'/src/Counter.php';
            exit((new App\Counter())->increment(2) === 3 ? 0 : 1);
            PHP);

        exec(\sprintf(
            'cd %s && git init -q && git add -A && git -c user.email=t@localhost -c user.name=t commit -qm baseline',
            escapeshellarg($this->repo),
        ));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->repo));
    }

    private function phase(ScriptedAgent $agent): TestAuthorPhase
    {
        return new TestAuthorPhase(new RepairAgent($agent));
    }

    /** A test that genuinely fails on the buggy code. */
    private static function failingTest(): string
    {
        return <<<'PHP'
            <?php
            require __DIR__.'/../src/Counter.php';

            if ((new App\Counter())->increment(2) !== 3) {
                fwrite(STDERR, "FAIL increment(2) should be 3\n");
                exit(1);
            }

            echo "OK\n";
            PHP;
    }

    /** A test that passes on the buggy code, so it reproduces nothing. */
    private static function passingTest(): string
    {
        return <<<'PHP'
            <?php
            echo "OK, and proves nothing\n";
            PHP;
    }

    public function testItAcceptsATestThatFailsOnBuggySource(): void
    {
        $result = $this->phase(new ScriptedAgent(self::failingTest()))
            ->author(new Sandbox($this->repo), "increment() does not increment.\n", 'tests');

        self::assertTrue($result->success);
        self::assertSame('baseline_failed', $result->reason);
        self::assertSame('tests/ReproductionTest.php', $result->testFile);
        self::assertNotNull($result->reproduction);
        self::assertStringContainsString('FAIL increment(2) should be 3', $result->baselineOutput);
    }

    public function testItDiscardsATestThatPassesOnBuggySource(): void
    {
        $sandbox = new Sandbox($this->repo);

        $result = $this->phase(new ScriptedAgent(self::passingTest(), self::failingTest()))
            ->author($sandbox, "increment() does not increment.\n", 'tests');

        // The first reply passed and had to be deleted, not left to collide with
        // the second attempt.
        self::assertTrue($result->success, 'the retry must be able to write the same path');
        self::assertSame('baseline_failed', $result->reason);

        $contents = $sandbox->read('tests/ReproductionTest.php');
        self::assertStringContainsString('should be 3', $contents);
    }

    public function testItRefusesWhenEveryAttemptPasses(): void
    {
        $result = $this->phase(new ScriptedAgent(self::passingTest(), self::passingTest()))
            ->author(new Sandbox($this->repo), "increment() does not increment.\n", 'tests');

        self::assertFalse($result->success);
        self::assertSame('test_passed_on_buggy_code', $result->reason);
        self::assertNull($result->reproduction);
    }

    public function testItLeavesNoFileBehindAfterARejectedAttempt(): void
    {
        $sandbox = new Sandbox($this->repo);

        $this->phase(new ScriptedAgent(self::passingTest(), self::passingTest()))
            ->author($sandbox, "increment() does not increment.\n", 'tests');

        self::assertFalse(
            $sandbox->exists('tests/ReproductionTest.php'),
            'a test that proves nothing must not survive the attempt',
        );
    }

    /**
     * The bug that motivated the runnable check: a PHPUnit file run with
     * `php file.php` dies on "Class TestCase not found" and exits 255, which the
     * old code read as "the baseline failed" and certified as a reproduction.
     */
    public function testATestThatCannotRunIsNotAReproduction(): void
    {
        $unrunnable = <<<'PHP'
            <?php
            use PHPUnit\Framework\TestCase;

            final class ReproductionTest extends TestCase
            {
                public function testIt(): void
                {
                    self::assertSame(1, 2);
                }
            }
            PHP;

        $result = $this->phase(new ScriptedAgent($unrunnable))
            ->author(new Sandbox($this->repo), "increment() does not increment.\n", 'tests');

        self::assertFalse($result->success, 'a test that cannot execute proves nothing');
        self::assertSame('test_does_not_run', $result->reason);
        self::assertNull($result->reproduction);
    }

    public function testItNeverOverwritesAnExistingTest(): void
    {
        mkdir($this->repo.'/tests', 0o755, true);
        file_put_contents($this->repo.'/tests/ReproductionTest.php', "<?php // the real specification\n");

        $result = $this->phase(new ScriptedAgent(self::failingTest()))
            ->author(new Sandbox($this->repo), "increment() does not increment.\n", 'tests');

        self::assertFalse($result->success);
        self::assertSame('test_file_exists', $result->reason);
        self::assertStringContainsString(
            'the real specification',
            (string) file_get_contents($this->repo.'/tests/ReproductionTest.php'),
        );
    }

    public function testItCreatesTheTestDirectoryWhenMissing(): void
    {
        $sandbox = new Sandbox($this->repo);
        self::assertFalse($sandbox->exists('tests'));

        $result = $this->phase(new ScriptedAgent(self::failingTest()))
            ->author($sandbox, "increment() does not increment.\n", 'tests');

        self::assertTrue($result->success);
        self::assertTrue($sandbox->exists('tests/ReproductionTest.php'));
    }

    public function testItRefusesWhenNoTestRunnerIsInstalled(): void
    {
        unlink($this->repo.'/check.php');

        $result = $this->phase(new ScriptedAgent(self::failingTest()))
            ->author(new Sandbox($this->repo), "increment() does not increment.\n", 'tests');

        self::assertFalse($result->success);
        self::assertSame('no_test_runner', $result->reason);
    }

    public function testItIgnoresAnEmptyResponseAndRetries(): void
    {
        $result = $this->phase(new ScriptedAgent('', self::failingTest()))
            ->author(new Sandbox($this->repo), "increment() does not increment.\n", 'tests');

        self::assertTrue($result->success);
    }

    public function testItKeepsAModelSuggestedPathInsideTheTestRoot(): void
    {
        $withHint = "// File: src/Evil.php\n".self::failingTest();
        $sandbox = new Sandbox($this->repo);

        $result = $this->phase(new ScriptedAgent($withHint))
            ->author($sandbox, "increment() does not increment.\n", 'tests');

        self::assertTrue($result->success);
        self::assertSame(
            'tests/ReproductionTest.php',
            $result->testFile,
            'a path outside the test root must not be honoured',
        );
    }
}
