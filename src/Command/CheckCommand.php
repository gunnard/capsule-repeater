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
        #[Option(description: 'Repository to verify')]
        string $repo = '.',
        #[Option(description: 'Path to the issue description')]
        string $issue = 'issue.md',
        #[Option(description: 'Test command, e.g. "php vendor/bin/phpunit"')]
        string $testCommand = 'php vendor/bin/phpunit',
        #[Option(description: 'Emit JSON evidence instead of text')]
        bool $json = false,
        #[Option(description: 'Apply this patch file instead of asking a model')]
        ?string $patch = null,
        #[Option(description: 'Block if the patch touches more than N consumers (0 = unlimited)')]
        int $maxBlastRadius = 0,
        #[Option(description: 'Abort if estimated cost exceeds N dollars (0 = unlimited)')]
        float $maxCost = 0.0,
        #[Option(description: 'Author a reproduction test before patching (model-driven)')]
        bool $authorTests = false,
        #[Option(description: 'Auto-detect the test command from repository config')]
        bool $autoDiscover = false,
    ): int {
        $repoPath = realpath($repo);
        if (false === $repoPath || !is_dir($repoPath)) {
            $io->error(\sprintf('Not a directory: %s', $repo));

            return Command::INVALID;
        }

        $issuePath = realpath($issue);
        if (false === $issuePath || !is_file($issuePath)) {
            $io->error(\sprintf('No such issue file: %s', $issue));

            return Command::INVALID;
        }

        $issueText = (string) file_get_contents($issuePath);

        // Auto-discover the test command if requested.
        if ($autoDiscover && null !== $this->testDiscovery) {
            $sandbox = new \App\Sandbox\Sandbox($repoPath);
            $discovered = $this->testDiscovery->discover($sandbox);
            if (null === $discovered) {
                $io->error('Could not discover a test command. Pass --test-command explicitly.');

                return Command::INVALID;
            }
            $testCommand = $discovered;
            if (!$json) {
                $io->writeln(\sprintf('<comment>Discovered test command: %s</comment>', $testCommand));
            }
        }

        $checks = $this->runner->parseTestCommand($testCommand);

        if (null === $patch) {
            $evidence = $this->proposeAndVerify($io, $repoPath, $issueText, $issue, $checks, $json, $maxBlastRadius, $maxCost);

            return $this->emit($io, $evidence, $json);
        }

        if (!is_file($patch)) {
            $io->error(\sprintf('No such patch file: %s', $patch));

            return Command::INVALID;
        }

        $evidence = $this->runner->verify(
            repoPath: $repoPath,
            issueText: $issueText,
            issueSource: $issue,
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
                $result = $this->testAuthor->author($work, $issueText, $testRoot);

                if (!$result->success) {
                    return new Evidence(
                        verdict: Verdict::NoPatch,
                        baseChecks: [],
                        headChecks: [],
                        revertChecks: [],
                        summary: 'Could not author a reproduction test.',
                        limits: [$result->message],
                        reason: $result->reason,
                    );
                }

                if (!$json) {
                    $io->writeln('  ✓ '.$result->message);
                }
            }

            if (!$json) {
                $io->section('Proposing a patch via MCP tools');
            }

            $context = $this->investigationContext($work);
            $this->repairAgent->propose($issueText, $context);

            $diff = $work->run('git', ['diff'], 30)->output;
        } catch (\Throwable $e) {
            return new Evidence(
                verdict: Verdict::Unverified,
                baseChecks: [],
                headChecks: [],
                revertChecks: [],
                summary: 'The repair agent could not produce a verifiable patch.',
                limits: ['Agent error: '.$e->getMessage()],
                reason: 'agent_error',
            );
        } finally {
            putenv('CAPSULE_AGENT_SANDBOX');
            unset($_ENV['CAPSULE_AGENT_SANDBOX'], $_SERVER['CAPSULE_AGENT_SANDBOX']);
            $this->restoreSandboxEnv($previousSandbox);
            $work?->destroy();
        }

        if ('' === trim($diff)) {
            // Maybe the agent committed or staged differently? check status too.
            return new Evidence(
                verdict: Verdict::Unverified,
                baseChecks: [],
                headChecks: [],
                revertChecks: [],
                summary: 'The agent finished without producing a diff.',
                limits: ['No working-tree changes were captured from the agent run.'],
                reason: 'empty_patch',
            );
        }

        // The model proposed; the code decides.
        return $this->runner->verify(
            repoPath: $repoPath,
            issueText: $issueText,
            issueSource: $issueSource,
            checks: $checks,
            patchText: $diff,
            maxBlastRadius: $maxBlastRadius > 0 ? $maxBlastRadius : null,
            maxCost: $maxCost,
        );
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
        );

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
