<?php

declare(strict_types=1);

namespace App\Command;

use App\Agent\RepairAgent;
use App\Phase\TestAuthorPhase;
use App\Sandbox\Sandbox;
use App\Service\TestDiscovery;
use App\Verify\VerificationRunner;
use App\Verdict\CheckResult;
use App\Verdict\Evidence;
use App\Verdict\Verdict;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verify that a patch actually fixes what it claims to fix.
 *
 * Exit codes are the interface: 0 proven, 1 unproven, 2 not reproducible,
 * 3 blocked. A CI job reads the code, not the prose.
 */
#[AsCommand(name: 'check', description: 'Verify a patch against a reproduction')]
final class CheckCommand
{
    public function __construct(
        private readonly VerificationRunner $runner,
        private readonly ?RepairAgent $repairAgent = null,
        private readonly ?TestAuthorPhase $testAuthor = null,
        private readonly ?TestDiscovery $testDiscovery = null,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Repository to verify', shortcut: 'r')]
        ?string $repo = null,
        #[Option(description: 'Path to the issue description (auto-detected if omitted)', shortcut: 'i')]
        ?string $issue = null,
        #[Option(description: 'Test command, e.g. "php vendor/bin/phpunit" (auto-discovered if omitted)', shortcut: 't')]
        ?string $testCommand = null,
        #[Option(description: 'Emit JSON evidence instead of text', shortcut: 'j')]
        bool $json = false,
        #[Option(description: 'Apply this patch file instead of asking a model', shortcut: 'p')]
        ?string $patch = null,
        #[Option(description: 'Block if the patch touches more than N consumers (0 = unlimited)', shortcut: 'b')]
        int $maxBlastRadius = 0,
        #[Option(description: 'Abort if estimated cost exceeds N dollars (0 = unlimited)', shortcut: 'm')]
        float $maxCost = 0.0,
        #[Option(description: 'Author a reproduction test before patching (model-driven)', shortcut: 'a')]
        bool $authorTests = false,
        #[Option(description: 'Auto-detect the test command from repository config (now the default)', shortcut: 'd')]
        bool $autoDiscover = true,
    ): int {
        // A .capsule.yaml in the working tree supplies defaults; flags always win.
        $config = $this->loadConfig(getcwd() ?: '.');

        if (null === $repo) {
            $repo = $config['repo'] ?? '.';
        }
        if (null === $issue) {
            $issue = $config['issue'] ?? null;
        }
        if (null === $testCommand) {
            $testCommand = $config['test_command'] ?? null;
        }
        if (null === $patch && isset($config['patch'])) {
            $patch = (string) $config['patch'];
        }
        if (0 === $maxBlastRadius && isset($config['max_blast_radius'])) {
            $maxBlastRadius = (int) $config['max_blast_radius'];
        }
        if (0.0 === $maxCost && isset($config['max_cost'])) {
            $maxCost = (float) $config['max_cost'];
        }
        if (!$authorTests && isset($config['author_tests'])) {
            $authorTests = (bool) $config['author_tests'];
        }

        $repoPath = realpath($repo);
        if (false === $repoPath || !is_dir($repoPath)) {
            $io->error(\sprintf('Not a directory: %s', $repo));

            return Command::INVALID;
        }

        // Issue file: explicit, then config, then auto-detect in the repo.
        if (null === $issue) {
            $issue = $this->detectIssue($repoPath);
        }
        if (null === $issue) {
            $io->error('No issue file found. Pass --issue or create issue.md in the repository.');

            return Command::INVALID;
        }

        $issuePath = realpath($issue);
        if (false === $issuePath || !is_file($issuePath)) {
            // Maybe relative to the repo.
            $issuePath = realpath($repoPath.'/'.ltrim($issue, '/'));
        }
        if (false === $issuePath || !is_file($issuePath)) {
            $io->error(\sprintf('No such issue file: %s', $issue));

            return Command::INVALID;
        }

        $issueText = (string) file_get_contents($issuePath);
        $issueSource = $issuePath;

        // Test command: explicit, then config, then auto-discover, then a sane default.
        if (null === $testCommand) {
            if (null !== $this->testDiscovery) {
                $sandbox = new \App\Sandbox\Sandbox($repoPath);
                $discovered = $this->testDiscovery->discover($sandbox);
                if (null !== $discovered) {
                    $testCommand = $discovered;
                    if (!$json) {
                        $io->writeln(\sprintf('<comment>Discovered test command: %s</comment>', $testCommand));
                    }
                }
            }
        }

        if (null === $testCommand) {
            // Fall back to a check.php if present, otherwise require an explicit command.
            if (is_file($repoPath.'/check.php')) {
                $testCommand = 'php check.php';
                if (!$json) {
                    $io->writeln('<comment>Using default test command: php check.php</comment>');
                }
            } else {
                $io->error('Could not discover a test command. Pass --test-command explicitly.');

                return Command::INVALID;
            }
        }

        $checks = $this->runner->parseTestCommand($testCommand);

        if (null === $patch) {
            $evidence = $this->proposeAndVerify(
                io: $io,
                repoPath: $repoPath,
                issueText: $issueText,
                issueSource: $issueSource,
                checks: $checks,
                json: $json,
                maxBlastRadius: $maxBlastRadius,
                maxCost: $maxCost,
                authorTests: $authorTests,
                testCommand: $testCommand,
            );

            return $this->emit($io, $evidence, $json);
        }

        if (!is_file($patch)) {
            $io->error(\sprintf('No such patch file: %s', $patch));

            return Command::INVALID;
        }

        $evidence = $this->runner->verify(
            repoPath: $repoPath,
            issueText: $issueText,
            issueSource: $issueSource,
            checks: $checks,
            patchText: (string) file_get_contents($patch),
            maxBlastRadius: $maxBlastRadius > 0 ? $maxBlastRadius : null,
            maxCost: $maxCost,
        );

        return $this->emit($io, $evidence, $json);
    }

