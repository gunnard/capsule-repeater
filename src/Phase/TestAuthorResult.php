<?php

declare(strict_types=1);

namespace App\Phase;

/**
 * Result of a test-authoring attempt.
 */
final readonly class TestAuthorResult
{
    public function __construct(
        public bool $success,
        public ?string $testFile,
        public ?Reproduction $reproduction,
        public string $reason,
        public string $message,
        public string $baselineOutput = '',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'testFile' => $this->testFile,
            'reason' => $this->reason,
            'message' => $this->message,
            'baselineOutput' => $this->baselineOutput,
        ];
    }
}
