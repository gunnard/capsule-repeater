<?php

declare(strict_types=1);

namespace App\Phase;

/**
 * A reproduction test that was authored for one bug.
 *
 * This travels from the authoring phase into the verifier, which installs it in
 * the sandbox *before* the base run and keeps it there across base, head and
 * revert. That placement is the whole point: the patch under test is only ever
 * the source change, so reverting it cannot quietly remove the test and make the
 * final phase fail for the wrong reason.
 */
final readonly class Reproduction
{
    /**
     * @param list<array{0: string, 1: list<string>}> $checks how to run this test
     */
    public function __construct(
        public string $path,
        public string $contents,
        public array $checks,
        public string $baselineOutput = '',
    ) {}
}
