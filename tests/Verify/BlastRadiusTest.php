<?php

declare(strict_types=1);

namespace App\Tests\Verify;

use App\Sandbox\Sandbox;
use App\Verify\BlastRadius;
use PHPUnit\Framework\TestCase;

final class BlastRadiusTest extends TestCase
{
    private string $root;
    private Sandbox $sandbox;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/capsule-br-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o755, true);

        file_put_contents($this->root.'/src/Target.php', <<<'PHP'
            <?php
            final class Target
            {
                public function doWork(int $x): int { return $x + 1; }
            }
            PHP);

        file_put_contents($this->root.'/src/CallerA.php', <<<'PHP'
            <?php
            $t = new Target();
            echo $t->doWork(5);
            PHP);

        file_put_contents($this->root.'/src/CallerB.php', <<<'PHP'
            <?php
            // doWork is referenced here too
            $result = Target::doWork(3);
            PHP);

        $this->sandbox = new Sandbox($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public function testItExtractsChangedSymbols(): void
    {
        $report = (new BlastRadius())->analyze($this->sandbox, ['src/Target.php']);

        self::assertContains('Target', $report->symbols);
        self::assertContains('doWork', $report->symbols);
    }

    public function testItFindsConsumers(): void
    {
        $report = (new BlastRadius())->analyze($this->sandbox, ['src/Target.php']);

        self::assertGreaterThan(0, $report->totalConsumers);
    }

    public function testRiskLevelIsNoneWhenNoConsumers(): void
    {
        // A file with no consumers
        file_put_contents($this->root.'/src/Orphan.php', "<?php\nfinal class Orphan {}\n");
        $report = (new BlastRadius())->analyze($this->sandbox, ['src/Orphan.php']);

        self::assertSame('none', $report->riskLevel);
        self::assertSame(0, $report->totalConsumers);
    }

    public function testExceedsThreshold(): void
    {
        $report = (new BlastRadius())->analyze($this->sandbox, ['src/Target.php']);

        // With 2+ consumers, threshold 1 should be exceeded
        if ($report->totalConsumers > 1) {
            self::assertTrue($report->exceeds(1));
            self::assertFalse($report->exceeds($report->totalConsumers));
        }
    }

    public function testToArrayIncludesAllFields(): void
    {
        $report = (new BlastRadius())->analyze($this->sandbox, ['src/Target.php']);
        $arr = $report->toArray();

        self::assertArrayHasKey('symbols', $arr);
        self::assertArrayHasKey('consumers', $arr);
        self::assertArrayHasKey('totalConsumers', $arr);
        self::assertArrayHasKey('riskLevel', $arr);
    }
}
