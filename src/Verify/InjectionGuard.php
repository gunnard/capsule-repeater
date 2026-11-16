<?php

declare(strict_types=1);

namespace App\Verify;

/**
 * Scans untrusted text (repository content, issue text) for prompt-injection
 * attempts before it reaches the model.
 *
 * A payload is never silently stripped: a detected attempt is a `BLOCKED`
 * verdict, because "the model ignored it" is not something we can prove.
 */
final class InjectionGuard
{
    /**
     * Patterns that indicate an attempt to redirect the agent rather than
     * describe a bug. Kept narrow on purpose: false positives here cost a run.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const PATTERNS = [
        ['/\bignore\s+(all\s+)?(previous|prior|above)\s+(instructions?|prompts?|rules?)\b/i', 'instruction override'],
        ['/\bdisregard\s+(all\s+)?(previous|prior|above)\b/i', 'instruction override'],
        ['/\byou\s+are\s+now\s+(a|an|the)\b/i', 'persona reassignment'],
        ['/\b(new|updated)\s+(system\s+)?instructions?\s*:/i', 'injected instructions'],
        ['/\bsystem\s*:\s*/i', 'injected system turn'],
        ['/\badd\s+(an?\s+)?(unauthenticated|backdoor|admin|root)\b/i', 'backdoor request'],
        ['/\b(exfiltrate|leak|send)\s+(the\s+)?(secrets?|tokens?|keys?|env)\b/i', 'credential exfiltration'],
        ['/<\|im_start\|>|<\|system\|>|\[\/INST\]/i', 'chat-template injection'],
        ['/\brm\s+-rf\s+\//i', 'destructive command'],
    ];

    /**
     * @return list<array{rule: string, file: string, line: int, excerpt: string}>
     */
    public function scan(string $text, string $source = 'input'): array
    {
        $findings = [];
        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            foreach (self::PATTERNS as [$pattern, $rule]) {
                if (1 === preg_match($pattern, $line)) {
                    $findings[] = [
                        'rule' => $rule,
                        'file' => $source,
                        'line' => $index + 1,
                        'excerpt' => $this->redact(trim($line)),
                    ];
                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * Never echo an injection payload back into a log or a prompt in full.
     */
    private function redact(string $line, int $limit = 120): string
    {
        return \strlen($line) <= $limit ? $line : substr($line, 0, $limit).'…';
    }
}
