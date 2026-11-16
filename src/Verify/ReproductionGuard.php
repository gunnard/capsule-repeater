<?php

declare(strict_types=1);

namespace App\Verify;

/**
 * Refuses patches that touch the reproduction itself.
 *
 * This closes the single most dangerous failure mode in the whole system: an
 * agent that cannot make the test pass can make the test agree with it. Without
 * this guard, `VERIFIED` would mean "the model edited its own exam", and every
 * downstream claim would be worthless.
 *
 * The reproduction is the specification. A patch may change the code under
 * test; it may never change the thing that decides whether the code is correct.
 *
 * Protection comes from two sources, and both are needed: the conventional
 * locations below, and the files the check command actually executes — a
 * corpus case whose check is `check.php` is no less a specification than one
 * whose check lives in `tests/`.
 */
final class ReproductionGuard
{
    /**
     * Conventional locations of a reproduction. Deliberately broad: a false
     * refusal costs one run, a false allow costs the integrity of the verdict.
     *
     * @var list<string>
     */
    private const PROTECTED_PATTERNS = [
        '#(^|/)tests?/#i',
        '#Test\.php$#i',
        '#(^|/)phpunit\.xml(\.dist)?$#i',
        '#(^|/)phpstan\.neon#i',
        '#(^|/)psalm\.xml#i',
        '#(^|/)behat\.ya?ml$#i',
        '#(^|/)codeception\.ya?ml$#i',
        '#(^|/)\.github/workflows/#i',
        '#(^|/)composer\.json$#i',
        '#(^|/)composer\.lock$#i',
    ];

    /**
     * @param list<string> $patchPaths    files the patch claims to modify
     * @param list<string> $executedPaths files the check command runs
     *
     * @return array{allowed: bool, reasons: list<string>, protectedPaths: list<string>}
     */
    public function inspect(array $patchPaths, array $executedPaths = []): array
    {
        $hits = [];

        foreach ($patchPaths as $path) {
            if ($this->isProtected($path, $executedPaths)) {
                $hits[] = $path;
            }
        }

        $reasons = [];
        if ([] !== $hits) {
            $reasons[] = \sprintf(
                'Patch modifies the reproduction (%s). A fix may change the code, never the check that judges it.',
                implode(', ', $hits),
            );
        }

        return [
            'allowed' => [] === $reasons,
            'reasons' => $reasons,
            'protectedPaths' => $hits,
        ];
    }

    /**
     * @param list<string> $executedPaths
     */
    private function isProtected(string $path, array $executedPaths): bool
    {
        $normalised = ltrim($path, '/');

        foreach ($executedPaths as $executed) {
            if (ltrim($executed, '/') === $normalised) {
                return true;
            }
        }

        foreach (self::PROTECTED_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $normalised)) {
                return true;
            }
        }

        return false;
    }
}
