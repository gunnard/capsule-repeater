<?php

declare(strict_types=1);

namespace App\Sandbox;

use App\Verdict\CheckResult;

/**
 * A disposable, path-confined working copy of the repository under verification.
 *
 * Everything the agent can touch goes through here. The agent never sees the
 * original tree: {@see Sandbox::copy()} makes a throwaway clone, so a bad patch
 * cannot damage the repository and a revert is always possible.
 *
 * The copy is a `git clone` plus a `git apply`/`git checkout` cycle, which means
 * reverting is exact rather than best-effort: it restores the committed tree,
 * including files the patch deleted.
 */
final class Sandbox
{
    /** Binaries the agent may run. Anything else is refused by name. */
    private const ALLOWED_BINARIES = ['php', 'composer', 'git', 'rg', 'node', 'npx', 'npm'];

    public function __construct(
        private string $root,
    ) {
        // A runtime override lets a spawned MCP server target a specific
        // disposable copy for one agent run, without recompiling the container.
        $override = getenv('CAPSULE_AGENT_SANDBOX');
        if (false !== $override && '' !== trim($override)) {
            $root = $override;
        }

        // An empty root is not "the current directory" — it is an unconfigured
        // sandbox, and the only safe thing to do with one is refuse.
        if ('' === trim($root)) {
            throw new SandboxViolation('No sandbox root configured. Set CAPSULE_SANDBOX to the repository to operate on.');
        }

        if (!is_dir($root)) {
            throw new SandboxViolation(\sprintf('Sandbox root is not a directory: %s', $root));
        }

        // Normalise once, here. `resolve()` returns realpath()-ed paths, and on
        // macOS `/var` is a symlink to `/private/var`; storing the un-normalised
        // root meant every "strip the root prefix" computation missed by
        // `/private` and produced paths like `/privatesrc/Foo.php`.
        $real = realpath($root);
        if (false === $real) {
            throw new SandboxViolation(\sprintf('Sandbox root is not readable: %s', $root));
        }

        $this->root = rtrim($real, '/');
    }

    /**
     * Create a disposable copy of a repository.
     *
     * The source is copied with its uncommitted changes, so a run verifies what
     * the developer actually has in front of them rather than the last commit.
     */
    public static function copy(string $source): self
    {
        $source = realpath($source);
        if (false === $source || !is_dir($source)) {
            throw new SandboxViolation(\sprintf('Not a directory: %s', $source));
        }

        $target = sys_get_temp_dir().'/capsule-sandbox-'.bin2hex(random_bytes(8));

        // The target must exist before the sandbox is constructed, since the
        // constructor refuses to point at a directory that isn't there.
        if (!mkdir($target, 0o755, true) && !is_dir($target)) {
            throw new SandboxViolation(\sprintf('Could not create the sandbox at %s', $target));
        }

        // Copy the whole tree. `vendor` is copied rather than symlinked on
        // purpose: Composer's autoloader resolves paths relative to its own real
        // location, so a symlink would point the tests back at the original,
        // unpatched source.
        $copy = new self($target);

        $result = $copy->runHost('rsync', [
            '-a',
            '--exclude', '.git',
            '--exclude', '.phpunit.cache',
            $source.'/',
            $target.'/',
        ]);

        if ($result->failed()) {
            throw new SandboxViolation('Could not copy the repository: '.$result->output);
        }

        // A fresh git repository makes `git apply` and `git checkout -- .` exact.
        $copy->runHost('git', ['init', '-q', $target]);
        $copy->runHost('git', ['-C', $target, 'add', '-A']);
        $copy->runHost('git', [
            '-C', $target,
            '-c', 'user.email=capsule@localhost',
            '-c', 'user.name=Capsule',
            'commit', '-q', '-m', 'sandbox baseline',
        ]);

        return $copy;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Remove the working copy. Best effort: a sandbox that cannot be cleaned up
     * is a leaked temp directory, not a failed verification.
     */
    public function destroy(): void
    {
        if (!is_dir($this->root) || !str_contains($this->root, 'capsule-sandbox-')) {
            return;
        }

        exec('rm -rf '.escapeshellarg($this->root));
    }

    /**
     * Resolve a path inside the sandbox, refusing anything that escapes it.
     */
    public function resolve(string $relative): string
    {
        $relative = ltrim($relative, '/');

        if (str_contains($relative, "\0")) {
            throw new SandboxViolation('Path contains a null byte.');
        }

        $candidate = $this->root.'/'.$relative;
        $real = realpath($candidate);

        // A path that does not exist yet resolves against its parent directory.
        if (false === $real) {
            $parent = realpath(\dirname($candidate));
            if (false === $parent) {
                throw new SandboxViolation(\sprintf('Path does not exist: %s', $relative));
            }
            $real = $parent.'/'.basename($candidate);
        }

        $rootReal = realpath($this->root);
        if (false === $rootReal) {
            throw new SandboxViolation('Sandbox root is not readable.');
        }

        if ($real !== $rootReal && !str_starts_with($real, $rootReal.'/')) {
            throw new SandboxViolation(\sprintf('Path escapes the sandbox: %s', $relative));
        }

        return $real;
    }

    public function read(string $relative): string
    {
        $path = $this->resolve($relative);
        if (!is_file($path)) {
            throw new SandboxViolation(\sprintf('Not a file: %s', $relative));
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new SandboxViolation(\sprintf('Unreadable: %s', $relative));
        }

        return $contents;
    }

    public function write(string $relative, string $contents): void
    {
        // A new test usually lands in a directory that does not exist yet
        // (`tests/`), so create it rather than refusing to write.
        $this->mkdir(\dirname($relative));

        $path = $this->resolve($relative);
        if (false === file_put_contents($path, $contents)) {
            throw new SandboxViolation(\sprintf('Unwritable: %s', $relative));
        }
    }

    /**
     * Create a directory inside the sandbox, including any missing parents.
     *
     * Each segment is resolved as it is created, so a symlink planted mid-path
     * cannot be used to walk out of the sandbox.
     */
    public function mkdir(string $relative): void
    {
        $relative = ltrim($relative, '/');

        if ('' === $relative || '.' === $relative) {
            return;
        }

        if (str_contains($relative, "\0")) {
            throw new SandboxViolation('Path contains a null byte.');
        }

        $real = realpath($this->root);
        if (false === $real) {
            throw new SandboxViolation('Sandbox root is not readable.');
        }

        foreach (explode('/', $relative) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                throw new SandboxViolation(\sprintf('Path escapes the sandbox: %s', $relative));
            }

            $real .= '/'.$segment;

            if (!is_dir($real) && !mkdir($real, 0o755) && !is_dir($real)) {
                throw new SandboxViolation(\sprintf('Could not create directory: %s', $relative));
            }

            $resolved = realpath($real);
            $rootReal = realpath($this->root);
            if (false === $resolved || false === $rootReal) {
                throw new SandboxViolation(\sprintf('Path does not exist: %s', $relative));
            }

            if ($resolved !== $rootReal && !str_starts_with($resolved, $rootReal.'/')) {
                throw new SandboxViolation(\sprintf('Path escapes the sandbox: %s', $relative));
            }

            $real = $resolved;
        }
    }

