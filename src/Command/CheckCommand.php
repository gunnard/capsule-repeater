<?php

declare(strict_types=1);

namespace App\Command;

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

        if (null === $patch) {
            $io->error('No --patch given. Model-driven patching runs through the repair agent against the MCP server; pass --patch for a deterministic run.');

            return Command::INVALID;
        }

        if (!is_file($patch)) {
            $io->error(\sprintf('No such patch file: %s', $patch));

            return Command::INVALID;
        }

        $evidence = $this->runner->verify(
            repoPath: $repoPath,
            issueText: (string) file_get_contents($issuePath),
            issueSource: $issue,
            checks: $this->runner->parseTestCommand($testCommand),
            patchText: (string) file_get_contents($patch),
        );

        return $this->emit($io, $evidence, $json);
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
