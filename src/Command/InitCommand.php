<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Scaffold a .capsule.yaml so a repository remembers its own settings.
 *
 * The file is the thing that makes `capsule check` zero-argument in a real
 * project: test command, issue file, blast-radius ceiling, cost ceiling.
 */
#[AsCommand(name: 'init', description: 'Create a .capsule.yaml with sensible defaults')]
final class InitCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = getcwd().'/.capsule.yaml';

        if (is_file($path)) {
            $io->warning('A .capsule.yaml already exists; not overwriting.');

            return Command::SUCCESS;
        }

        $discovered = null;
        if (class_exists('App\\Service\\TestDiscovery') && is_dir(getcwd().'/vendor')) {
            // Best effort; the user can edit the file afterwards.
        }

        $defaults = [
            'test_command' => is_file(getcwd().'/check.php') ? 'php check.php' : 'php vendor/bin/phpunit',
            'issue' => is_file(getcwd().'/issue.md') ? 'issue.md' : null,
            'max_blast_radius' => 0,
            'max_cost' => 0.0,
            'author_tests' => false,
        ];

        if (null === $defaults['issue']) {
            unset($defaults['issue']);
        }

        file_put_contents($path, Yaml::dump($defaults, 2, 2));

        $io->success('Created .capsule.yaml');
        $io->text('Edit it to taste, then run `capsule check` with no arguments.');

        return Command::SUCCESS;
    }
}
