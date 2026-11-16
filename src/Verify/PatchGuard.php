<?php

declare(strict_types=1);

namespace App\Verify;

/**
 * Guards the shape of a generated patch before it is anywhere near being applied.
 *
 * A patch that rewrites half the repository is not a fix, whatever the tests say.
 */
final class PatchGuard
{
    public function __construct(
        private readonly int $maxChangedLines = 150,
        private readonly int $maxFiles = 8,
    ) {}

    /**
     * @return array{allowed: bool, reasons: list<string>, files: list<string>, changedLines: int}
     */
    public function inspect(string $patch): array
    {
        $files = [];
        $changedLines = 0;

        foreach (explode("\n", $patch) as $line) {
            if (str_starts_with($line, '+++ ') || str_starts_with($line, '--- ')) {
                $path = trim(substr($line, 4));
                $path = preg_replace('#^(a|b)/#', '', $path) ?? $path;
                if ('/dev/null' !== $path && '' !== $path) {
                    $files[$path] = true;
                }
                continue;
            }

            if (str_starts_with($line, '+') || str_starts_with($line, '-')) {
                if (!str_starts_with($line, '+++') && !str_starts_with($line, '---')) {
                    ++$changedLines;
                }
            }
        }

        $reasons = [];

        if ($changedLines > $this->maxChangedLines) {
            $reasons[] = \sprintf('Patch changes %d lines, over the %d-line budget.', $changedLines, $this->maxChangedLines);
        }

        if (\count($files) > $this->maxFiles) {
            $reasons[] = \sprintf('Patch touches %d files, over the %d-file budget.', \count($files), $this->maxFiles);
        }

        if ([] === $files) {
            $reasons[] = 'Patch does not modify any file.';
        }

        if (0 === $changedLines && [] !== $files) {
            $reasons[] = 'Patch changes no lines: there is nothing to verify.';
        }

        return [
            'allowed' => [] === $reasons,
            'reasons' => $reasons,
            'files' => array_keys($files),
            'changedLines' => $changedLines,
        ];
    }
}
