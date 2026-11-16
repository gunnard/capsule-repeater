<?php

declare(strict_types=1);

namespace App\Sandbox;

use RuntimeException;

/**
 * Raised when anything tries to leave the sandbox, run a non-allowlisted
 * binary, or otherwise cross a boundary the agent must not cross.
 */
final class SandboxViolation extends RuntimeException {}
