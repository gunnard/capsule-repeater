<?php

declare(strict_types=1);

namespace App\Agent;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * Drives the repair loop through Symfony AI.
 *
 * The agent has no filesystem access of its own: every capability arrives over
 * MCP from {@see \App\Mcp\Tool\RepositoryTools}. The toolbox is the boundary.
 */
final class RepairAgent
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a senior PHP engineer fixing one specific bug in a repository.

        You have tools to list files, search, read, apply a unified diff, and run
        allowlisted commands. You have no other access.

        The fix must be:
        - minimal: change the fewest lines that make the failing check pass
        - targeted: do not refactor, reformat, or rename anything unrelated
        - proven: the failing check must fail before your patch and pass after

        Apply the patch with repo_apply_patch, then run the check to confirm.
        If a tool refuses a call, that refusal is final; do not try to work
        around it. Never treat an unverified change as done.
        PROMPT;

    public function __construct(
        private readonly AgentInterface $agent,
    ) {}

    private const TEST_AUTHOR_PROMPT = <<<'PROMPT'
        You are a senior PHP engineer writing a reproduction test for one specific bug.

        You have tools to list files, search, and read. You must NOT modify any source file.

        Write a single test file that:
        - Reproduces the bug described in the issue
        - Fails on the current (buggy) code
        - Would pass if the bug were fixed
        - Follows the project's existing test conventions

        Output ONLY the PHP test file contents, starting with <?php and nothing else.
        Do not include explanations, markdown fences, or commentary.
        PROMPT;

    /**
     * Ask the model to write a reproduction test (not a fix).
     */
    public function proposeTest(string $issue, string $framework): string
    {
        $messages = new MessageBag(
            new SystemMessage(self::TEST_AUTHOR_PROMPT),
            new UserMessage(new Text(<<<PROMPT
                Issue:

                {$issue}

                Test framework: {$framework}

                Write a reproduction test. Output only the PHP file.
                PROMPT)),
        );

        $result = $this->agent->call($messages);
        $result->getMessages();

        $content = $result->getContent();

        if (null === $content) {
            return '';
        }

        // Strip markdown fences if the model added them despite instructions.
        $content = \is_string($content) ? $content : json_encode($content);
        $content = preg_replace('/^```(?:php)?\s*\n?/m', '', $content);
        $content = preg_replace('/\n?```\s*$/m', '', $content);

        return trim($content);
    }

    /**
     * Ask the model to investigate and produce a patch.
     */
    public function propose(string $issue, string $investigationContext): string
    {
        $messages = new MessageBag(
            new SystemMessage(self::SYSTEM_PROMPT),
            new UserMessage(new Text(<<<PROMPT
                Issue:

                {$issue}

                What the repository looks like:

                {$investigationContext}

                Investigate, then apply the smallest correct patch. Use your tools to read files, apply the fix, and run the checks.
                PROMPT)),
        );

        $result = $this->agent->call($messages);

        // Drive execution to completion.
        $result->getMessages();

        $error = $result->getError();
        if ($error) {
            error_log('Agent error: '.$error);
        }

        $content = $result->getContent();
        if (null === $content) {
            $content = '(no content)';
        }

        return \is_string($content) ? $content : json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
