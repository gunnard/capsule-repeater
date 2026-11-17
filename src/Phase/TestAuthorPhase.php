<?php

declare(strict_types=1);

namespace App\Phase;

use App\Agent\RepairAgent;
use App\Sandbox\Sandbox;
use App\Verdict\Verdict;

/**
 * The TDD gate: write the failing test before touching the source.
 *
 * "We can't verify a fix we can't reproduce." This phase ensures the
 * reproduction exists and fails on unpatched code before any patch is attempted.
 *
 * The test is written to a scratch path first, syntax-checked with `php -l`,
 * then placed under the detected test root. It is never allowed to overwrite
 * an existing test file.
 */
final class TestAuthorPhase
{
    public function __construct(
        private readonly RepairAgent $agent,
    ) {}

    /**
     * Attempt to author a reproduction test.
     *
     * @return TestAuthorResult
     */
    public function author(
        Sandbox $sandbox,
        string $issueText,
        string $testRoot,
        int $maxRetries = 1,
    ): TestAuthorResult {
        $attempt = 0;

        while ($attempt <= $maxRetries) {
            ++$attempt;

            // Ask the agent for a test, not a fix.
            $testCode = $this->agent->proposeTest($issueText, $this->detectFramework($sandbox));

            if ('' === trim($testCode)) {
                continue;
            }

            // Determine the test file path.
            $testFile = $this->resolveTestPath($testRoot, $testCode);

            if (null === $testFile) {
                continue;
            }

            // Never overwrite an existing test.
            if ($sandbox->exists($testFile)) {
                return new TestAuthorResult(
                    success: false,
                    testFile: $testFile,
                    testCode: $testCode,
                    reason: 'test_file_exists',
                    message: "Refused to overwrite existing test: {$testFile}",
                );
            }

            // Syntax check before placing.
            $scratchFile = sys_get_temp_dir().'/capsule-test-'.bin2hex(random_bytes(6)).'.php';
            file_put_contents($scratchFile, $testCode);

            $lint = $sandbox->run('php', ['-l', $scratchFile], 10);
            @unlink($scratchFile);

            if ($lint->failed()) {
                continue;
            }

            // Place the test.
            $sandbox->write($testFile, $testCode);

            // Prove the baseline: the test must FAIL on unpatched source.
            $baseline = $sandbox->run('php', [$testFile], 30);

            if ($baseline->failed()) {
                return new TestAuthorResult(
                    success: true,
                    testFile: $testFile,
                    testCode: $testCode,
                    reason: 'baseline_failed',
                    message: 'Reproduction test written and confirmed failing on unpatched source.',
                    baselineOutput: $baseline->output,
                );
            }

            // Test passed on unpatched source — the bug isn't reproduced.
            // Discard and retry.
            $sandbox->run('git', ['checkout', '--', $testFile], 10);
        }

        return new TestAuthorResult(
            success: false,
            testFile: null,
            testCode: null,
            reason: 'cannot_reproduce',
            message: 'Could not author a test that fails on the unpatched source.',
        );
    }

    /**
     * Detect the test framework used in the repository.
     */
    private function detectFramework(Sandbox $sandbox): string
    {
        if ($sandbox->exists('phpunit.xml') || $sandbox->exists('phpunit.xml.dist')) {
            return 'phpunit';
        }

        if ($sandbox->exists('pest.xml') || $sandbox->exists('tests/Pest.php')) {
            return 'pest';
        }

        if ($sandbox->exists('composer.json')) {
            $composer = json_decode($sandbox->read('composer.json'), true);
            $requireDev = $composer['require-dev'] ?? [];
            if (isset($requireDev['phpunit/phpunit'])) {
                return 'phpunit';
            }
            if (isset($requireDev['pestphp/pest'])) {
                return 'pest';
            }
        }

        return 'none';
    }

    /**
     * Extract the test file path from the generated test code.
     * Looks for a @file or namespace hint, or defaults to tests/ReproductionTest.php.
     */
    private function resolveTestPath(string $testRoot, string $testCode): ?string
    {
        // Look for a file path hint in the code (e.g., // File: tests/FooTest.php)
        if (1 === preg_match('/\/\/\s*File:\s*(.+\.php)/', $testCode, $matches)) {
            $path = trim($matches[1]);

            return str_starts_with($path, $testRoot) ? $path : $testRoot.'/'.basename($path);
        }

        // Default: tests/ReproductionTest.php
        return $testRoot.'/ReproductionTest.php';
    }
}