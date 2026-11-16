<?php

declare(strict_types=1);

namespace App\Command;

use App\Agent\IssueToPatchAgent;
use App\Mcp\Server\McpServer;
use App\Mcp\Tools\RepoTools;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:demo', description: 'Run the bundled issue-to-patch demo case')]
final class DemoCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('issue', 'i', InputOption::VALUE_REQUIRED, 'Issue description (or use bundled case)');
        $this->addOption('repo', 'r', InputOption::VALUE_REQUIRED, 'Path to target repository');
        $this->addOption('mcp-stdio', null, InputOption::VALUE_NONE, 'Run as MCP stdio server instead of demo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // MCP stdio mode
        if ($input->getOption('mcp-stdio')) {
            $repoPath = $input->getOption('repo') ?? getcwd();
            $tools = new RepoTools($repoPath);
            $server = new McpServer($tools);
            $io->writeln('<info>Capsule Repeater MCP Server starting (stdio)...</info>');
            $server->runStdio();
            return Command::SUCCESS;
        }

        // Demo mode
        $io->title('Capsule Repeater — Issue-to-Patch Demo');

        $repoPath = $input->getOption('repo');
        if ($repoPath === null) {
            // Use bundled sample repo
            $repoPath = $this->createBundledRepo($io);
            if ($repoPath === null) {
                $io->error('Could not create bundled demo repo.');
                return Command::FAILURE;
            }
        }

        $issue = $input->getOption('issue');
        if ($issue === null) {
            $issue = $this->bundledIssue();
        }

        $io->section('Issue');
        $io->text($issue);

        $io->section('Running agent loop...');
        $agent = new IssueToPatchAgent($repoPath);
        $result = $agent->run($issue);

        $io->section('Patch');
        if (!empty($result['patch'])) {
            $io->text($result['patch']);
        } else {
            $io->warning('No patch generated.');
        }

        $io->section('Report');
        $report = $result['report'];
        $io->text('Summary: ' . ($report['summary'] ?? 'N/A'));
        $io->text('Root cause: ' . ($report['rootCause'] ?? 'N/A'));
        $io->text('Confidence: ' . ($report['confidence'] ?? 'N/A'));

        if (!empty($report['verified'])) {
            $io->success('Verified:');
            foreach ($report['verified'] as $v) {
                $io->text('  ✓ ' . $v);
            }
        }

        if (!empty($report['unverified'])) {
            $io->warning('Unverified:');
            foreach ($report['unverified'] as $v) {
                $io->text('  ✗ ' . $v);
            }
        }

        if (!empty($report['limits'])) {
            $io->note('Limits: ' . $report['limits']);
        }

        $io->newLine();
        $status = ($result['verified'] ?? false) ? '<info>VALIDATED</info>' : '<comment>NEEDS REVIEW</comment>';
        $io->writeln("Result: {$status}");

        return Command::SUCCESS;
    }

    private function bundledIssue(): string
    {
        return "Bug: The calculateTotal() function in src/Calculator.php returns the wrong result. "
            . "It should sum all items in the array but currently returns 0 for non-empty arrays. "
            . "Expected: calculateTotal([1, 2, 3]) should return 6.";
    }

    private function createBundledRepo(SymfonyStyle $io): ?string
    {
        $tmpDir = sys_get_temp_dir() . '/capsule-repeater-demo-' . uniqid();
        mkdir($tmpDir, 0755, true);

        // Create sample PHP project with a bug
        $srcDir = $tmpDir . '/src';
        mkdir($srcDir, 0755, true);

        file_put_contents($srcDir . '/Calculator.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace Demo;

class Calculator
{
    /**
     * Sum all items in the array.
     */
    public function calculateTotal(array $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            // BUG: forgot to add $item to $total
        }
        return $total;
    }
}
PHP
        );

        // Create a test
        $testDir = $tmpDir . '/tests';
        mkdir($testDir, 0755, true);
        file_put_contents($testDir . '/CalculatorTest.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace Demo\Tests;

use Demo\Calculator;
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase
{
    public function testCalculateTotal(): void
    {
        $calc = new Calculator();
        $this->assertSame(6, $calc->calculateTotal([1, 2, 3]));
        $this->assertSame(0, $calc->calculateTotal([]));
        $this->assertSame(10, $calc->calculateTotal([10]));
    }
}
PHP
        );

        // Create composer.json for the sample project
        file_put_contents($tmpDir . '/composer.json', json_encode([
            'name' => 'demo/calculator',
            'autoload' => ['psr-4' => ['Demo\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Demo\\Tests\\' => 'tests/']],
            'require-dev' => ['phpunit/phpunit' => '^11.0'],
        ], JSON_PRETTY_PRINT));

        // Initialize git
        exec("cd {$tmpDir} && git init -q && git add -A && git commit -q -m 'Initial commit with bug' 2>&1", $out, $code);
        if ($code !== 0) {
            $io->warning('Git init failed: ' . implode("\n", $out));
        }

        $io->note("Bundled demo repo created at: {$tmpDir}");
        return $tmpDir;
    }
}
