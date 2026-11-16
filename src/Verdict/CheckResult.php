<?php

declare(strict_types=1);

namespace App\Verdict;

/**
 * The result of one command execution inside the sandbox.
 */
final readonly class CheckResult
{
    public function __construct(
        public string $command,
        public int $exitCode,
        public string $output,
    ) {}

    public function passed(): bool
    {
        return 0 === $this->exitCode;
    }

    public function failed(): bool
    {
        return 0 !== $this->exitCode;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'command' => $this->command,
            'exitCode' => $this->exitCode,
            'passed' => $this->passed(),
            'output' => $this->truncate($this->output),
        ];
    }

    private function truncate(string $output, int $limit = 4000): string
    {
        return \strlen($output) <= $limit ? $output : substr($output, 0, $limit)."\n… (truncated)";
    }
}
