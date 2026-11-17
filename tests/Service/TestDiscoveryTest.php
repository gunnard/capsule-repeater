<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Sandbox\Sandbox;
use App\Service\TestDiscovery;
use PHPUnit\Framework\TestCase;

final class TestDiscoveryTest extends TestCase
{
    private string $root;
    private Sandbox $sandbox;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/capsule-td-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
        $this->sandbox = new Sandbox($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public function testItDiscoversPhpUnitXml(): void
    {
        file_put_contents($this->root.'/phpunit.xml', '<phpunit/>');

        self::assertSame('php vendor/bin/phpunit', (new TestDiscovery())->discover($this->sandbox));
    }

    public function testItDiscoversPhpUnitXmlDist(): void
    {
        file_put_contents($this->root.'/phpunit.xml.dist', '<phpunit/>');

        self::assertSame('php vendor/bin/phpunit', (new TestDiscovery())->discover($this->sandbox));
    }

    public function testItPrefersCheckPhpOverPhpUnit(): void
    {
        file_put_contents($this->root.'/check.php', '<?php echo "ok";');
        file_put_contents($this->root.'/phpunit.xml', '<phpunit/>');

        self::assertSame('php check.php', (new TestDiscovery())->discover($this->sandbox));
    }

    public function testItDiscoversComposerTestScript(): void
    {
        file_put_contents($this->root.'/composer.json', json_encode(['scripts' => ['test' => 'phpunit']]));

        self::assertSame('composer test', (new TestDiscovery())->discover($this->sandbox));
    }

    public function testItDiscoversPlaywrightConfig(): void
    {
        file_put_contents($this->root.'/playwright.config.ts', 'export default {};');

        self::assertSame('npx playwright test', (new TestDiscovery())->discover($this->sandbox));
    }

    public function testItDiscoversPackageJsonTestScript(): void
    {
        file_put_contents($this->root.'/package.json', json_encode(['scripts' => ['test' => 'jest']]));

        self::assertSame('npm test', (new TestDiscovery())->discover($this->sandbox));
    }

    public function testItDiscoversPackageJsonE2eScript(): void
    {
        file_put_contents($this->root.'/package.json', json_encode(['scripts' => ['test' => 'jest', 'test:e2e' => 'playwright']]));

        self::assertSame('npm run test:e2e', (new TestDiscovery())->discover($this->sandbox));
    }

    public function testItReturnsNullWhenNothingFound(): void
    {
        self::assertNull((new TestDiscovery())->discover($this->sandbox));
    }

    public function testDescribeReturnsHumanReadable(): void
    {
        file_put_contents($this->root.'/check.php', '<?php echo "ok";');

        self::assertSame('Discovered: php check.php', (new TestDiscovery())->describe($this->sandbox));
    }

    public function testDescribeReturnsNotFoundMessage(): void
    {
        self::assertSame('No test configuration found.', (new TestDiscovery())->describe($this->sandbox));
    }
}
