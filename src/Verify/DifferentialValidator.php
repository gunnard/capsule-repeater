<?php

declare(strict_types=1);

namespace App\Verify;

use App\Sandbox\Sandbox;
use App\Verdict\CheckResult;
use App\Verdict\Evidence;
use App\Verdict\Verdict;

/**
 * Decides whether a patch is load-bearing.
 *
 * The rule is simple and non-negotiable:
 *
 *   base must FAIL   → the bug is reproduced
 *   head must PASS   → the patch fixes it
 *   revert must FAIL → the patch is what fixes it
 *
 * If the revert still passes, the patch was decorative and the verdict is
 * UNVERIFIED. No phrasing gets around this; it is the whole product.
 *
 * The sandbox is a parameter rather than a dependency on purpose: a verification
 * run owns exactly one repository, and the container has no business deciding
 * which one.
 */
final class DifferentialValidator
{
    /**
     * The full three-phase evaluation.
     *
     * @param callable(): array{applied: bool, message: string} $applyPatch
     * @param callable(): void                                  $revertPatch
     * @param list<array{0: string, 1: list<string>}>           $checks binary + arguments to run for each phase
     * @param list<string>                                      $limits
     * @param list<string>                                      $blastRadius
     */
    public function validateWithPatch(
        Sandbox $sandbox,
        array $checks,
        callable $applyPatch,
        callable $revertPatch,
        string $summary,
        array $limits = [],
        array $blastRadius = [],
        ?string $diff = null,
    ): Evidence {
        $base = $this->runAll($sandbox, $checks);

        if (!$this->allFailed($base)) {
            return new Evidence(
                verdict: Verdict::NoPatch,
                baseChecks: $base,
                headChecks: [],
                revertChecks: [],
                summary: $summary,
                limits: [...$limits, 'The checks passed before any patch was applied: the bug was not reproduced, so no honest patch exists.'],
                blastRadius: $blastRadius,
                diff: $diff,
                reason: 'cannot_reproduce',
            );
        }

        $application = $applyPatch();
        if (!($application['applied'] ?? false)) {
            return new Evidence(
                verdict: Verdict::Unverified,
                baseChecks: $base,
                headChecks: [],
                revertChecks: [],
                summary: $summary,
                limits: [...$limits, 'The patch did not apply: '.($application['message'] ?? 'unknown reason')],
                blastRadius: $blastRadius,
                diff: $diff,
                reason: 'patch_did_not_apply',
            );
        }

        $head = $this->runAll($sandbox, $checks);
        $revertPatch();
        $revert = $this->runAll($sandbox, $checks);

        $loadBearing = $this->allFailed($base) && $this->allPassed($head) && $this->allFailed($revert);

        if ($loadBearing) {
            return new Evidence(
                verdict: Verdict::Verified,
                baseChecks: $base,
                headChecks: $head,
                revertChecks: $revert,
                summary: $summary,
                limits: $limits,
                blastRadius: $blastRadius,
                diff: $diff,
            );
        }

        $reasons = [];
        if (!$this->allPassed($head)) {
            $reasons[] = 'the patch did not make the checks pass';
        }
        if (!$this->allFailed($revert)) {
            $reasons[] = 'the checks still pass with the patch reverted — the patch was decorative';
        }

        return new Evidence(
            verdict: Verdict::Unverified,
            baseChecks: $base,
            headChecks: $head,
            revertChecks: $revert,
            summary: $summary,
            limits: [...$limits, ...$reasons],
            blastRadius: $blastRadius,
            diff: $diff,
            reason: 'not_load_bearing',
        );
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $checks
     *
     * @return list<CheckResult>
     */
    private function runAll(Sandbox $sandbox, array $checks): array
    {
        $results = [];
        foreach ($checks as [$binary, $arguments]) {
            $results[] = $sandbox->run($binary, $arguments);
        }

        return $results;
    }

    /** @param list<CheckResult> $results */
    private function allPassed(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->failed()) {
                return false;
            }
        }

        return [] !== $results;
    }

    /** @param list<CheckResult> $results */
    private function allFailed(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->passed()) {
                return false;
            }
        }

        return [] !== $results;
    }
}
