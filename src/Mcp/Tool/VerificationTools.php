<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Sandbox\Sandbox;
use App\Verify\VerificationRunner;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Exposes the verification gate itself as an MCP tool.
 *
 * This is the "what it unlocked" half of the project: any MCP client — Claude
 * Code, Cursor, a CI runner, another agent — can hand a patch to the gate and
 * get back a machine-checkable verdict, without shelling out to the CLI. The
 * capability boundary stays the protocol.
 *
 * Scope is deliberately deterministic: the caller supplies the patch. Running
 * the model loop inside an MCP tool call would make the tool slow, expensive
 * and non-idempotent, which is the wrong shape for a tool.
 */
final class VerificationTools
{
    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly VerificationRunner $runner,
    ) {}

    /**
     * Run the differential proof against a patch and return the full verdict.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'verify_patch',
        title: 'Verify a patch (differential proof)',
        description: 'Run the Capsule Repeater gate on a patch: base must FAIL, head must PASS, revert must FAIL again. Returns a machine-checkable verdict with evidence, blast radius, cost and stated limits.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function verifyPatch(
        string $issue,
        string $testCommand,
        string $patch,
        int $maxBlastRadius = 0,
        float $maxCost = 0.0,
    ): array {
        $checks = $this->runner->parseTestCommand($testCommand);

        $evidence = $this->runner->verify(
            repoPath: $this->sandbox->root(),
            issueText: $issue,
            issueSource: 'mcp:verify_patch',
            checks: $checks,
            patchText: $patch,
            maxBlastRadius: $maxBlastRadius > 0 ? $maxBlastRadius : null,
            maxCost: $maxCost,
        );

        return $evidence->toArray();
    }
}
