<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Mcp\Tools\RepoTools;

/**
 * MCP server bootstrap for Capsule Repeater.
 *
 * Exposes RepoTools as MCP tools over JSON-RPC (stdio or HTTP transport).
 *
 * When the PHP MCP SDK (mcp/sdk) is installed, this class registers
 * tools with the SDK's server. Otherwise it provides a minimal
 * JSON-RPC 2.0 handler that can be used for testing/development.
 *
 * @see https://modelcontextprotocol.io/specification
 */
final class McpServer
{
    /** @var array<string, callable> */
    private array $tools = [];

    /** @var array<string, array<string, mixed>> */
    private array $toolSchemas = [];

    public function __construct(
        private readonly RepoTools $repoTools,
        private readonly string $serverName = 'capsule-repeater',
        private readonly string $serverVersion = '0.1.0',
    ) {
        $this->registerTools();
    }

    private function registerTools(): void
    {
        $this->addTool('repo.list_files', 'List files in the repository (sandboxed)', [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Relative directory path', 'default' => '.'],
            ],
        ], fn (array $args) => $this->repoTools->listFiles($args['path'] ?? '.'));

        $this->addTool('repo.search', 'Search repository contents with a regex pattern', [
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string', 'description' => 'Regex pattern to search for'],
                'path' => ['type' => 'string', 'description' => 'Relative directory to search in', 'default' => '.'],
            ],
            'required' => ['pattern'],
        ], fn (array $args) => $this->repoTools->search($args['pattern'], $args['path'] ?? '.'));

        $this->addTool('repo.read_file', 'Read a file from the repository (sandboxed)', [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Relative file path'],
            ],
            'required' => ['path'],
        ], fn (array $args) => $this->repoTools->readFile($args['path']));

        $this->addTool('repo.git_status', 'Get git status of the repository', [
            'type' => 'object',
            'properties' => [],
        ], fn () => $this->repoTools->gitStatus());

        $this->addTool('repo.git_diff', 'Get the current git diff', [
            'type' => 'object',
            'properties' => [],
        ], fn () => $this->repoTools->gitDiff());

        $this->addTool('repo.apply_patch', 'Apply a unified diff patch to the repository', [
            'type' => 'object',
            'properties' => [
                'patch' => ['type' => 'string', 'description' => 'Unified diff patch content'],
            ],
            'required' => ['patch'],
        ], fn (array $args) => $this->repoTools->applyPatch($args['patch']));

        $this->addTool('repo.run', 'Run an allowlisted command (phpunit, phpstan, etc.)', [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => 'Command to execute'],
            ],
            'required' => ['command'],
        ], fn (array $args) => $this->repoTools->run($args['command']));
    }

    /**
     * @param array<string, mixed> $schema
     * @param callable(array<string, mixed>): array<string, mixed> $handler
     */
    private function addTool(string $name, string $description, array $schema, callable $handler): void
    {
        $this->tools[$name] = $handler;
        $this->toolSchemas[$name] = [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function listTools(): array
    {
        return $this->toolSchemas;
    }

    /**
     * Handle a JSON-RPC 2.0 request.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handleRequest(array $request): array
    {
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        return match ($method) {
            'initialize' => $this->handleInitialize($id),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolCall($id, $params),
            default => [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32601, 'message' => "Method not found: {$method}"],
            ],
        };
    }

    /** @return array<string, mixed> */
    private function handleInitialize(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => [
                    'name' => $this->serverName,
                    'version' => $this->serverVersion,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function handleToolsList(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => array_values($this->toolSchemas)],
        ];
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed> */
    private function handleToolCall(mixed $id, array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$toolName])) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32602, 'message' => "Unknown tool: {$toolName}"],
            ];
        }

        try {
            $result = ($this->tools[$toolName])($arguments);
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT)],
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => json_encode(['error' => $e->getMessage()])],
                    ],
                    'isError' => true,
                ],
            ];
        }
    }

    /**
     * Run as a stdio MCP server (for Claude Code / MCP clients).
     */
    public function runStdio(): void
    {
        $stdin = fopen('php://stdin', 'r');
        $stdout = fopen('php://stdout', 'w');

        while (!feof($stdin)) {
            $line = fgets($stdin);
            if ($line === false || trim($line) === '') {
                continue;
            }

            $request = json_decode(trim($line), true);
            if (!is_array($request)) {
                continue;
            }

            $response = $this->handleRequest($request);
            fwrite($stdout, json_encode($response) . "\n");
            fflush($stdout);
        }
    }
}
