<?php

declare(strict_types=1);

namespace App\Verify;

use App\Sandbox\Sandbox;

/**
 * Maps what else a patch could break.
 *
 * Two questions, answered in order:
 *   1. Which symbols does the patch touch?
 *   2. Who calls those symbols?
 *
 * The first is cheap and local. The second is the one that matters: a patch that
 * changes a method used by 47 other files is not a one-line fix, whatever the
 * diff says. This service produces the evidence; the verdict decides what to do
 * with it.
 */
final class BlastRadius
{
    /** @var list<string> */
    private const SYMBOL_PATTERN = ['/\\b(?:function|class|interface|trait)\\s+([A-Za-z_][A-Za-z0-9_]*)/'];

    private ?Sandbox $sandbox = null;

    /**
     * Analyze the blast radius of a patch.
     *
     * @param Sandbox      $sandbox       the sandbox containing the repository
     * @param list<string> $changedFiles  files the patch modifies
     *
     * @return BlastRadiusReport
     */
    public function analyze(Sandbox $sandbox, array $changedFiles): BlastRadiusReport
    {
        $this->sandbox = $sandbox;
        $symbols = $this->extractSymbols($changedFiles);
        $consumers = $this->findConsumers($symbols, $changedFiles);

        return new BlastRadiusReport(
            symbols: $symbols,
            consumers: $consumers,
            totalConsumers: \count($consumers),
            riskLevel: $this->riskLevel(\count($consumers)),
        );
    }

    /**
     * Extract every function/class/interface/trait name the patch touches.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function extractSymbols(array $files): array
    {
        $symbols = [];

        foreach ($files as $file) {
            if (!str_ends_with($file, '.php') || !$this->sandbox->exists($file)) {
                continue;
            }

            $contents = $this->sandbox->read($file);
            if (0 !== preg_match_all(self::SYMBOL_PATTERN[0], $contents, $matches)) {
                $symbols = [...$symbols, ...$matches[1]];
            }
        }

        return array_values(array_unique($symbols));
    }

    /**
     * For each symbol, ripgrep the repository for every other file that
     * references it. The defining file is excluded.
     *
     * @param list<string> $symbols
     * @param list<string> $changedFiles
     *
     * @return list<ConsumerReference>
     */
    private function findConsumers(array $symbols, array $changedFiles): array
    {
        $consumers = [];
        $changedSet = array_flip($changedFiles);

        foreach ($symbols as $symbol) {
            // Skip very short or common names that would produce noise.
            if (\strlen($symbol) < 4) {
                continue;
            }

            $result = $this->sandbox->run('rg', [
                '--line-number',
                '--no-heading',
                '--color', 'never',
                '--word-regexp',
                '--fixed-strings',
                '--glob', '!vendor/**',
                '--glob', '!.git/**',
                '--glob', '!tests/**',
                '--glob', '!*.patch',
                '--glob', '!bootstrap.php',
                '--glob', '!check.php',
                $symbol,
                '.',
            ], 30);

            foreach (explode("\n", $result->output) as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }

                // rg output: path:line:content
                $parts = explode(':', $line, 3);
                if (3 !== \count($parts)) {
                    continue;
                }

                [$file, $lineNumber, $content] = $parts;

                // Skip the defining file — we want consumers, not definitions.
                if (isset($changedSet[$file])) {
                    continue;
                }

                // Skip if this line is a definition, not a call/reference.
                if (1 === preg_match('/\b(?:function|class|interface|trait)\s+'.preg_quote($symbol, '/').'/', $content)) {
                    continue;
                }

                $consumers[] = new ConsumerReference(
                    symbol: $symbol,
                    file: $file,
                    line: (int) $lineNumber,
                    excerpt: trim($content),
                );
            }
        }

        // Deduplicate by file+line+symbol.
        $seen = [];
        $unique = [];
        foreach ($consumers as $consumer) {
            $key = $consumer->symbol.'|'.$consumer->file.'|'.$consumer->line;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $consumer;
        }

        return $unique;
    }

    private function riskLevel(int $consumerCount): string
    {
        return match (true) {
            $consumerCount === 0 => 'none',
            $consumerCount <= 3 => 'low',
            $consumerCount <= 10 => 'medium',
            default => 'high',
        };
    }
}