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
        public ?string $testCode,
        public string $reason,
        public string $message,
        public string $baselineOutput = '',
    ) {}

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