    /**
     * Delete a file inside the sandbox.
     *
     * Needed to discard an authored test: `git checkout --` cannot undo a file
     * that was never tracked, which is exactly what a fresh test always is.
     */
    public function remove(string $relative): void
    {
        $path = $this->resolve($relative);

        if (is_file($path) && !unlink($path)) {
            throw new SandboxViolation(\sprintf('Could not remove: %s', $relative));
        }
    }

    public function exists(string $relative): bool
    {
        try {
            return file_exists($this->resolve($relative));
        } catch (SandboxViolation) {
            return false;
        }
    }

    /**
     * Run a command inside the sandbox with an allowlisted binary.
     *
     * The command is split into a binary plus arguments; we never pass the
     * string to a shell, so quoting cannot be used to smuggle a second command.
     *
     * @param list<string> $arguments
     */
    public function run(string $binary, array $arguments = [], int $timeoutSeconds = 120): CheckResult
    {
        $command = $this->describeCommand($binary, $arguments);

        if (!self::isAllowedBinary($binary)) {
            return new CheckResult($command, 126, 'Refused: binary not in the allowlist.');
        }

        return $this->execute($binary, $arguments, $timeoutSeconds, $command);
    }

    /**
     * Run a binary the agent is not allowed to run, for sandbox bookkeeping only.
     *
     * @param list<string> $arguments
     */
    private function runHost(string $binary, array $arguments): CheckResult
    {
        return $this->execute($binary, $arguments, 120, $this->describeCommand($binary, $arguments));
    }

    /**
     * @param list<string> $arguments
     */
    private function execute(string $binary, array $arguments, int $timeoutSeconds, string $command): CheckResult
    {
        $process = @proc_open(
            [$binary, ...$arguments],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->root,
        );

        if (!\is_resource($process)) {
            return new CheckResult($command, 127, 'Failed to start the process.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                $output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                $output .= "\n[timed out after {$timeoutSeconds}s]";
                break;
            }

            usleep(10_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Do NOT trim: a unified diff is only valid with its trailing newline,
        // and `git apply` reports a stripped diff as "corrupt patch". Consumers
        // that want a trimmed value trim it themselves.
        return new CheckResult($command, $exitCode, $output);
    }

    /**
     * Binaries the agent is allowed to run. Anything else is refused by name,
     * not sanitized — a refusal is evidence, not an error to work around.
     */
    private static function isAllowedBinary(string $binary): bool
    {
        return \in_array(basename($binary), self::ALLOWED_BINARIES, true);
    }

    /** @param list<string> $arguments */
    private function describeCommand(string $binary, array $arguments): string
    {
        return implode(' ', [basename($binary), ...$arguments]);
    }
}
