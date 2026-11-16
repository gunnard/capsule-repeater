<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Symfony AI agent that orchestrates the issue-to-patch workflow.
 *
 * Investigate → Plan → Patch → Validate → Report
 *
 * Uses MCP-exposed repo tools for grounded code investigation and validation.
 */
final class IssueToPatchAgent
{
    public function __construct(
        private readonly string $systemPrompt,
    ) {}

    /**
     * Run the full issue-to-patch loop.
     *
     * @return array{patch: string, report: array<string, mixed>}
     */
    public function run(string $issueDescription): array
    {
        // TODO: wire up Symfony AI agent with MCP tool providers
        // 1. Investigate: search/read repo via MCP tools
        // 2. Plan: generate scoped fix plan
        // 3. Patch: apply changes to sandboxed work copy
        // 4. Validate: run allowlisted tests/checks
        // 5. Report: unified diff + verified/unverified summary
        return [
            'patch' => '',
            'report' => [
                'summary' => 'Not yet implemented',
                'verified' => [],
                'unverified' => [],
                'limits' => 'Agent not yet wired',
            ],
        ];
    }
}
