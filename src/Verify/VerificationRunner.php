<?php

declare(strict_types=1);

namespace App\Verify;

use App\Sandbox\Sandbox;
use App\Verdict\Evidence;
use App\Verdict\Verdict;

/**
 * One verification run, start to finish.
 *
 * Both `check` and `suite` go through this object, so the corpus measures the
 * real gate rather than a parallel implementation of it. That distinction is the
 * difference between a benchmark and a claim.
 */
final class VerificationRunner
{
    public function __construct(
        private readonly DifferentialValidator $validator,
        private readonly InjectionGuard $injectionGuard,
        private readonly PatchGuard $patchGuard,
        private readonly ReproductionGuard $reproductionGuard,
    ) {}

    /**
     * @param list<array{0: string, 1: list<string>}> $checks
     */
    public function verify(
        string $repoPath,
        string $issueText,
        string $issueSource,
        array $checks,
        string $patchText,
        ?int $maxBlastRadius = null,
    ): Evidence {
        // Guardrail: untrusted text is scanned before it reaches a model.
        $findings = $this->injectionGuard->scan($issueText, $issueSource);
        if ([] !== $findings) {
            return new Evidence(
                verdict: Verdict::Blocked,
                baseChecks: [],
                headChecks: [],
                revertChecks: [],
                summary: 'Refused: the issue text contains a prompt-injection attempt.',
                limits: array_map(
                    static fn (array $f): string => \sprintf('%s at %s:%d', $f['rule'], $f['file'], $f['line']),
                    $findings,
                ),
                reason: 'prompt_injection',
            );
        }

        $inspection = $this->patchGuard->inspect($patchText);

        // A patch that changes the reproduction is refused outright: the
        // differential proof is only as good as the test it runs.
        $reproduction = $this->reproductionGuard->inspect(
            $inspection['files'],
            $this->executedPaths($checks),
        );

        if (!$reproduction['allowed']) {
            return new Evidence(
                verdict: Verdict::Blocked,
                baseChecks: [],
                headChecks: [],
                revertChecks: [],
                summary: 'Refused: the patch modifies the reproduction.',
                limits: $reproduction['reasons'],
                reason: 'reproduction_modified',
                diff: $patchText,
            );
        }

        if (!$inspection['allowed']) {
            return new Evidence(
                verdict: Verdict::Blocked,
                baseChecks: [],
                headChecks: [],
                revertChecks: [],
                summary: 'Refused: the patch exceeds the minimality budget.',
                limits: $inspection['reasons'],
                reason: 'patch_budget',
                diff: $patchText,
            );
        }

        // The run happens in a throwaway copy: the repository under verification
        // is never written to, so an unverified patch cannot damage real work.
        $sandbox = Sandbox::copy($repoPath);

        try {
            $report = (new BlastRadius())->analyze($sandbox, $inspection['files']);
            if (null !== $report && null !== $maxBlastRadius && $report->exceeds($maxBlastRadius)) {
                return new Evidence(
                    verdict: Verdict::Blocked,
                    baseChecks: [],
                    headChecks: [],
                    revertChecks: [],
                    summary: 'Refused: the patch blast radius exceeds the threshold.',
                    limits: [
                        \sprintf(
                            'Patch touches %d consumers (threshold: %d). Risk level: %s.',
                            $report->totalConsumers,
                            $maxBlastRadius,
                            $report->riskLevel,
                        ),
                    ],
                    blastRadius: $report,
                    diff: $patchText,
                    reason: 'blast_radius_exceeded',
                );
            }

            return $this->evaluate($sandbox, $checks, $patchText, $inspection['files'], $report);
        } finally {
            $sandbox->destroy();
        }
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $checks
     * @param list<string>                            $files
     */
    private function evaluate(Sandbox $sandbox, array $checks, string $patchText, array $files, ?BlastRadiusReport $report = null): Evidence
    {
        return $this->validator->validateWithPatch(
            sandbox: $sandbox,
            checks: $checks,
            applyPatch: static function () use ($sandbox, $patchText): array {
                $file = tempnam(sys_get_temp_dir(), 'capsule_');
                file_put_contents($file, $patchText);

                try {
                    // --check first, so a patch that does not apply is reported
                    // rather than half-applied.
                    $check = $sandbox->run('git', ['apply', '--check', $file], 30);
                    if ($check->failed()) {
                        return ['applied' => false, 'message' => $check->output];
                    }

                    $apply = $sandbox->run('git', ['apply', $file], 30);

                    return [
                        'applied' => $apply->passed(),
                        'message' => $apply->passed() ? 'Patch applied.' : $apply->output,
                    ];
                } finally {
                    @unlink($file);
                }
            },
            revertPatch: static function () use ($sandbox): void {
                $sandbox->run('git', ['checkout', '--', '.'], 30);
            },
            summary: \sprintf('Evaluated %d check(s) across base, head and revert.', \count($checks)),
            blastRadius: $report,
            diff: $patchText,
        );
    }

    /**
     * @return list<array{0: string, 1: list<string>}>
     */
    public function parseTestCommand(string $command): array
    {
        $parts = preg_split('/\s+/', trim($command)) ?: [];
        $binary = array_shift($parts) ?: 'php';

        return [[$binary, $parts]];
    }

    /**
     * The files a check command runs, so the reproduction guard can protect
     * them wherever they live.
     *
     * @param list<array{0: string, 1: list<string>}> $checks
     *
     * @return list<string>
     */
    public function executedPaths(array $checks): array
    {
        $paths = [];

        foreach ($checks as [, $arguments]) {
            foreach ($arguments as $argument) {
                if (str_starts_with($argument, '-')) {
                    continue;
                }

                // Only arguments that name a file that exists, so `php -r ...`
                // and test filter names do not become false positives.
                if (is_file($argument) || str_ends_with($argument, '.php')) {
                    $paths[] = ltrim($argument, './');
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Every symbol the patch touches, so the report can say what else could break.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function blastRadius(Sandbox $sandbox, array $files): array
    {
        $symbols = [];

        foreach ($files as $file) {
            if (!str_ends_with($file, '.php') || !$sandbox->exists($file)) {
                continue;
            }

            $contents = $sandbox->read($file);
            if (0 !== preg_match_all('/\b(?:function|class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/', $contents, $matches)) {
                $symbols = [...$symbols, ...$matches[1]];
            }
        }

        return array_values(array_unique($symbols));
    }
}