    /**
     * Model-driven path: the agent proposes by modifying a disposable copy via
     * its MCP tools, we capture the diff, then the verifier judges it. The model
     * never gets to declare success.
     *
     * @param list<array{0: string, 1: list<string>}> $checks
     */
    private function proposeAndVerify(
        SymfonyStyle $io,
        string $repoPath,
        string $issueText,
        string $issueSource,
        array $checks,
        bool $json,
        int $maxBlastRadius = 0,
        float $maxCost = 0.0,
        bool $authorTests = false,
        string $testCommand = '',
    ): Evidence {
        if (null === $this->repairAgent) {
            return new Evidence(
                verdict: Verdict::Blocked,
                baseChecks: [],
                headChecks: [],
                revertChecks: [],
                summary: 'No repair agent is available.',
                limits: ['Model-driven patching requires a configured agent and API key. Pass --patch for a deterministic run.'],
                reason: 'no_agent',
            );
        }

        // Pre-scan untrusted text before it reaches the model, same as verify().
        $early = $this->runner->verify($repoPath, $issueText, $issueSource, $checks, '');
        if (Verdict::Blocked === $early->verdict && 'prompt_injection' === $early->reason) {
            return $early;
        }

        $work = null;
        $previousSandbox = getenv('CAPSULE_SANDBOX') ?: null;
        $reproduction = null;
        $startedAt = microtime(true);
        $elapsed = static fn (): float => microtime(true) - $startedAt;

        try {
            $work = Sandbox::copy($repoPath);

            // The MCP server subprocess reads CAPSULE_SANDBOX at boot. The client
            // spawns it when the agent first uses a tool, so set it now.
            // CAPSULE_AGENT_SANDBOX is the override the MCP server honours above
            // .env (see bin/mcp-server).
            $this->setSandboxEnv($work->root());
            putenv('CAPSULE_AGENT_SANDBOX='.$work->root());
            $_ENV['CAPSULE_AGENT_SANDBOX'] = $work->root();
            $_SERVER['CAPSULE_AGENT_SANDBOX'] = $work->root();

            // Test-authoring phase: write a reproduction test first.
            if ($authorTests && null !== $this->testAuthor) {
                if (!$json) {
                    $io->section('Authoring a reproduction test');
                }

                $testRoot = $this->detectTestRoot($work);
                $result = $this->testAuthor->author($work, $issueText, $testRoot, $testCommand);

                if (!$result->success) {
                    return new Evidence(
                        verdict: Verdict::NoPatch,
                        baseChecks: [],
                        headChecks: [],
                        revertChecks: [],
                        summary: 'Could not author a reproduction test.',
                        limits: [$result->message],
                        reason: $result->reason,
                        cost: $this->repairAgent->meter()->cost(),
                        meter: $this->repairAgent->meter(),
                        wallTime: $elapsed(),
                    );
                }

                $reproduction = $result->reproduction;

                if (!$json) {
                    $io->writeln('  <info>✓</info> '.$result->message);
                    $io->writeln(\sprintf('    <comment>baseline:</comment> %s', $this->firstLine($result->baselineOutput)));
                }

                // Fold the authored test into the sandbox baseline. It is part of
                // the specification, not of the patch, so it must never show up in
                // the diff the model is judged on.
                $this->commitBaseline($work);
            }

            if ($this->repairAgent->exceedsCost($maxCost)) {
                return $this->costEvidence($this->repairAgent, $maxCost, 'after authoring the reproduction');
            }

            if (!$json) {
                $io->section('Proposing a patch via MCP tools');
            }

            $context = $this->investigationContext($work);
            $reply = $this->repairAgent->propose($issueText, $context);

            // Stage everything first: `git diff` alone silently ignores files the
            // agent created, which is exactly how a model's new file can vanish
            // from a patch and get judged as "no change".
            $work->run('git', ['add', '-A'], 30);
            $diff = $work->run('git', ['diff', '--cached'], 30)->output;
        } catch (\Throwable $e) {
            return new Evidence(
                verdict: Verdict::Unverified,
                baseChecks: [],
                headChecks: [],
                revertChecks: [],
                summary: 'The repair agent could not produce a verifiable patch.',
                limits: ['Agent error: '.$e->getMessage()],
                reason: 'agent_error',
                cost: $this->repairAgent->meter()->cost(),
                meter: $this->repairAgent->meter(),
                wallTime: $elapsed(),
            );
        } finally {
            putenv('CAPSULE_AGENT_SANDBOX');
            unset($_ENV['CAPSULE_AGENT_SANDBOX'], $_SERVER['CAPSULE_AGENT_SANDBOX']);
            $this->restoreSandboxEnv($previousSandbox);
            $work?->destroy();
        }

        if ('' === trim($diff)) {
            return new Evidence(
                verdict: Verdict::Unverified,
                baseChecks: [],
                headChecks: [],
                revertChecks: [],
                summary: 'The agent finished without producing a diff.',
                limits: [
                    'No working-tree changes were captured from the agent run.',
                    'The model said: '.$this->shorten($reply),
                    ...array_map($this->shorten(...), $this->repairAgent->transcript()),
                ],
                reason: 'empty_patch',
                cost: $this->repairAgent->meter()->cost(),
                meter: $this->repairAgent->meter(),
                reproductionPath: $reproduction?->path,
                wallTime: $elapsed(),
            );
        }

        // The model proposed; the code decides.
        $evidence = $this->runner->verify(
            repoPath: $repoPath,
            issueText: $issueText,
            issueSource: $issueSource,
            checks: $checks,
            patchText: $diff,
            maxBlastRadius: $maxBlastRadius > 0 ? $maxBlastRadius : null,
            maxCost: $maxCost,
            reproduction: $reproduction,
            meter: $this->repairAgent->meter(),
        );

        // The verifier only measures its own run; the model's thinking time is
        // part of what this verdict cost, so report the whole span.
        return new Evidence(
            verdict: $evidence->verdict,
            baseChecks: $evidence->baseChecks,
            headChecks: $evidence->headChecks,
            revertChecks: $evidence->revertChecks,
            summary: $evidence->summary,
            limits: $evidence->limits,
            blastRadius: $evidence->blastRadius,
            diff: $evidence->diff,
            reason: $evidence->reason,
            cost: $evidence->cost,
            wallTime: $elapsed(),
            meter: $evidence->meter,
            reproductionPath: $evidence->reproductionPath,
        );
    }

