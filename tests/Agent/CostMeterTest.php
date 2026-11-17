<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\CostMeter;
use App\Agent\ModelPricing;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

final class CostMeterTest extends TestCase
{
    private static function usage(int $prompt, int $completion, string $model = 'gpt-4o-mini'): Metadata
    {
        return new Metadata([
            'token_usage' => new TokenUsage(
                promptTokens: $prompt,
                completionTokens: $completion,
                totalTokens: $prompt + $completion,
                model: $model,
            ),
        ]);
    }

    public function testItPricesAKnownModel(): void
    {
        $meter = new CostMeter();
        $meter->record(self::usage(1_000_000, 1_000_000));

        // gpt-4o-mini: $0.15 in + $0.60 out per million.
        self::assertEqualsWithDelta(0.75, $meter->cost(), 1e-9);
        self::assertSame(2_000_000, $meter->totalTokens());
        self::assertSame(1, $meter->calls());
        self::assertSame('gpt-4o-mini', $meter->model());
    }

    public function testItAccumulatesAcrossCalls(): void
    {
        $meter = new CostMeter();
        $meter->record(self::usage(1_000_000, 0));
        $meter->record(self::usage(0, 1_000_000));

        self::assertEqualsWithDelta(0.75, $meter->cost(), 1e-9);
        self::assertSame(2, $meter->calls());
    }

    public function testLongestPrefixWins(): void
    {
        $pricing = new ModelPricing();

        // `gpt-4o-mini` must not be priced as `gpt-4o`.
        self::assertSame(0.15, $pricing->ratesFor('gpt-4o-mini-2024-07-18')['input']);
        self::assertSame(2.50, $pricing->ratesFor('gpt-4o-2024-08-06')['input']);
    }

    public function testAnUnknownModelIsAssumedExpensiveNotFree(): void
    {
        $pricing = new ModelPricing();

        self::assertGreaterThan(0.0, $pricing->cost('some-unlisted-model', 1000, 1000));
        self::assertGreaterThan(0.0, $pricing->cost(null, 1000, 1000));
    }

    public function testAMissingUsageIsCountedButNotInvented(): void
    {
        $meter = new CostMeter();
        $meter->record(new Metadata());

        self::assertSame(1, $meter->calls());
        self::assertSame(0.0, $meter->cost());
        self::assertSame(0, $meter->totalTokens());
    }

    public function testTheCeilingIsOnlyEnforcedWhenSet(): void
    {
        $meter = new CostMeter();
        $meter->record(self::usage(1_000_000, 0));

        self::assertFalse($meter->exceeds(0.0), 'zero means unlimited, not "spend nothing"');
        self::assertFalse($meter->exceeds(0.5));
        self::assertTrue($meter->exceeds(0.0001));
    }

    public function testTheSummaryReportsWhatWasSpent(): void
    {
        $meter = new CostMeter();
        $meter->record(self::usage(1000, 500));

        $summary = $meter->summary();

        self::assertSame(1, $summary['calls']);
        self::assertSame(1000, $summary['promptTokens']);
        self::assertSame(500, $summary['completionTokens']);
        self::assertSame('gpt-4o-mini', $summary['model']);
        self::assertGreaterThan(0.0, $summary['usd']);
    }
}
