<?php

declare(strict_types=1);

namespace App\Verify;

/**
 * A single reference to a changed symbol from another file.
 */
final readonly class ConsumerReference
{
    public function __construct(
        public string $symbol,
        public string $file,
        public int $line,
        public string $excerpt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'file' => $this->file,
            'line' => $this->line,
            'excerpt' => $this->excerpt,
        ];
    }
}
