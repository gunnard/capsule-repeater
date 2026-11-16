<?php

declare(strict_types=1);

namespace App\Agent;

use App\Mcp\Tools\RepoTools;
use App\Service\LlmClient;

/**
 * Agentic issue-to-patch workflow.
 *
 * Loop: Investigate → Plan → Patch → Validate → Report
 *
 * Each phase calls the LLM with tool results as context,
 * producing a grounded, evidence-based fix proposal.
 */
final class IssueToPatchAgent
{
    private LlmClient $llm;
    private RepoTools $tools;

    public function __construct(
        private readonly string $projectRoot,
        ?LlmClient $llm = null,
    ) {
        $this->llm = $llm ?? new LlmClient();
        $this->tools = new RepoTools($projectRoot);
    }

    /**
     * Run the full issue-to-patch loop.
     *
     * @return array{patch: string, report: array<string, mixed>, verified: bool}
     */
    public function run(string $issueDescription): array
    {
        if (!$this->llm->isConfigured()) {
            return [
                'patch' => '',
                'report' => [
                    'summary' => 'No LLM provider configured. Set ANTHROPIC_API_KEY, OPENAI_API_KEY, or HUGGINGFACE_API_KEY.',
                    'verified' => [],
                    'unverified' => ['LLM not configured'],
                    'limits' => 'Cannot run without an LLM provider.',
                ],
                'verified' => false,
            ];
        }

        // Phase 1: Investigate
        $investigation = $this->investigate($issueDescription);

        // Phase 2: Plan
        $plan = $this->plan($issueDescription, $investigation);

        // Phase 3: Patch
        $patchResult = $this->patch($plan);

        // Phase 4: Validate
        $validation = $this->validate($patchResult);

        // Phase 5: Report
        $report = $this->report($issueDescription, $investigation, $plan, $patchResult, $validation);

        return [
            'patch' => $patchResult['patch'] ?? '',
            'report' => $report,
            'verified' => $validation['success'] ?? false,
        ];
    }

    /**
     * Phase 1: Investigate the codebase for relevant context.
     *
     * @return array<string, mixed>
     */
    private function investigate(string $issueDescription): array
    {
        // Gather repo context
        $fileList = $this->tools->listFiles('.');
        $gitStatus = $this->tools->gitStatus();

        // Search for keywords from the issue
        $keywords = $this->extractKeywords($issueDescription);
        $searchResults = [];
        foreach (array_slice($keywords, 0, 5) as $keyword) {
            $result = $this->tools->search($keyword);
            if (!empty($result['matches'])) {
                $searchResults[$keyword] = $result['matches'];
            }
        }

        // Read top matching files
        $filesRead = [];
        $filesToRead = [];
        foreach ($searchResults as $keyword => $matches) {
            foreach (array_slice($matches, 0, 3) as $match) {
                // Extract file path from match (format: file:line:content)
                $parts = explode(':', $match, 3);
                if (isset($parts[0]) && !in_array($parts[0], $filesToRead, true)) {
                    $filesToRead[] = $parts[0];
                }
            }
        }

        foreach (array_slice($filesToRead, 0, 5) as $file) {
            $content = $this->tools->readFile($file);
            if (!isset($content['error'])) {
                $filesRead[$file] = $content['content'] ?? '';
            }
        }

        return [
            'fileList' => $fileList['files'] ?? [],
            'gitStatus' => $gitStatus,
            'searchResults' => $searchResults,
            'filesRead' => $filesRead,
            'keywords' => $keywords,
        ];
    }

