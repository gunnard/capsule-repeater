<?php

declare(strict_types=1);

namespace App\Verdict;

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
     * @param list<string>      $blastRadius  every consumer of the symbols the patch touches
     */
    public function __construct(
        public Verdict $verdict,
        public array $baseChecks,
        public array $headChecks,
        public array $revertChecks,
        public string $summary,
        public array $limits = [],
        public array $blastRadius = [],
        public ?string $diff = null,
        public ?string $reason = null,
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
            'blastRadius' => $this->blastRadius,
            'limits' => $this->limits,
            'diff' => $this->diff,
        ];
    }
}
