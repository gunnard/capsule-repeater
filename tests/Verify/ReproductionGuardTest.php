<?php

declare(strict_types=1);

namespace App\Tests\Verify;

use App\Verify\ReproductionGuard;
use PHPUnit\Framework\TestCase;

/**
 * The guard that stops a model from grading its own exam.
 *
 * Without this, the system would happily certify a patch that only changed the
 * assertions — the most likely failure mode of an agent told to "make the tests pass".
 */
final class ReproductionGuardTest extends TestCase
{
    public function testItAllowsAPatchThatOnlyTouchesSource(): void
    {
        $inspection = (new ReproductionGuard())->inspect(['src/ShippingCalculator.php']);

        self::assertTrue($inspection['allowed']);
        self::assertSame([], $inspection['reasons']);
    }

    public function testItRefusesAPatchThatTouchesATestFile(): void
    {
        $inspection = (new ReproductionGuard())->inspect(['src/Foo.php', 'tests/ShippingCalculatorTest.php']);

        self::assertFalse($inspection['allowed']);
        self::assertContains('tests/ShippingCalculatorTest.php', $inspection['protectedPaths']);
    }

    public function testItRefusesAPatchThatTouchesTheTestDirectory(): void
    {
        self::assertFalse((new ReproductionGuard())->inspect(['test/unit/FooTest.php'])['allowed']);
    }

    public function testItRefusesAPatchThatTouchesPhpUnitConfig(): void
    {
        self::assertFalse((new ReproductionGuard())->inspect(['phpunit.xml'])['allowed']);
        self::assertFalse((new ReproductionGuard())->inspect(['phpunit.xml.dist'])['allowed']);
    }

    public function testItRefusesAPatchThatTouchesDependencyLocking(): void
    {
        self::assertFalse((new ReproductionGuard())->inspect(['composer.json'])['allowed']);
        self::assertFalse((new ReproductionGuard())->inspect(['composer.lock'])['allowed']);
    }

    public function testItRefusesAPatchThatTouchesCiConfig(): void
    {
        self::assertFalse((new ReproductionGuard())->inspect(['.github/workflows/ci.yml'])['allowed']);
    }

    public function testTheReasonExplainsWhyNotJustWhat(): void
    {
        $reasons = (new ReproductionGuard())->inspect(['tests/FooTest.php'])['reasons'];

        self::assertStringContainsString('never the check that judges it', $reasons[0]);
    }

    public function testItRefusesAPatchThatTouchesTheExecutedCheck(): void
    {
        // A corpus case whose check is `check.php` is no less a specification
        // than one whose check lives in tests/.
        $inspection = (new ReproductionGuard())->inspect(['check.php'], ['check.php']);

        self::assertFalse($inspection['allowed']);
        self::assertContains('check.php', $inspection['protectedPaths']);
    }

    public function testItAllowsAPatchWhenTheExecutedPathIsDifferent(): void
    {
        $inspection = (new ReproductionGuard())->inspect(['src/Counter.php'], ['check.php']);

        self::assertTrue($inspection['allowed']);
    }
}
