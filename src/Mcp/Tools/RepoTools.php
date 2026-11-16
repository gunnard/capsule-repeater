<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Mcp\Server\ToolInterface;

/**
 * MCP toolset exposing repository operations to the AI agent.
 *
 * All tools are sandboxed: reads/writes are confined to the project directory,
 * and command execution is restricted to an allowlist.
 */
final class RepoTools implements ToolInterface
{
    public function __construct(private readonly string $projectRoot) {}

    /** @return array<string, mixed> */
    public function listFiles(string $path = '.'): array
    {
        // TODO: implement directory listing within projectRoot
        return ['path' => $path, 'files' => []];
    }

    /** @return array<string, mixed> */
    public function search(string $pattern, string $path = '.'): array
    {
        // TODO: implement ripgrep-based search within projectRoot
        return ['pattern' => $pattern, 'matches' => []];
    }

    /** @return array<string, mixed> */
    public function readFile(string $path): array
    {
        // TODO: implement safe file read within projectRoot
        return ['path' => $path, 'content' => ''];
    }

    /** @return array<string, mixed> */
    public function gitStatus(): array
    {
        // TODO: implement git status
        return ['clean' => true, 'files' => []];
    }

    /** @return array<string, mixed> */
    public function gitDiff(): array
    {
        // TODO: implement git diff
        return ['diff' => ''];
    }

    /** @return array<string, mixed> */
    public function applyPatch(string $patch): array
    {
        // TODO: implement sandboxed patch application
        return ['applied' => false, 'message' => 'Not yet implemented'];
    }

    /** @return array<string, mixed> */
    public function run(string $command): array
    {
        // TODO: implement allowlisted command execution
        return ['command' => $command, 'exitCode' => -1, 'output' => 'Not yet implemented'];
    }
}
