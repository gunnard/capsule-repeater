# Capsule Repeater — 90-second video script

**Hard limit:** the showcase truncates at **1:30**. Every beat must land by **1:25**.
**Positioning:** not an issue-to-patch assistant — *a verification gate for AI-written patches.*
**Thesis:** the model proposes, the code decides the verdict.

Every command below has been run against `main` at `75121d6`. Every number on
screen is a real number from a real run. Reproduce them; do not type ahead of them.

---

## One sentence (first 8 seconds)

> Every AI coding agent will tell you it fixed the bug. Almost none of them can prove it.

---

## Beat sheet

| Time | What is on screen | Narration (~150 wpm) |
|---|---|---|
| **0:00–0:10** | Empty terminal, dark. Title fades in over it. | "Every AI coding agent will tell you it fixed the bug. Almost none of them can prove it." |
| **0:10–0:22** | `fixtures/shipping/src/ShippingCalculator.php`, lines 18–30. Flash the inverted comparison. Then `fixtures/shipping/issue.md`. | "Here's a real one. The threshold comparison is inverted, so small carts ship free and carts over fifty dollars get charged. The issue says exactly what should happen — and the test that covers it is red." |
| **0:22–0:34** | Deterministic run (below). Banner, table, then the three phase blocks. | "Base: fails. Patch applied, head: passes. Revert the patch, and it fails again. That's the whole product — the patch is *load-bearing*, not just present." |
| **0:34–0:38** | Same terminal, cut. | "But that run was handed a patch. The real question is what happens when a model writes it." |
| **0:38–1:04** | The model run (below). Authoring block → baseline FAIL line → patch proposal → VERIFIED table. | "So it authors the test first, and proves it fails on the broken code before it is allowed to touch anything. Then the model proposes a fix through MCP tools. And it still has to survive the same three phases. The model proposes; the code decides." |
| **1:04–1:14** | `php bin/capsule suite` — the six-case table, then the score block. | "And it has to say no. Six frozen cases, six correct verdicts, five of them refusals. A decorative patch, a rewritten test, an injection attempt." |
| **1:14–1:22** | `corpus/injection` run, cut fast — `BLOCKED` and the named finding. | "The refusal names what it found and where." |
| **1:22–1:25** | Repo URL card. | "The verdict comes from code, not from confidence." |

---

## Exactly what to type

### Deterministic run (0:22)

```bash
php bin/capsule check \
  --repo=fixtures/shipping \
  --issue=fixtures/shipping/issue.md \
  --test-command="php check.php" \
  --patch=fixtures/shipping/fix.patch
```

Real output, ~0.38s, exit 0:

```
  Verdict        VERIFIED
  Meaning        proven load-bearing
  Load-bearing   yes
  Cost           none (no model called)
  Wall time      0.38s

Base (must fail)      FAIL  php check.php (exit 1)
Head (must pass)      PASS  php check.php (exit 0)
Revert (must fail)    FAIL  php check.php (exit 1)
```

### Model run (0:38) — **pre-warm this before recording**

```bash
CAPSULE_MODEL=gpt-4o php bin/capsule check \
  --repo=fixtures/shipping \
  --issue=fixtures/shipping/issue.md \
  --test-command="php check.php" \
  --author-tests
```

Real output shape, ~13s, exit 0:

```
Authoring a reproduction test
  ✓ Reproduction written to tests/ReproductionTest.php and confirmed
    failing on unpatched source.
    baseline: subtotal $10.00 pays the flat fee: expected 6.95, got 0.00

Proposing a patch via MCP tools

  Verdict        VERIFIED
  Cost           $0.043627 (2 call(s), 14415 tokens, gpt-4o-2024-08-06)
  Wall time      13.28s

Reproduction     tests/ReproductionTest.php
Base (must fail)   FAIL
Head (must pass)   PASS
Revert (must fail) FAIL
```

**Do not narrate the cost figure.** It varies a dollar-fraction per run. Let it
scroll; if you must mention it, say "about four cents."

### Corpus (1:04)

```bash
php bin/capsule suite
```

Real output: `Score 6/6`, `Refused 5 of 6 (not VERIFIED)`, and the six rows —
`verified`, `decorative`, `no-patch`, `exam-rewrite`, `injection`, `overreach`.

### Injection (1:14)

```bash
php bin/capsule check \
  --repo=corpus/injection \
  --issue=corpus/injection/issue.md \
  --test-command="php check.php" \
  --patch=corpus/injection/patch.diff
```

Real output, exit 3, instant:

```
  Verdict        BLOCKED
  Meaning        refused by guardrail

Limits
 * instruction override at corpus/injection/issue.md:3
```

---

## Facts to get right (the stale Desktop script got all of these wrong)

| Item | Reality |
|---|---|
| Command | `php bin/capsule check` — there is no `verify --revert` subcommand; the differential proof is *inside* `check` |
| Corpus | **6 cases**, 6/6, **5 refused** — not "9/12" |
| Test suite | **80 tests, 160 assertions** |
| MCP tools | **8**, including `repo_write_file`; there is no `repo.search`/`repo.read_file` |
| Bug | inverted shipping threshold — not `calculateTotal()` returning zero |
| Zero-arg invocation | `capsule check` auto-detects repo (cwd), issue file, and test command — no flags needed |
| MCP tool | `verify_patch` exposes the gate itself, so any MCP client can call it directly |
| Toolbelt framing | the capability boundary is the protocol: the agent has no filesystem access of its own |

## Production rules

- One continuous take of the real tool. No fabricated terminal output — the cost and time figures are the proof that it ran.
- Terminal ≥16pt, dark, notifications off, no API keys visible (`export OPENAI_API_KEY` first; do not `cat .env`).
- No browser UI. The terminal is the product.
- Never cut the revert beat. It is the entire argument.
- End by 1:25 so the repo URL is not truncated.

## Submission copy

**Name:** Capsule Repeater

**One paragraph:**
> Capsule Repeater is a verification gate for AI-written patches. Given a bug
> report, an agent driven by Symfony AI investigates a sandboxed repository
> through tools served by the official PHP MCP SDK — list, search, read, write,
> diff, apply, run — and refuses to call the work done until it is *proven*. If
> no test covers the bug it authors one first and shows it failing on the
> unpatched code; then, after the patch, it reverts the fix and requires the
> check to fail again. Only then does it emit `VERIFIED`. Every run ends in a
> machine-checkable verdict with evidence, cost, and stated limits, and
> `php bin/capsule suite` scores the gate against a frozen corpus of bug cases so
> a regression in the harness is caught too. The model proposes; the code decides.

**Tags:** `Agent`, `MCP`, `Testing`
**Repo:** https://github.com/gunnard/capsule-repeater
