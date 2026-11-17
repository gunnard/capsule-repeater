<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Sandbox\Sandbox;
use PHPUnit\Framework\TestCase;

/**
 * Sandbox behaviour that a fix depends on, and that has broken before.
 */
final class SandboxPathTest extends TestCase
{
    private string $root;
    private Sandbox $sandbox;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/capsule-path-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o755, true);
        file_put_contents($this->root.'/src/Thing.php', "<?php\n");

        $this->sandbox = new Sandbox($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    /**
     * On macOS `/var` is a symlink to `/private/var`, so an un-normalised root
     * did not match the realpath()-ed paths `resolve()` returns. Anything that
     * stripped the root prefix then produced `/privatesrc/Thing.php`, which is
     * how every MCP list/read for an absolute path went wrong.
     */
    public function testTheRootIsNormalisedToARealPath(): void
    {
        $expected = realpath($this->root);

        self::assertSame($expected, $this->sandbox->root());
        self::assertStringStartsWith('/', $this->sandbox->root());
        self::assertStringEndsNotWith('/', $this->sandbox->root());
    }

    public function testARealtivePathResolvesUnderTheRoot(): void
    {
        $resolved = $this->sandbox->resolve('src/Thing.php');

        self::assertStringStartsWith($this->sandbox->root().'/', $resolved);
        self::assertStringEndsWith('/src/Thing.php', $resolved);
    }

    /**
     * A unified diff is only valid with its trailing newline. `trim()`-ing the
     * output of `git diff` produced diffs that `git apply` rejected as corrupt,
     * which made every model-generated patch unapplicable.
     */
    public function testGitDiffKeepsItsTrailingNewline(): void
    {
        exec(\sprintf(
            'cd %s && git init -q && git add -A && git -c user.email=t@localhost -c user.name=t commit -qm base',
            escapeshellarg($this->root),
        ));

        file_put_contents($this->root.'/src/Thing.php', "<?php\n// changed\n");

        $diff = $this->sandbox->run('git', ['diff'], 30)->output;

        self::assertNotSame('', trim($diff));
        self::assertStringEndsWith("\n", $diff, 'a diff without its trailing newline cannot be applied');

        // And the round trip must actually work.
        $patchFile = $this->root.'/../apply-'.bin2hex(random_bytes(4)).'.diff';
        file_put_contents($this->root.'/src/Thing.php', "<?php\n");
        file_put_contents($patchFile, $diff);

        $applied = $this->sandbox->run('git', ['apply', '--check', realpath($patchFile)], 30);
        @unlink($patchFile);

        self::assertTrue($applied->passed(), 'the captured diff must apply cleanly: '.$applied->output);
    }

    public function testItCreatesMissingDirectoriesForANewTest(): void
    {
        self::assertFalse($this->sandbox->exists('tests'));

        $this->sandbox->write('tests/ReproductionTest.php', "<?php\n");

        self::assertTrue($this->sandbox->exists('tests/ReproductionTest.php'));
    }

    public function testItRefusesToCreateDirectoriesOutsideTheSandbox(): void
    {
        $this->expectException(\App\Sandbox\SandboxViolation::class);

        $this->sandbox->mkdir('../escape');
    }

    public function testItCanRemoveAFileItCreated(): void
    {
        $this->sandbox->write('tests/Fresh.php', "<?php\n");
        $this->sandbox->remove('tests/Fresh.php');

        self::assertFalse($this->sandbox->exists('tests/Fresh.php'));
    }
}
