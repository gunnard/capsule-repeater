<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Sandbox\Sandbox;
use App\Sandbox\SandboxViolation;
use Mcp\Capability\Attribute\McpTool;

/**
 * The agent's entire capability surface, exposed as MCP tools.
 *
 * Every guardrail lives on the far side of the protocol: the model cannot reach
 * the filesystem, run a command, or apply a patch except through these methods.
 * That is what makes `BLOCKED` enforceable rather than aspirational.
 */
final class RepositoryTools
{
    public function __construct(private readonly Sandbox $sandbox) {}

    /**
     * List files in the repository, skipping vendor and VCS directories.
     *
     * @return array{files: list<string>, count: int}
     */
    #[McpTool(name: 'repo_list_files', title: 'List repository files')]
    public function listFiles(string $path = '.'): array
    {
        $directory = $this->sandbox->resolve($path);
        if (!is_dir($directory)) {
            return ['files' => [], 'count' => 0];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        $root = rtrim($this->sandbox->root(), '/').'/';

        foreach ($iterator as $file) {
            $relative = str_replace($root, '', $file->getPathname());

            if (1 === preg_match('#(^|/)(vendor|node_modules|\.git)(/|$)#', $relative)) {
                continue;
            }

            $files[] = $relative;

            if (\count($files) >= 400) {
                break;
            }
        }

        sort($files);

        return ['files' => $files, 'count' => \count($files)];
    }

    /**
     * Search repository contents for a regular expression.
     *
     * @return array{pattern: string, matches: list<string>, count: int}
     */
    #[McpTool(name: 'repo_search', title: 'Search repository contents')]
    public function search(string $pattern, string $path = '.'): array
    {
        // `rg` is the only search binary, and it is invoked formatively.
        $result = $this->sandbox->run('rg', [
            '--line-number',
            '--no-heading',
            '--color', 'never',
            '--glob', '!vendor/**',
            '--glob', '!.git/**',
            '--max-count', '40',
            $pattern,
            $path,
        ], 30);

        $matches = [];
        foreach (explode("\n", $result->output) as $line) {
            $line = trim($line);
            if ('' !== $line) {
                $matches[] = $line;
            }
        }

        return [
            'pattern' => $pattern,
            'matches' => $matches,
            'count' => \count($matches),
        ];
    }

    /**
     * Read a file from the repository.
     *
     * @return array{path: string, content: string}
     */
    #[McpTool(name: 'repo_read_file', title: 'Read a repository file')]
    public function readFile(string $path): array
    {
        $content = $this->sandbox->read($path);

        if (\strlen($content) > 60_000) {
            $content = substr($content, 0, 60_000)."\n… (truncated)";
        }

        return ['path' => $path, 'content' => $content];
    }

    /**
     * Replace the entire contents of an existing file.
     *
     * Unified diffs are a poor interface for a language model: it has to
     * reproduce surrounding context lines byte-for-byte, and one wrong space
     * makes the whole patch unapplicable even when the fix is correct. Writing
     * the file is what the model actually means, and the verifier judges the
     * resulting diff either way — so nothing is lost by making this easier.
     *
     * New files are refused: a fix creates a change to existing source, not
     * extra files, and an unbounded file-creation primitive is a blast radius.
     */
    #[McpTool(name: 'repo_write_file', title: 'Replace the contents of an existing file')]
    public function writeFile(string $path, string $content): array
    {
        try {
            $this->sandbox->resolve($path);
        } catch (SandboxViolation) {
            return ['written' => false, 'message' => \sprintf('Refused: %s escapes the sandbox.', $path)];
        }

        if (!$this->sandbox->exists($path)) {
            return [
                'written' => false,
                'message' => \sprintf('Refused: %s does not exist. This tool edits existing files only.', $path),
            ];
        }

        $before = $this->sandbox->read($path);

        if ($before === $content) {
            return ['written' => false, 'message' => 'No change: the file already has that content.'];
        }

        $this->sandbox->write($path, $content);

        return [
            'written' => true,
            'message' => 'File written.',
            'path' => $path,
        ];
    }

    /**
     * Get the current working-tree status.
     *
     * @return array{clean: bool, entries: list<string>}
     */
    #[McpTool(name: 'repo_git_status', title: 'Get git status')]
    public function gitStatus(): array
    {
        $result = $this->sandbox->run('git', ['status', '--porcelain'], 30);
        $entries = array_values(array_filter(
            array_map('trim', explode("\n", $result->output)),
            static fn (string $line): bool => '' !== $line,
        ));

        return ['clean' => [] === $entries, 'entries' => $entries];
    }

    /**
     * Get the current working-tree diff.
     *
     * @return array{diff: string, empty: bool}
     */
    #[McpTool(name: 'repo_git_diff', title: 'Get git diff')]
    public function gitDiff(): array
    {
        $result = $this->sandbox->run('git', ['diff'], 30);
        $diff = $result->output;

        if (\strlen($diff) > 40_000) {
            $diff = substr($diff, 0, 40_000)."\n… (truncated)";
        }

        return ['diff' => $diff, 'empty' => '' === trim($diff)];
    }

    /**
     * Apply a unified diff to the sandbox, refusing anything that touches
     * files outside the optional allowlist.
     *
     * @param list<string> $allowedPaths
     * @return array{applied: bool, message: string}
     */
    #[McpTool(name: 'repo_apply_patch', title: 'Apply a unified diff')]
    public function applyPatch(string $patch, array $allowedPaths = []): array
    {
        if ('' === trim($patch)) {
            return ['applied' => false, 'message' => 'Empty patch.'];
        }

        foreach ($this->touchedPaths($patch) as $path) {
            if ([] !== $allowedPaths && !\in_array($path, $allowedPaths, true)) {
                return [
                    'applied' => false,
                    'message' => \sprintf('Refused: patch touches %s, outside the allowed paths.', $path),
                ];
            }

            // A patch that reaches outside the sandbox is never applied.
            try {
                $this->sandbox->resolve($path);
            } catch (SandboxViolation) {
                return [
                    'applied' => false,
                    'message' => \sprintf('Refused: patch path escapes the sandbox (%s).', $path),
                ];
            }
        }

        $patchFile = tempnam(sys_get_temp_dir(), 'capsule_');
        file_put_contents($patchFile, $patch);

        try {
            // `--recount` forgives wrong hunk line counts, which is the most
            // common way a model-written diff is malformed: the change is right,
            // the @@ header is not. Forgiving that is not leniency about the fix,
            // because the differential proof still has to pass.
            $check = $this->sandbox->run('git', ['apply', '--recount', '--check', $patchFile], 30);
            if ($check->failed()) {
                return ['applied' => false, 'message' => 'Patch does not apply cleanly: '.$check->output];
            }

            $apply = $this->sandbox->run('git', ['apply', '--recount', $patchFile], 30);
            if ($apply->failed()) {
                return ['applied' => false, 'message' => 'Patch application failed: '.$apply->output];
            }

            return ['applied' => true, 'message' => 'Patch applied.'];
        } finally {
            @unlink($patchFile);
        }
    }

    /**
     * Run an allowlisted command in the sandbox.
     *
     * @param list<string> $arguments
     * @return array{command: string, exitCode: int, passed: bool, output: string}
     */
    #[McpTool(name: 'repo_run', title: 'Run an allowlisted command')]
    public function run(string $binary, array $arguments = []): array
    {
        $result = $this->sandbox->run($binary, $arguments);

        return [
            'command' => $result->command,
            'exitCode' => $result->exitCode,
            'passed' => $result->passed(),
            'output' => $result->toArray()['output'],
        ];
    }

    /**
     * Every file a unified diff claims to modify.
     *
     * @return list<string>
     */
    private function touchedPaths(string $patch): array
    {
        $paths = [];
        $lines = explode("\n", $patch);

        foreach ($lines as $line) {
            if (str_starts_with($line, '+++ ') || str_starts_with($line, '--- ')) {
                $path = trim(substr($line, 4));
                $path = preg_replace('#^(a|b)/#', '', $path) ?? $path;

                if ('/dev/null' !== $path && '' !== $path) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }
}
