<?php

declare(strict_types=1);

namespace App\Verdict;

use App\Phase\Reproduction;

/**
 * Everything a verdict rests on. If a claim is not in here, it was not proven.
 *
 * This object is the product. A patch without evidence is just a diff.
 */
final readonly class Evidence
{
    /**
     * @param list<CheckResult> $baseChecks   run against unpatched source — must fail
     * @param list<CheckResult> $headChecks   run with the patch applied — must pass
     * @param list<CheckResult> $revertChecks run with the patch reverted — must fail again
     * @param list<string>      $limits       what this run could not verify
     * @param ?BlastRadiusReport $blastRadius  consumer cross-reference for the patch
     */
    public function __construct(
        public Verdict $verdict,
        public array $baseChecks,
        public array $headChecks,
        public array $revertChecks,
        public string $summary,
        public array $limits = [],
        public ?\App\Verify\BlastRadiusReport $blastRadius = null,
        public ?string $diff = null,
        public ?string $reason = null,
        public float $cost = 0.0,
        public float $wallTime = 0.0,
        public ?\App\Agent\CostMeter $meter = null,
        public ?string $reproductionPath = null,
    ) {}

    /**
     * The differential proof: the patch only counts if removing it breaks the tests again.
     */
    public function isLoadBearing(): bool
    {
        if ([] === $this->baseChecks || [] === $this->headChecks || [] === $this->revertChecks) {
            return false;
        }

        $baseFailed = $this->allFailed($this->baseChecks);
        $headPassed = $this->allPassed($this->headChecks);
        $revertFailed = $this->allFailed($this->revertChecks);

        return $baseFailed && $headPassed && $revertFailed;
    }

    /** @param list<CheckResult> $results */
    private function allPassed(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->failed()) {
                return false;
            }
        }

        return true;
    }

    /** @param list<CheckResult> $results */
    private function allFailed(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->passed()) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict->value,
            'exitCode' => $this->verdict->exitCode(),
            'summary' => $this->summary,
            'reason' => $this->reason,
            'evidence' => [
                'base' => array_map(static fn (CheckResult $r): array => $r->toArray(), $this->baseChecks),
                'head' => array_map(static fn (CheckResult $r) => $r->toArray(), $this->headChecks),
                'revert' => array_map(static fn (CheckResult $r) => $r->toArray(), $this->revertChecks),
            ],
            'loadBearing' => $this->isLoadBearing(),
            'blastRadius' => $this->blastRadius?->toArray(),
            'limits' => $this->limits,
            'diff' => $this->diff,
            'cost' => $this->cost,
            'usage' => $this->meter?->summary(),
            'reproduction' => $this->reproductionPath,
            'wallTime' => $this->wallTime,
        ];
    }
}
