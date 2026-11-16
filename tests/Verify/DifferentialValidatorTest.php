<?php

declare(strict_types=1);

namespace App\Tests\Verify;

use App\Sandbox\Sandbox;
use App\Verify\DifferentialValidator;
use App\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

/**
 * The four-state contract, expressed as executable assertions.
 *
 * These tests are the specification of the product: they encode the difference
 * between a patch that is proven and one that merely looks like one.
 */
final class DifferentialValidatorTest extends TestCase
{
    private string $root;
    private Sandbox $sandbox;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/capsule-diff-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);

        // A "check" that fails until the marker file exists.
        file_put_contents($this->root.'/check.php', <<<'PHP'
            <?php
            exit(file_exists(__DIR__.'/fixed') ? 0 : 1);
            PHP);

        $this->sandbox = new Sandbox($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    /** @return list<array{0: string, 1: list<string>}> */
    private function checks(): array
    {
        return [['php', ['check.php']]];
    }

    private function validator(): DifferentialValidator
    {
        return new DifferentialValidator();
    }

    public function testALoadBearingPatchIsVerified(): void
    {
        $evidence = $this->validator()->validateWithPatch(
            sandbox: $this->sandbox,
            checks: $this->checks(),
            applyPatch: function (): array {
                file_put_contents($this->root.'/fixed', '');

                return ['applied' => true, 'message' => 'ok'];
            },
            revertPatch: function (): void {
                @unlink($this->root.'/fixed');
            },
            summary: 'test',
        );

        self::assertSame(Verdict::Verified, $evidence->verdict);
        self::assertTrue($evidence->isLoadBearing());
    }

    public function testADecorativePatchIsUnverified(): void
    {
        // The patch applies but changes nothing that affects the check.
        $evidence = $this->validator()->validateWithPatch(
            sandbox: $this->sandbox,
            checks: $this->checks(),
            applyPatch: static fn (): array => ['applied' => true, 'message' => 'ok'],
            revertPatch: static function (): void {},
            summary: 'test',
        );

        self::assertSame(Verdict::Unverified, $evidence->verdict);
        self::assertFalse($evidence->isLoadBearing());
        self::assertContains(
            'the patch did not make the checks pass',
            $evidence->limits,
        );
    }

    public function testAPatchThatPassesAtBaseIsNoPatch(): void
    {
        // Nothing is broken, so there is no honest patch to produce.
        file_put_contents($this->root.'/fixed', '');

        $evidence = $this->validator()->validateWithPatch(
            sandbox: $this->sandbox,
            checks: $this->checks(),
            applyPatch: static fn (): array => ['applied' => true, 'message' => 'ok'],
            revertPatch: static function (): void {},
            summary: 'test',
        );

        self::assertSame(Verdict::NoPatch, $evidence->verdict);
        self::assertSame('cannot_reproduce', $evidence->reason);
    }

    public function testAPatchThatDoesNotApplyIsUnverifiedNotVerified(): void
    {
        $evidence = $this->validator()->validateWithPatch(
            sandbox: $this->sandbox,
            checks: $this->checks(),
            applyPatch: static fn (): array => ['applied' => false, 'message' => 'corrupt patch'],
            revertPatch: static function (): void {},
            summary: 'test',
        );

        self::assertSame(Verdict::Unverified, $evidence->verdict);
        self::assertSame('patch_did_not_apply', $evidence->reason);
        self::assertSame([], $evidence->headChecks);
    }

    public function testItNeverReportsVerifiedWithoutAHeadPhase(): void
    {
        // The strongest invariant: Verified requires all three phases to exist.
        $evidence = $this->validator()->validateWithPatch(
            sandbox: $this->sandbox,
            checks: $this->checks(),
            applyPatch: static fn (): array => ['applied' => false, 'message' => 'nope'],
            revertPatch: static function (): void {},
            summary: 'test',
        );

        self::assertNotSame(Verdict::Verified, $evidence->verdict);
        self::assertSame([], $evidence->headChecks);
        self::assertSame([], $evidence->revertChecks);
    }
}
