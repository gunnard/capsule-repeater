<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Token prices in USD per million tokens.
 *
 * Deliberately a small table with a conservative default: an unknown model is
 * priced high rather than free, so a cost ceiling fails safe. Overly precise
 * numbers would be a lie anyway — providers change them.
 */
final class ModelPricing
{
    /**
     * Longest prefix wins, so `gpt-4o-mini` beats `gpt-4o`.
     *
     * @var array<string, array{input: float, output: float}>
     */
    private const PRICES = [
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
        'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
        'o4-mini' => ['input' => 1.10, 'output' => 4.40],
        'o3-mini' => ['input' => 1.10, 'output' => 4.40],
        'o3' => ['input' => 2.00, 'output' => 8.00],
        'claude-sonnet' => ['input' => 3.00, 'output' => 15.00],
        'claude-haiku' => ['input' => 1.00, 'output' => 5.00],
        'claude-opus' => ['input' => 15.00, 'output' => 75.00],
    ];

    /** Unknown models are assumed expensive, never free. */
    private const DEFAULT = ['input' => 5.00, 'output' => 15.00];

    /**
     * @return array{input: float, output: float}
     */
    public function ratesFor(?string $model): array
    {
        if (null === $model || '' === $model) {
            return self::DEFAULT;
        }

        $needle = strtolower($model);
        $match = null;
        $matchLength = 0;

        foreach (self::PRICES as $prefix => $rates) {
            if (str_starts_with($needle, $prefix) && \strlen($prefix) > $matchLength) {
                $match = $rates;
                $matchLength = \strlen($prefix);
            }
        }

        return $match ?? self::DEFAULT;
    }

    public function cost(?string $model, ?int $promptTokens, ?int $completionTokens): float
    {
        $rates = $this->ratesFor($model);

        return ($promptTokens ?? 0) / 1_000_000 * $rates['input']
            + ($completionTokens ?? 0) / 1_000_000 * $rates['output'];
    }
}
