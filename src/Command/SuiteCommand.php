<?php

declare(strict_types=1);

namespace App\Command;

use App\Verify\VerificationRunner;
use App\Verdict\Evidence;
use App\Verdict\Verdict;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Score the gate against a frozen corpus.
 *
 * This is the difference between a demo and a measurement: every case declares
 * the verdict it deserves, including the ones that must be refused. A run that
 * scores 6/6 has shown it can say no, which is the only interesting property.
 */
#[AsCommand(name: 'suite', description: 'Score the gate against the frozen corpus')]
final class SuiteCommand
{
    public function __construct(
        private readonly VerificationRunner $runner,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Corpus directory')]
        string $corpus = 'corpus',
        #[Option(description: 'Emit JSON instead of a table')]
        bool $json = false,
    ): int {
        $root = realpath($corpus);
        if (false === $root || !is_dir($root)) {
            $io->error(\sprintf('No such corpus directory: %s', $corpus));

            return Command::FAILURE;
        }

        $cases = $this->discover($root);
        if ([] === $cases) {
            $io->error(\sprintf('No cases found in %s', $corpus));

            return Command::FAILURE;
        }

        $results = [];
        foreach ($cases as $case) {
            $results[] = $this->runCase($case);
        }

        $passed = array_filter($results, static fn (array $r): bool => $r['match']);
        $refused = array_filter($results, static fn (array $r): bool => Verdict::Verified->value !== $r['actual']);

        if ($json) {
            $io->writeln(json_encode([
                'total' => \count($results),
                'passed' => \count($passed),
                'failed' => \count($results) - \count($passed),
                'refused' => \count($refused),
                'cases' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return \count($passed) === \count($results) ? Command::SUCCESS : Command::FAILURE;
        }

        $io->title('Capsule Repeater · corpus');

        $rows = [];
        foreach ($results as $result) {
            $rows[] = [
                $result['name'],
                $result['expected'],
                $result['actual'],
                $result['match'] ? '<info>ok</info>' : '<error>MISMATCH</error>',
                $result['note'],
            ];
        }

        $io->table(['Case', 'Expected', 'Actual', '', 'Why'], $rows);

        $io->definitionList(
            ['Score' => \sprintf('%d/%d', \count($passed), \count($results))],
            ['Refused' => \sprintf('%d of %d (not VERIFIED)', \count($refused), \count($results))],
        );

        if (\count($passed) === \count($results)) {
            $io->success('Every case got the verdict it deserves — including the refusals.');

            return Command::SUCCESS;
        }

        $io->error(\sprintf('%d case(s) got the wrong verdict.', \count($results) - \count($passed)));

        return Command::FAILURE;
    }

    /**
     * @return list<array{name: string, dir: string, expected: string, note: string}>
     */
    private function discover(string $root): array
    {
        $cases = [];

        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }

            $dir = $entry->getPathname();
            $expectedFile = $dir.'/expected.txt';

            if (!is_file($expectedFile) || !is_file($dir.'/issue.md') || !is_file($dir.'/patch.diff')) {
                continue;
            }

            $note = is_file($dir.'/README.md')
                ? trim((string) file_get_contents($dir.'/README.md'))
                : '';

            $cases[] = [
                'name' => $entry->getFilename(),
                'dir' => $dir,
                'expected' => trim((string) file_get_contents($expectedFile)),
                'note' => $note,
            ];
        }

        usort($cases, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $cases;
    }

    /**
     * @param array{name: string, dir: string, expected: string, note: string} $case
     *
     * @return array{name: string, expected: string, actual: string, match: bool, note: string, limits: list<string>}
     */
    private function runCase(array $case): array
    {
        // The corpus uses a standalone PHP script as its check rather than
        // PHPUnit, so the corpus stays dependency-free and fast.
        $evidence = $this->runner->verify(
            repoPath: $case['dir'],
            issueText: (string) file_get_contents($case['dir'].'/issue.md'),
            issueSource: $case['name'].'/issue.md',
            checks: $this->runner->parseTestCommand('php check.php'),
            patchText: (string) file_get_contents($case['dir'].'/patch.diff'),
        );

        return [
            'name' => $case['name'],
            'expected' => $case['expected'],
            'actual' => $evidence->verdict->value,
            'match' => $evidence->verdict->value === $case['expected'],
            'note' => $case['note'],
            'limits' => $this->summarise($evidence),
        ];
    }

    /** @return list<string> */
    private function summarise(Evidence $evidence): array
    {
        return $evidence->limits;
    }
}
