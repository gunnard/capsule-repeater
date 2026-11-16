<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

/**
 * MCP toolset exposing sandboxed repository operations to the AI agent.
 *
 * All paths are confined to $projectRoot. Command execution is allowlisted.
 */
final class RepoTools
{
    /** @var string[] */
    private const ALLOWED_COMMANDS = [
        'phpunit', 'phpstan', 'phpcs', 'php-cs-fixer',
        'composer', 'symfony', 'php', 'git',
    ];

    /** @var string[] */
    private const DENIED_PATTERNS = [
        'rm ', 'rm\t', 'rmdir', 'del ', 'sudo', 'chmod', 'chown',
        'curl ', 'wget ', 'nc ', 'ssh ', 'scp ', '> ', '>> ',
        'eval(', 'exec(', 'system(', 'passthru(', 'shell_exec(',
    ];

    public function __construct(private readonly string $projectRoot) {}

    /**
     * Resolve a relative path safely within projectRoot.
     */
    private function resolve(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        $full = realpath($this->projectRoot . '/' . $relativePath);

        if ($full === false || !str_starts_with($full, realpath($this->projectRoot))) {
            throw new \RuntimeException("Path outside project root: {$relativePath}");
        }

        return $full;
    }

    /** @return array<string, mixed> */
    public function listFiles(string $path = '.'): array
    {
        $dir = $this->resolve($path);
        if (!is_dir($dir)) {
            return ['error' => 'Not a directory', 'path' => $path];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relative = str_replace($this->projectRoot . '/', '', $file->getPathname());
            // Skip vendor, .git, node_modules
            if (preg_match('#(^|/)(vendor|node_modules|\.git)(/|$)#', $relative)) {
                continue;
            }
            $files[] = $relative;
            if (count($files) >= 500) {
                break;
            }
        }

        return ['path' => $path, 'files' => $files, 'count' => count($files)];
    }

    /** @return array<string, mixed> */
    public function search(string $pattern, string $path = '.'): array
    {
        $dir = $this->resolve($path);
        $matches = [];

        // Use grep for efficient search
        $cmd = sprintf(
            'grep -rn --include="*.php" --include="*.yaml" --include="*.yml" --include="*.twig" --include="*.xml" --include="*.json" -E %s %s 2>/dev/null | head -50',
            escapeshellarg($pattern),
            escapeshellarg($dir)
        );

        exec($cmd, $output, $exitCode);

        foreach ($output as $line) {
            $relative = str_replace($this->projectRoot . '/', '', $line);
            $matches[] = $relative;
        }

        return ['pattern' => $pattern, 'matches' => $matches, 'count' => count($matches)];
    }

    /** @return array<string, mixed> */
    public function readFile(string $path): array
    {
        $file = $this->resolve($path);
        if (!is_file($file)) {
            return ['error' => 'File not found', 'path' => $path];
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return ['error' => 'Could not read file', 'path' => $path];
        }

        // Truncate very large files
        if (strlen($content) > 50000) {
            $content = substr($content, 0, 50000) . "\n... (truncated)";
        }

        return ['path' => $path, 'content' => $content, 'size' => filesize($file)];
    }

    /** @return array<string, mixed> */
    public function gitStatus(): array
    {
        $cmd = sprintf('cd %s && git status --porcelain 2>&1', escapeshellarg($this->projectRoot));
        exec($cmd, $output, $exitCode);

        $files = [];
        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $status = substr($line, 0, 2);
            $file = trim(substr($line, 2));
            $files[] = ['status' => $status, 'file' => $file];
        }

        return ['clean' => empty($files), 'files' => $files];
    }

    /** @return array<string, mixed> */
    public function gitDiff(): array
    {
        $cmd = sprintf('cd %s && git diff 2>&1', escapeshellarg($this->projectRoot));
        exec($cmd, $output, $exitCode);

        $diff = implode("\n", $output);
        if (strlen($diff) > 30000) {
            $diff = substr($diff, 0, 30000) . "\n... (truncated)";
        }

        return ['diff' => $diff];
    }

    /** @return array<string, mixed> */
    public function applyPatch(string $patch): array
    {
        if (trim($patch) === '') {
            return ['applied' => false, 'message' => 'Empty patch'];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'patch_');
        file_put_contents($tmpFile, $patch);

        $cmd = sprintf(
            'cd %s && git apply --check %s 2>&1',
            escapeshellarg($this->projectRoot),
            escapeshellarg($tmpFile)
        );
        exec($cmd, $checkOutput, $checkExit);

        if ($checkExit !== 0) {
            @unlink($tmpFile);
            return [
                'applied' => false,
                'message' => 'Patch does not apply cleanly',
                'details' => implode("\n", $checkOutput),
            ];
        }

        $cmd = sprintf(
            'cd %s && git apply %s 2>&1',
            escapeshellarg($this->projectRoot),
            escapeshellarg($tmpFile)
        );
        exec($cmd, $applyOutput, $applyExit);
        @unlink($tmpFile);

        return [
            'applied' => $applyExit === 0,
            'message' => $applyExit === 0 ? 'Patch applied successfully' : 'Patch application failed',
            'details' => implode("\n", $applyOutput),
        ];
    }

    /** @return array<string, mixed> */
    public function run(string $command): array
    {
        $command = trim($command);
        if ($command === '') {
            return ['error' => 'Empty command'];
        }

        // Check for denied patterns
        foreach (self::DENIED_PATTERNS as $pattern) {
            if (stripos($command, $pattern) !== false) {
                return ['error' => "Command contains denied pattern: {$pattern}"];
            }
        }

        // Check command is allowlisted
        $parts = preg_split('/\s+/', $command);
        $baseCommand = basename($parts[0]);
        if (!in_array($baseCommand, self::ALLOWED_COMMANDS, true)) {
            return [
                'error' => "Command not allowlisted: {$baseCommand}",
                'allowed' => self::ALLOWED_COMMANDS,
            ];
        }

        $cmd = sprintf('cd %s && %s 2>&1', escapeshellarg($this->projectRoot), $command);
        exec($cmd, $output, $exitCode);

        $result = implode("\n", $output);
        if (strlen($result) > 30000) {
            $result = substr($result, 0, 30000) . "\n... (truncated)";
        }

        return [
            'command' => $command,
            'exitCode' => $exitCode,
            'output' => $result,
            'success' => $exitCode === 0,
        ];
    }
}
