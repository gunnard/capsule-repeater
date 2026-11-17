<?php

declare(strict_types=1);

namespace App\Tests\Verify;

use App\Agent\CostMeter;
use App\Phase\Reproduction;
use App\Verify\DifferentialValidator;
use App\Verify\InjectionGuard;
use App\Verify\PatchGuard;
use App\Verify\ReproductionGuard;
use App\Verify\VerificationRunner;
use App\Verdict\Verdict;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

/**
 * A reproduction travels separately from the patch.
 *
 * The distinction is load-bearing: the patch under test must be a pure source
 * change, because the revert phase proves the patch is what fixed things. If the
 * test rode along inside the diff, reverting would delete the test and the revert
 * phase would fail for the wrong reason — a false VERIFIED.
 */
final class ReproductionPreludeTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir().'/capsule-prelude-'.bin2hex(random_bytes(6));
        mkdir($this->repo.'/src', 0o755, true);

        file_put_contents($this->repo.'/src/Counter.php', self::counterSource('return $value;'));

        exec(\sprintf(
            'cd %s && git init -q && git add -A && git -c user.email=t@localhost -c user.name=t commit -qm baseline',
            escapeshellarg($this->repo),
        ));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->repo));
    }

    private function runner(): VerificationRunner
    {
        return new VerificationRunner(
            new DifferentialValidator(),
            new InjectionGuard(),
            new PatchGuard(),
            new ReproductionGuard(),
        );
    }

    private static function counterSource(string $body): string
    {
        return "<?php\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Counter\n{\n    public function increment(int \$value): int\n    {\n        {$body}\n    }\n}\n";
    }

    private function fixPatch(): string
    {
        file_put_contents($this->repo.'/src/Counter.php', self::counterSource('return $value + 1;'));
        $patch = (string) shell_exec(\sprintf('cd %s && git diff', escapeshellarg($this->repo)));
        exec(\sprintf('cd %s && git checkout -- .', escapeshellarg($this->repo)));

        return $patch;
    }

    /** A reproduction that fails on buggy code and passes once the fix lands. */
    private static function reproduction(): Reproduction
    {
        $path = 'tests/ReproductionTest.php';
        $contents = <<<'PHP'
            <?php
            require __DIR__.'/../src/Counter.php';

            if ((new App\Counter())->increment(2) !== 3) {
                fwrite(STDERR, "FAIL increment(2) should be 3\n");
                exit(1);
            }

            echo "OK\n";
            PHP;

        return new Reproduction(
            path: $path,
            contents: $contents,
            checks: [['php', [$path]]],
        );
    }

    public function testAPatchIsVerifiedAgainstAnAuthoredReproduction(): void
    {
        $evidence = $this->runner()->verify(
            repoPath: $this->repo,
            issueText: "increment() does not increment.\n",
            issueSource: 'issue.md',
            checks: [['php', ['check.php']]],
            patchText: $this->fixPatch(),
            reproduction: self::reproduction(),
        );

        self::assertSame(Verdict::Verified, $evidence->verdict);
        self::assertSame('tests/ReproductionTest.php', $evidence->reproductionPath);
        self::assertTrue($evidence->isLoadBearing());
    }

    /**
     * The regression guard for the original bug: a patch that changes nothing
     * must not be verified just because the authored test happens to pass.
     */
    public function testAnEmptyPatchIsNotVerifiedEvenWithAReproduction(): void
    {
        // The reproduction passes, but there is no source change to credit.
        $trivial = new Reproduction(
            path: 'tests/ReproductionTest.php',
            contents: "<?php\nexit(0);\n",
            checks: [['php', ['tests/ReproductionTest.php']]],
        );

        $evidence = $this->runner()->verify(
            repoPath: $this->repo,
            issueText: "increment() does not increment.\n",
            issueSource: 'issue.md',
            checks: [['php', ['check.php']]],
            patchText: '',
            reproduction: $trivial,
        );

        self::assertNotSame(Verdict::Verified, $evidence->verdict);
    }

    public function testTheReproductionSurvivesTheRevertPhase(): void
    {
        $evidence = $this->runner()->verify(
            repoPath: $this->repo,
            issueText: "increment() does not increment.\n",
            issueSource: 'issue.md',
            checks: [['php', ['check.php']]],
            patchText: $this->fixPatch(),
            reproduction: self::reproduction(),
        );

        // If the revert phase had failed because the test vanished, the revert
        // check would show a "could not open input file" style error instead of
        // the actual assertion failure.
        self::assertCount(1, $evidence->revertChecks);
        self::assertStringContainsString('FAIL increment(2) should be 3', $evidence->revertChecks[0]->output);
    }

    public function testADecorativePatchIsRefusedWithAReproduction(): void
    {
        file_put_contents($this->repo.'/src/Counter.php', "<?php\n".self::counterSource('return $value;')."\n// touched\n");
        $decorative = (string) shell_exec(\sprintf('cd %s && git diff', escapeshellarg($this->repo)));
        exec(\sprintf('cd %s && git checkout -- .', escapeshellarg($this->repo)));

        $evidence = $this->runner()->verify(
            repoPath: $this->repo,
            issueText: "increment() does not increment.\n",
            issueSource: 'issue.md',
            checks: [['php', ['check.php']]],
            patchText: $decorative,
            reproduction: self::reproduction(),
        );

        self::assertSame(Verdict::Unverified, $evidence->verdict);
    }

    public function testTheCostCeilingBlocksBeforeAnyCheckRuns(): void
    {
        $meter = new CostMeter();
        $meter->record(new Metadata([
            'token_usage' => new TokenUsage(
                promptTokens: 1_000_000,
                completionTokens: 1_000_000,
                totalTokens: 2_000_000,
                model: 'gpt-4o',
            ),
        ]));

        $evidence = $this->runner()->verify(
            repoPath: $this->repo,
            issueText: "increment() does not increment.\n",
            issueSource: 'issue.md',
            checks: [['php', ['check.php']]],
            patchText: $this->fixPatch(),
            maxCost: 0.01,
            meter: $meter,
        );

        self::assertSame(Verdict::Blocked, $evidence->verdict);
        self::assertSame('cost_exceeded', $evidence->reason);
        self::assertSame([], $evidence->baseChecks, 'nothing should run after the ceiling is hit');
        self::assertGreaterThan(0.01, $evidence->cost);
    }

    public function testAReportedCostTravelsIntoTheEvidence(): void
    {
        $meter = new CostMeter();
        $meter->record(new Metadata([
            'token_usage' => new TokenUsage(
                promptTokens: 1000,
                completionTokens: 500,
                totalTokens: 1500,
                model: 'gpt-4o-mini',
            ),
        ]));

        $evidence = $this->runner()->verify(
            repoPath: $this->repo,
            issueText: "increment() does not increment.\n",
            issueSource: 'issue.md',
            checks: [['php', ['check.php']]],
            patchText: $this->fixPatch(),
            reproduction: self::reproduction(),
            meter: $meter,
        );

        self::assertSame(Verdict::Verified, $evidence->verdict);
        self::assertGreaterThan(0.0, $evidence->cost);
        self::assertSame(1, $evidence->meter?->calls());
        self::assertArrayHasKey('usage', $evidence->toArray());
    }
}