    /**
     * Fold the authored test into the sandbox's baseline commit, so it is part
     * of the repository rather than part of the proposed change.
     */
    private function commitBaseline(Sandbox $work): void
    {
        $work->run('git', ['add', '-A'], 30);
        $work->run('git', [
            '-c', 'user.email=capsule@localhost',
            '-c', 'user.name=Capsule',
            'commit', '-q', '-m', 'authored reproduction',
        ], 30);
    }

    /**
     * A refusal caused by spending too much, as evidence rather than an exception.
     */
    private function costEvidence(RepairAgent $agent, float $ceiling, string $stage): Evidence
    {
        $meter = $agent->meter();

        return new Evidence(
            verdict: Verdict::Blocked,
            baseChecks: [],
            headChecks: [],
            revertChecks: [],
            summary: \sprintf('Refused: the cost ceiling was reached %s.', $stage),
            limits: [\sprintf(
                'Spent $%.6f across %d call(s) (%d tokens) against a ceiling of $%.6f.',
                $meter->cost(),
                $meter->calls(),
                $meter->totalTokens(),
                $ceiling,
            )],
            reason: 'cost_exceeded',
            cost: $meter->cost(),
            meter: $meter,
        );
    }

    private function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: '');

        return '' === $line ? '(no output)' : $line;
    }

    /**
     * A model reply, trimmed to something that fits in an evidence limit.
     */
    private function shorten(string $text, int $limit = 300): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ('' === $text) {
            return '(nothing)';
        }

        return \strlen($text) <= $limit ? $text : substr($text, 0, $limit).'…';
    }

    private function detectTestRoot(Sandbox $work): string
    {
        if ($work->exists('tests')) {
            return 'tests';
        }

        if ($work->exists('test')) {
            return 'test';
        }

        if ($work->exists('spec')) {
            return 'spec';
        }

        // Default: create a tests directory.
        return 'tests';
    }

    private function investigationContext(Sandbox $work): string
    {
        $listing = $work->run('git', ['ls-files'], 30)->output;

        return "Files:\n".$listing;
    }

    private function setSandboxEnv(string $root): void
    {
        putenv('CAPSULE_SANDBOX='.$root);
        $_ENV['CAPSULE_SANDBOX'] = $root;
        $_SERVER['CAPSULE_SANDBOX'] = $root;
    }

    private function restoreSandboxEnv(?string $previous): void
    {
        if (null === $previous) {
            putenv('CAPSULE_SANDBOX');
            unset($_ENV['CAPSULE_SANDBOX'], $_SERVER['CAPSULE_SANDBOX']);

            return;
        }

        $this->setSandboxEnv($previous);
    }

    /**
     * Load defaults from a .capsule.yaml / .capsule.yml file.
     *
     * @return array<string, mixed>
     */
    private function loadConfig(string $dir): array
    {
        foreach (['.capsule.yaml', '.capsule.yml', '.capsule.json'] as $name) {
            $path = $dir.'/'.$name;
            if (!is_file($path)) {
                continue;
            }

            try {
                if (str_ends_with($name, '.json')) {
                    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                } else {
                    $data = \Symfony\Component\Yaml\Yaml::parseFile($path);
                }
            } catch (\Throwable) {
                return [];
            }

            return \is_array($data) ? $data : [];
        }

        return [];
    }

    /**
     * Find an issue file in the repository when none was given.
     */
    private function detectIssue(string $repoPath): ?string
    {
        foreach (['issue.md', 'ISSUE.md', 'issue.txt', 'ISSUE.txt'] as $name) {
            if (is_file($repoPath.'/'.$name)) {
                return $repoPath.'/'.$name;
            }
        }

        foreach (glob($repoPath.'/.github/ISSUE*') ?: [] as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    private function emit(SymfonyStyle $io, Evidence $evidence, bool $json): int
    {
        if ($json) {
            $io->writeln(json_encode($evidence->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($io, $evidence);
        }

        return $evidence->verdict->exitCode();
    }

    private function render(SymfonyStyle $io, Evidence $evidence): void
    {
        $io->title('Capsule Repeater');

        $io->definitionList(
            ['Verdict' => $evidence->verdict->value],
            ['Meaning' => $evidence->verdict->humanLabel()],
            ['Load-bearing' => $evidence->isLoadBearing() ? 'yes' : 'no'],
            ['Cost' => $this->renderCost($evidence)],
            ['Wall time' => \sprintf('%.2fs', $evidence->wallTime)],
        );

        if (null !== $evidence->reproductionPath) {
            $io->section('Reproduction');
            $io->writeln(\sprintf('  Authored before patching: <info>%s</info>', $evidence->reproductionPath));
        }

        $this->renderChecks($io, 'Base (must fail)', $evidence->baseChecks);
        $this->renderChecks($io, 'Head (must pass)', $evidence->headChecks);
        $this->renderChecks($io, 'Revert (must fail again)', $evidence->revertChecks);

        if ([] !== $evidence->limits) {
            $io->section('Limits');
            $io->listing($evidence->limits);
        }

        if (Verdict::Verified === $evidence->verdict) {
            $io->success('Proven: the patch is what makes the checks pass.');
        }
    }

    /**
     * Cost is reported, not implied: "$0.000000" on a deterministic run means no
     * model was called, and an unmeasured model run says so rather than claiming
     * to have been free.
     */
    private function renderCost(Evidence $evidence): string
    {
        $meter = $evidence->meter;

        if (null === $meter || 0 === $meter->calls()) {
            return 'none (no model called)';
        }

        if (0.0 === $meter->cost()) {
            return \sprintf('%d call(s), usage not reported by the provider', $meter->calls());
        }

        return \sprintf(
            '$%.6f (%d call(s), %d tokens%s)',
            $meter->cost(),
            $meter->calls(),
            $meter->totalTokens(),
            null !== $meter->model() ? ', '.$meter->model() : '',
        );
    }

    /** @param list<CheckResult> $results */
    private function renderChecks(SymfonyStyle $io, string $label, array $results): void
    {
        if ([] === $results) {
            return;
        }

        $io->section($label);
        foreach ($results as $result) {
            $status = $result->passed() ? '<info>PASS</info>' : '<error>FAIL</error>';
            $io->writeln(\sprintf('  %s  %s (exit %d)', $status, $result->command, $result->exitCode));
        }
    }
}