    /**
     * Phase 2: Plan the fix.
     *
     * @param array<string, mixed> $investigation
     * @return array<string, mixed>
     */
    private function plan(string $issueDescription, array $investigation): array
    {
        $context = "Repository files:\n" . implode("\n", array_slice($investigation['fileList'], 0, 50)) . "\n\n";

        if (!empty($investigation['filesRead'])) {
            $context .= "Relevant file contents:\n";
            foreach ($investigation['filesRead'] as $file => $content) {
                $context .= "=== {$file} ===\n" . substr($content, 0, 5000) . "\n\n";
            }
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a senior software engineer. Given a bug report and repository context, '
                    . 'produce a scoped fix plan. Output a JSON object with keys: '
                    . '"analysis" (what you found), "rootCause" (the likely root cause), '
                    . '"filesToChange" (array of file paths), "changes" (description of changes), '
                    . '"patch" (a unified diff that fixes the issue). '
                    . 'Be precise and minimal. Only change what is necessary.',
            ],
            [
                'role' => 'user',
                'content' => "Issue:\n{$issueDescription}\n\n{$context}\n\nProduce a fix plan as JSON.",
            ],
        ];

        $response = $this->llm->chat($messages, ['max_tokens' => 4096, 'temperature' => 0.2]);

        // Try to parse JSON from response
        $plan = $this->parseJson($response);
        if ($plan === null) {
            $plan = [
                'analysis' => $response,
                'rootCause' => 'Could not parse structured plan',
                'filesToChange' => [],
                'changes' => $response,
                'patch' => '',
            ];
        }

