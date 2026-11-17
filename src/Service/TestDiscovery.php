<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Discovers how to test a repository.
 *
 * Scans the repository root for known test configuration files and returns
 * the appropriate test command. This removes the friction of requiring the
 * user to know and pass --test-command manually.
 */
final class TestDiscovery
{
    /**
     * Known test configuration patterns and their commands.
     *
     * @var array<string, array{file: string, command: string}>
     */
    private const PATTERNS = [
        'phpunit' => ['file' => 'phpunit.xml', 'command' => 'php vendor/bin/phpunit'],
        'phpunit_dist' => ['file' => 'phpunit.xml.dist', 'command' => 'php vendor/bin/phpunit'],
        'pest' => ['file' => 'tests/Pest.php', 'command' => 'php vendor/bin/pest'],
        'composer' => ['file' => 'composer.json', 'command' => null], // resolved dynamically
    ];

    /**
     * Discover the test command for a repository.
     *
     * @return string|null  the test command, or null if nothing found
     */
    public function discover(\App\Sandbox\Sandbox $sandbox): ?string
    {
        // Self-contained check script (dependency-free, preferred for demos)
        if ($sandbox->exists('check.php')) {
            return 'php check.php';
        }

        // PHPUnit
        if ($sandbox->exists('phpunit.xml')) {
            return 'php vendor/bin/phpunit';
        }

        if ($sandbox->exists('phpunit.xml.dist')) {
            return 'php vendor/bin/phpunit';
        }

        // Pest
        if ($sandbox->exists('tests/Pest.php') || $sandbox->exists('Pest.php')) {
            return 'php vendor/bin/pest';
        }

        // Composer scripts
        if ($sandbox->exists('composer.json')) {
            $composer = json_decode($sandbox->read('composer.json'), true);
            $scripts = $composer['scripts'] ?? [];
            if (isset($scripts['test'])) {
                return 'composer test';
            }
        }

        // Package.json scripts (Node/JS projects)
        if ($sandbox->exists('package.json')) {
            $package = json_decode($sandbox->read('package.json'), true);
            $scripts = $package['scripts'] ?? [];
            if (isset($scripts['test'])) {
                // Check for Playwright
                if (isset($scripts['test:e2e']) || isset($scripts['test:playwright'])) {
                    return 'npm run test:e2e';
                }

                return 'npm test';
            }
        }

        // Playwright config
        if ($sandbox->exists('playwright.config.ts') || $sandbox->exists('playwright.config.js')) {
            return 'npx playwright test';
        }

        // CI workflow (GitHub Actions) - extract first test-like run step
        $ciCommand = $this->discoverFromCi($sandbox);
        if (null !== $ciCommand) {
            return $ciCommand;
        }

        return null;
    }

    /**
     * Try to extract a test command from GitHub Actions workflows.
     */
    private function discoverFromCi(\App\Sandbox\Sandbox $sandbox): ?string
    {
        // We can't glob in the sandbox easily, so check common paths.
        $workflowPaths = [
            '.github/workflows/ci.yml',
            '.github/workflows/tests.yml',
            '.github/workflows/test.yml',
            '.github/workflows/php.yml',
        ];

        foreach ($workflowPaths as $path) {
            if (!$sandbox->exists($path)) {
                continue;
            }

            $content = $sandbox->read($path);
            // Look for common test commands in run: steps.
            if (preg_match('/run:\s*(.+phpunit.+)/m', $content, $matches)) {
                return trim($matches[1]);
            }

            if (preg_match('/run:\s*(.+pest.+)/m', $content, $matches)) {
                return trim($matches[1]);
            }

            if (preg_match('/run:\s*(composer test.+)/m', $content, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Human-readable description of what was discovered.
     */
    public function describe(\App\Sandbox\Sandbox $sandbox): string
    {
        $command = $this->discover($sandbox);

        if (null === $command) {
            return 'No test configuration found.';
        }

        return "Discovered: {$command}";
    }
}
