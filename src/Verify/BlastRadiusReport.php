<?php

declare(strict_types=1);

namespace App\Verify;

/**
 * The full blast radius of a patch.
 */
final readonly class BlastRadiusReport
{
    /**
     * @param list<string>           $symbols    symbols the patch touches
     * @param list<ConsumerReference> $consumers  other files that reference those symbols
     * @param int                    $totalConsumers
     * @param string                 $riskLevel   none | low | medium | high
     */
    public function __construct(
        public array $symbols,
        public array $consumers,
        public int $totalConsumers,
        public string $riskLevel,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'symbols' => $this->symbols,
            'consumers' => array_map(
                static fn (ConsumerReference $c): array => $c->toArray(),
                $this->consumers,
            ),
            'totalConsumers' => $this->totalConsumers,
            'riskLevel' => $this->riskLevel,
        ];
    }

    public function exceeds(int $threshold): bool
    {
        return $this->totalConsumers > $threshold;
    }
}
