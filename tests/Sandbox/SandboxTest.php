<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Sandbox\Sandbox;
use App\Sandbox\SandboxViolation;
use PHPUnit\Framework\TestCase;

/**
 * The sandbox is the security boundary the whole verdict rests on. If a path can
 * escape it, "BLOCKED" is theatre.
 */
final class SandboxTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/capsule-sandbox-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o755, true);
        file_put_contents($this->root.'/src/Example.php', "<?php\n\nfinal class Example {}\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public function testItReadsAFileInsideTheSandbox(): void
    {
        $sandbox = new Sandbox($this->root);

        self::assertStringContainsString('final class Example', $sandbox->read('src/Example.php'));
    }

    public function testItRefusesADotDotTraversal(): void
    {
        $sandbox = new Sandbox($this->root);

        $this->expectException(SandboxViolation::class);
        $sandbox->read('../etc/passwd');
    }

    public function testItRefusesAnAbsolutePathOutsideTheRoot(): void
    {
        $sandbox = new Sandbox($this->root);

        $this->expectException(SandboxViolation::class);
        $sandbox->read('/etc/passwd');
    }

    public function testItRefusesANullByteInAPath(): void
    {
        $sandbox = new Sandbox($this->root);

        $this->expectException(SandboxViolation::class);
        $sandbox->read("src/Example.php\0../../etc/passwd");
    }

    public function testItRefusesABinaryOutsideTheAllowlist(): void
    {
        $sandbox = new Sandbox($this->root);

        $result = $sandbox->run('curl', ['https://example.com']);

        self::assertSame(126, $result->exitCode);
        self::assertStringContainsString('not in the allowlist', $result->output);
    }

    public function testItRefusesACurlDisguisedAsAPath(): void
    {
        $sandbox = new Sandbox($this->root);

        // `rm -rf /` would be a shell string; `rm` is simply not allowlisted.
        $result = $sandbox->run('/bin/rm', ['-rf', '/']);

        self::assertSame(126, $result->exitCode);
    }

    public function testItRunsAnAllowlistedCommand(): void
    {
        $sandbox = new Sandbox($this->root);

        $result = $sandbox->run('php', ['-r', 'echo "hello";']);

        self::assertTrue($result->passed());
        self::assertSame('hello', $result->output);
    }
}
