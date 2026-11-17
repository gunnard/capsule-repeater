<?php

declare(strict_types=1);

namespace App\Phase;

use App\Agent\RepairAgent;
use App\Sandbox\Sandbox;

/**
 * The TDD gate: write the failing test before touching the source.
 *
 * "We can't verify a fix we can't reproduce." A patch is only meaningful
 * relative to a failing test, and a model that could not reproduce the bug has
 * no business fixing it.
 *
 * Two rules keep this honest, and both were learned the hard way:
 *
 * 1. The baseline runs through the **real runner**. Executing a PHPUnit file
 *    with `php file.php` dies on `Class TestCase not found`, which looks like a
 *    failure and would certify a test that can never pass. A baseline that
 *    fails for the wrong reason is worse than no baseline.
 * 2. A test that fails because it cannot run is rejected outright. Only a run
 *    that produced real output counts as a reproduction.
 *
 * The authored test is never merged into the patch: the verifier installs it
 * in the sandbox as a prelude, so it exists in the base, head and revert phases
 * while the patch under test stays a pure source change.
 */
final class TestAuthorPhase
{
    /** Output that means "could not run", not "the bug is present". */
    private const UNRUNNABLE = [
        'Fatal error',
        'Parse error',
        'syntax error, unexpected',
        'Class "PHPUnit',
        'Failed opening required',
        'Could not open input file',
    ];

    public function __construct(
        private readonly RepairAgent $agent,
    ) {}

    public function author(
        Sandbox $sandbox,
        string $issueText,
        string $testRoot,
        string $testCommand = '',
        int $maxRetries = 1,
    ): TestAuthorResult {
        $framework = $this->detectFramework($sandbox);

        if (null === $framework) {
            return new TestAuthorResult(
                success: false,
                testFile: null,
                reproduction: null,
                reason: 'no_test_runner',
                message: 'No test runner is installed in the repository, so an authored test could not be run.',
            );
        }

        $attempt = 0;
        $lastReason = 'cannot_reproduce';

        while ($attempt <= $maxRetries) {
            ++$attempt;

            $testCode = $this->agent->proposeTest($issueText, $framework['label'], $this->repoHints($sandbox));

            if ('' === trim($testCode)) {
                $lastReason = 'empty_response';

                continue;
            }

            $testFile = $this->resolveTestPath($testRoot, $testCode);

            // A reproduction is additive. Overwriting an existing test would let
            // the authoring phase silently rewrite the specification.
            if ($sandbox->exists($testFile)) {
                return new TestAuthorResult(
                    success: false,
                    testFile: $testFile,
                    reproduction: null,
                    reason: 'test_file_exists',
                    message: \sprintf('Refused to overwrite the existing test %s.', $testFile),
                );
            }

            $sandbox->write($testFile, $testCode);

            $checks = $framework['command']($testFile);
            $baseline = $this->runChecks($sandbox, $checks);

            if ($this->isRunnableFailure($baseline['output'])) {
                // It "failed", but not because it reproduced anything.
                $sandbox->remove($testFile);

                return new TestAuthorResult(
                    success: false,
                    testFile: $testFile,
                    reproduction: null,
                    reason: 'test_does_not_run',
                    message: \sprintf(
                        'The authored test could not be executed, so it proves nothing. %s',
                        $this->excerpt($baseline['output']),
                    ),
                    baselineOutput: $baseline['output'],
                );
            }

            if ($baseline['failed']) {
                return new TestAuthorResult(
                    success: true,
                    testFile: $testFile,
                    reproduction: new Reproduction(
                        path: $testFile,
                        contents: $testCode,
                        checks: $checks,
                        baselineOutput: $baseline['output'],
                    ),
                    reason: 'baseline_failed',
                    message: \sprintf('Reproduction written to %s and confirmed failing on unpatched source.', $testFile),
                    baselineOutput: $baseline['output'],
                );
            }

            // It passed on buggy code: the test does not reproduce the bug.
            // Remove it — `git checkout` cannot undo an untracked file.
            $sandbox->remove($testFile);
            $lastReason = 'test_passed_on_buggy_code';
        }

        return new TestAuthorResult(
            success: false,
            testFile: null,
            reproduction: null,
            reason: $lastReason,
            message: 'Could not author a test that fails on the unpatched source.',
        );
    }

