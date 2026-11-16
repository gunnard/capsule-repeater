<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:demo', description: 'Run the bundled issue-to-patch demo case')]
final class DemoCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Capsule Repeater — Issue-to-Patch Demo</info>');
        $output->writeln('Loading bundled bug case...');

        // TODO: wire up IssueToPatchAgent + MCP server
        $output->writeln('<comment>Agent not yet wired — scaffold only.</comment>');

        return Command::SUCCESS;
    }
}