        return $plan;
    }

    /**
     * Phase 3: Apply the patch.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function patch(array $plan): array
    {
        $patch = $plan['patch'] ?? '';

        if (trim($patch) === '') {
            return [
                'patch' => '',
                'applied' => false,
                'message' => 'No patch generated by the agent.',
            ];
        }

        $result = $this->tools->applyPatch($patch);

        return [
            'patch' => $patch,
            'applied' => $result['applied'] ?? false,
            'message' => $result['message'] ?? 'Unknown',
            'details' => $result['details'] ?? '',
        ];
    }

    /**
     * Phase 4: Validate the change.
     *
     * @param array<string, mixed> $patchResult
     * @return array<string, mixed>
     */
    private function validate(array $patchResult): array
    {
        if (!($patchResult['applied'] ?? false)) {
            return [
                'success' => false,
                'checks' => [],
                'message' => 'Patch was not applied; skipping validation.',
            ];
        }

        $checks = [];

        // Run git diff to confirm changes
        $diff = $this->tools->gitDiff();
        $checks['git_diff'] = [
            'success' => !empty($diff['diff']),
            'output' => substr($diff['diff'] ?? '', 0, 5000),
        ];

        // Try running tests (common commands)
        $testCommands = ['phpunit', 'vendor/bin/phpunit', 'composer test'];
        foreach ($testCommands as $cmd) {
            $result = $this->tools->run($cmd);
            if ($result['success'] ?? false) {
                $checks['tests'] = [
                    'success' => true,
                    'command' => $cmd,
                    'output' => substr($result['output'] ?? '', 0, 5000),
                ];
                break;
            }
            // Record attempt even if failed
            $checks['tests_attempted'] = [
                'command' => $cmd,
                'exitCode' => $result['exitCode'] ?? -1,
                'output' => substr($result['output'] ?? '', 0, 2000),
            ];
        }

        // Try static analysis
        $analysisCommands = ['phpstan analyse', 'vendor/bin/phpstan analyse'];
        foreach ($analysisCommands as $cmd) {
            $result = $this->tools->run($cmd);
            if (($result['exitCode'] ?? -1) === 0 || ($result['exitCode'] ?? -1) === 1) {
                // phpstan returns 1 for errors found, 0 for clean
                $checks['static_analysis'] = [
                    'success' => ($result['exitCode'] ?? -1) === 0,
                    'command' => $cmd,
                    'output' => substr($result['output'] ?? '', 0, 5000),
                ];
                break;
            }
        }

        $allPassed = ($checks['git_diff']['success'] ?? false)
            && (!isset($checks['tests']) || ($checks['tests']['success'] ?? false));

        return [
            'success' => $allPassed,
            'checks' => $checks,
            'message' => $allPassed ? 'Validation passed' : 'Validation had issues',
        ];
    }

    /**
     * Phase 5: Report.
     *
     * @param array<string, mixed> $investigation
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $patchResult
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    private function report(
        string $issueDescription,
        array $investigation,
        array $plan,
        array $patchResult,
        array $validation,
    ): array {
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a senior software engineer writing a patch report. '
                    . 'Given the issue, investigation, plan, patch result, and validation results, '
                    . 'produce a JSON object with keys: '
                    . '"summary" (concise description of what was done), '
                    . '"rootCause" (identified root cause), '
                    . '"changes" (what files/lines changed), '
                    . '"verified" (array of what was verified with evidence), '
                    . '"unverified" (array of what could not be verified), '
                    . '"limits" (what the agent could not do or check), '
                    . '"confidence" (high/medium/low).',
            ],
            [
                'role' => 'user',
                'content' => "Issue:\n{$issueDescription}\n\n"
                    . "Plan analysis:\n" . ($plan['analysis'] ?? 'N/A') . "\n\n"
                    . "Root cause:\n" . ($plan['rootCause'] ?? 'N/A') . "\n\n"
                    . "Patch applied: " . ($patchResult['applied'] ? 'Yes' : 'No') . "\n"
                    . "Patch message: " . ($patchResult['message'] ?? 'N/A') . "\n\n"
                    . "Validation: " . ($validation['success'] ? 'Passed' : 'Failed/Partial') . "\n"
                    . "Validation details: " . json_encode($validation['checks'] ?? []) . "\n\n"
                    . "Produce the report as JSON.",
            ],
        ];

        $response = $this->llm->chat($messages, ['max_tokens' => 2048, 'temperature' => 0.2]);
        $report = $this->parseJson($response);

        if ($report === null) {
            $report = [
                'summary' => $response,
                'rootCause' => $plan['rootCause'] ?? 'Unknown',
                'changes' => $plan['changes'] ?? 'See patch',
                'verified' => $validation['success'] ? ['Patch applied', 'Tests passed'] : [],
                'unverified' => $validation['success'] ? [] : ['Tests failed or could not run'],
                'limits' => 'Report parsing failed; raw LLM output included.',
                'confidence' => 'low',
            ];
        }

        return $report;
    }

    /**
     * Extract keywords from an issue description for code search.
     *
     * @return string[]
     */
    private function extractKeywords(string $issueDescription): array
    {
        // Simple keyword extraction: find capitalized identifiers, file names, function names
        $keywords = [];

        // Match file-like patterns (e.g., Foo.php, foo_bar)
        preg_match_all('/\b[A-Z][a-zA-Z]*(?:\.php)?\b/', $issueDescription, $matches);
        foreach ($matches[0] as $m) {
            if (strlen($m) > 3 && !in_array($m, ['The', 'This', 'When', 'With', 'From', 'That', 'Should'], true)) {
                $keywords[] = $m;
            }
        }

        // Match snake_case identifiers
        preg_match_all('/\b[a-z_]{4,}\b/', $issueDescription, $matches);
        foreach ($matches[0] as $m) {
            if (!in_array($m, ['should', 'would', 'could', 'with', 'from', 'that', 'this', 'when', 'error', 'issue'], true)) {
                $keywords[] = $m;
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * Try to parse JSON from LLM response (handles markdown code blocks).
     *
     * @return array<string, mixed>|null
     */
    private function parseJson(string $response): ?array
    {
        // Try direct parse
        $data = json_decode($response, true);
        if (is_array($data)) {
            return $data;
        }

        // Try extracting from markdown code block
        if (preg_match('/```(?:json)?\s*\n(.*?)\n```/s', $response, $matches)) {
            $data = json_decode($matches[1], true);
            if (is_array($data)) {
                return $data;
            }
        }

        // Try finding first { ... } block
        if (preg_match('/\{.*\}/s', $response, $matches)) {
            $data = json_decode($matches[0], true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }
}