    /**
     * Run the reproduction exactly as the project would.
     *
     * @param list<array{0: string, 1: list<string>}> $checks
     *
     * @return array{failed: bool, output: string}
     */
    private function runChecks(Sandbox $sandbox, array $checks): array
    {
        $failed = false;
        $output = '';

        foreach ($checks as [$binary, $arguments]) {
            $result = $sandbox->run($binary, $arguments);
            $output .= ($output !== '' ? "\n" : '').$result->output;

            if ($result->failed()) {
                $failed = true;
            }
        }

        return ['failed' => $failed, 'output' => $output];
    }

    /**
     * A failure caused by the test not being executable is not a reproduction.
     */
    private function isRunnableFailure(string $output): bool
    {
        foreach (self::UNRUNNABLE as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The line that explains a failure, so a refusal is diagnosable.
     */
    private function excerpt(string $output): string
    {
        foreach (self::UNRUNNABLE as $needle) {
            foreach (explode("\n", $output) as $line) {
                if (str_contains($line, $needle)) {
                    return trim($line);
                }
            }
        }

        $first = trim(strtok($output, "\n") ?: '');

        return '' === $first ? '' : $first;
    }

    /**
     * How this repository runs one test file, or null when it cannot.
     *
     * @return array{label: string, command: callable(string): list<array{0: string, 1: list<string>}>}|null
     */
    private function detectFramework(Sandbox $sandbox): ?array
    {
        if ($sandbox->exists('vendor/bin/phpunit')) {
            return [
                'label' => 'PHPUnit',
                'command' => static fn (string $file): array => [['php', ['vendor/bin/phpunit', '--do-not-cache-result', $file]]],
            ];
        }

        if ($sandbox->exists('vendor/bin/pest')) {
            return [
                'label' => 'Pest',
                'command' => static fn (string $file): array => [['php', ['vendor/bin/pest', $file]]],
            ];
        }

        // A dependency-free repository: its checks are plain PHP scripts that
        // exit non-zero on failure, so the authored test must do the same.
        if ($sandbox->exists('check.php')) {
            return [
                'label' => 'plain PHP (there is no vendor/ directory, so PHPUnit is NOT available). '
                    .'Copy the style of the existing check script below: require the source file '
                    .'directly, compare values, and exit(1) with a message on failure. '
                    .'Do NOT extend TestCase and do NOT use assertions.',
                'command' => static fn (string $file): array => [['php', [$file]]],
            ];
        }

        return null;
    }

    /**
     * A short description of the repository, so the model writes something that
     * fits rather than something plausible.
     */
    private function repoHints(Sandbox $sandbox): string
    {
        $parts = [];

        if ($sandbox->exists('check.php')) {
            // The existing check is the strongest possible hint: it shows the
            // project's own conventions, its require paths, and its output style.
            $parts[] = "The repository's existing check script (follow this style exactly):\n\n```php\n"
                .$this->head($sandbox->read('check.php'), 45)
                ."\n```";
        }

        $listing = $this->fileListing($sandbox);
        $parts[] = "Files:\n\n".$listing;

        return implode("\n\n", $parts);
    }

    /**
     * The first N lines of a file, so a prompt stays a reasonable size.
     */
    private function head(string $contents, int $lines): string
    {
        $all = explode("\n", $contents);

        if (\count($all) <= $lines) {
            return $contents;
        }

        return implode("\n", \array_slice($all, 0, $lines))."\n// … (truncated)";
    }

    private function fileListing(Sandbox $sandbox): string
    {
        $root = $sandbox->root();
        $listing = [];
        $excluded = ['vendor', 'node_modules', '.git', '.phpunit.cache'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file) use ($excluded): bool {
                    if (!$file->isDir()) {
                        return true;
                    }

                    return !\in_array($file->getFilename(), $excluded, true);
                },
            ),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $listing[] = str_replace(rtrim($root, '/').'/', '', $file->getPathname());

            if (\count($listing) >= 40) {
                break;
            }
        }

        sort($listing);

        return [] === $listing ? '(no file listing available)' : implode("\n", $listing);
    }

    /**
     * Where the new test goes. Never an arbitrary path the model invented, and
     * always inside the test root.
     */
    private function resolveTestPath(string $testRoot, string $testCode): string
    {
        $testRoot = trim($testRoot, '/');

        if (1 === preg_match('#//\s*File:\s*(\S+\.php)#', $testCode, $matches)) {
            $candidate = ltrim(trim($matches[1]), '/');

            // Honour a model-suggested path only when it stays in the test root.
            if (str_starts_with($candidate, $testRoot.'/') && !str_contains($candidate, '..')) {
                return $candidate;
            }
        }

        return $testRoot.'/ReproductionTest.php';
    }
}
