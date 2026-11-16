<?php

declare(strict_types=1);

namespace App\Verdict;

/**
 * The only four things a Capsule Repeater run is allowed to say.
 *
 * A verdict is a claim about what was *proven*, not about what the model
 * believes. `Verified` is reachable only through DifferentialValidator.
 */
enum Verdict: string
{
    /** The patch is load-bearing: base fails, head passes, revert fails again. */
    case Verified = 'VERIFIED';

    /** A patch was produced but could not be proven. Never treat as success. */
    case Unverified = 'UNVERIFIED';

    /** The bug could not be reproduced, so no honest patch exists. */
    case NoPatch = 'NO_PATCH';

    /** A guardrail refused: injection, sandbox escape, cost ceiling, denied command. */
    case Blocked = 'BLOCKED';

    public function exitCode(): int
    {
        return match ($this) {
            self::Verified => 0,
            self::Unverified => 1,
            self::NoPatch => 2,
            self::Blocked => 3,
        };
    }

    public function isSuccess(): bool
    {
        return self::Verified === $this;
    }

    public function humanLabel(): string
    {
        return match ($this) {
            self::Verified => 'proven load-bearing',
            self::Unverified => 'not proven',
            self::NoPatch => 'no honest patch',
            self::Blocked => 'refused by guardrail',
        };
    }
}
