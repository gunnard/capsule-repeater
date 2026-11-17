<?php

declare(strict_types=1);

namespace App\Agent;

use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Measures what a run actually spent.
 *
 * Symfony AI merges the token usage of every tool round into the metadata of
 * the final message, so a single call already reports the whole loop. We read
 * it there rather than estimating: a cost gate fed by guesses is decoration.
 *
 * A limitation worth stating: usage is only available once a model round has
 * completed, so a ceiling is enforced at round boundaries, not mid-request.
 * That is a property of the platform, not of this class.
 */
final class CostMeter
{
    private float $cost = 0.0;
    private int $promptTokens = 0;
    private int $completionTokens = 0;
    private int $calls = 0;
    private ?string $model = null;

    public function __construct(
        private readonly ModelPricing $pricing = new ModelPricing(),
    ) {}

    /**
     * Fold one completed agent call into the running total.
     */
    public function record(Metadata $metadata, ?string $model = null): void
    {
        $usage = $metadata->get('token_usage');

        if (!$usage instanceof TokenUsageInterface) {
            // The provider reported nothing. Count the call, but do not invent
            // a cost: an unmeasured run must not look expensive or free.
            ++$this->calls;

            return;
        }

        $prompt = $usage->getPromptTokens();
        $completion = $usage->getCompletionTokens();

        $this->promptTokens += $prompt ?? 0;
        $this->completionTokens += $completion ?? 0;
        $this->model = $usage->getModel() ?? $model ?? $this->model;
        $this->cost += $this->pricing->cost($this->model, $prompt, $completion);
        ++$this->calls;
    }

    public function cost(): float
    {
        return $this->cost;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function promptTokens(): int
    {
        return $this->promptTokens;
    }

    public function completionTokens(): int
    {
        return $this->completionTokens;
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    /**
     * Whether a run has already spent more than the caller allowed.
     * A ceiling of zero means unlimited.
     */
    public function exceeds(float $ceiling): bool
    {
        return $ceiling > 0.0 && $this->cost > $ceiling;
    }

    /** @return array{usd: float, calls: int, promptTokens: int, completionTokens: int, model: ?string} */
    public function summary(): array
    {
        return [
            'usd' => round($this->cost, 6),
            'calls' => $this->calls,
            'promptTokens' => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
            'model' => $this->model,
        ];
    }

    public function reset(): void
    {
        $this->cost = 0.0;
        $this->promptTokens = 0;
        $this->completionTokens = 0;
        $this->calls = 0;
        $this->model = null;
    }
}
