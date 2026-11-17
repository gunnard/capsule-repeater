# Capsule Repeater — Development Plan v2

**Base:** current `main` (commit 6db9093+), all P0/P1 core features shipped.
**Goal:** close the gap between "verification gate" and "verification gate that
tells you what else could break, finds its own tests, and stays within budget."

---

## Priority stack (revised)

| # | Feature | Impact | Effort | Status |
|---|---|---|---|---|
| P1-9 | **Blast radius: consumer cross-reference** | ★★★★☆ | 3h | **start now** |
| P0-2 | **Test-authoring phase (TDD gate)** | ★★★★★ | 5h | after P1-9 |
| P2-10 | **Cost tracking + `--max-cost` gate** | ★★★☆☆ | 2h | after P0-2 |
| P2-13 | **Test discovery (auto-detect `--test-command`)** | ★★★☆☆ | 3h | after P2-10 |
| P2-11 | **Playwright / AIO binary allowlist** | ★★☆☆☆ | 1h | after P2-13 |
| P2-14 | **Evidence bundles (hash-chained, tamper-evident)** | ★★☆☆☆ | 3h | cut unless time |
| P3-15 | **Web Profiler "Agent Trace" panel** | ★★☆☆☆ | 4h | cut |

**Cut line:** anything below P2-13 unless time permits. Never cut P1-9 or P0-2.

---

## Day 1 — Blast radius consumer analysis (P1-9)

**Goal:** When a patch touches `ShippingCalculator::shippingFor`, the evidence
should say "and 47 other files call it" — not just "it changed."

### Tasks
1. Create `src/Verify/BlastRadius.php`:
   - Input: `Sandbox`, list of changed files, list of changed symbols
   - Step 1: extract changed symbols (already exists in `VerificationRunner::blastRadius`)
   - Step 2: for each symbol, `rg --word-regexp --fixed-strings <symbol>` across the repo (excluding vendor, tests, .git)
   - Step 3: collect every file that references the symbol, excluding the file that defines it
   - Output: `BlastRadiusReport` with `symbols[]`, `consumers[]` (file + line), `totalConsumers`, `riskLevel` (low/medium/high based on consumer count)
2. Wire into `VerificationRunner::verify()` → `Evidence.blastRadius` becomes a structured object, not a flat list of symbol names
3. Update `Evidence::toArray()` to emit the structured blast radius
4. Add `--max-blast-radius=N` option to `CheckCommand` (default: unlimited) — if consumer count exceeds N, verdict is `BLOCKED` with reason `blast_radius_exceeded`
5. Unit tests: mock sandbox, assert consumer detection, risk levels, threshold blocking

### Done when
- `php bin/capsule check ... --json` shows `blastRadius.consumers` with file:line entries
- `--max-blast-radius=5` on a patch touching a widely-used symbol returns `BLOCKED`

---

## Day 2 — Test-authoring phase (P0-2)

**Goal:** "We can't verify a fix we can't reproduce." The agent writes the failing
test first, proves it fails, then patches.

### Tasks
1. Create `src/Phase/TestAuthorPhase.php`:
   - Input: issue text, sandbox, detected test framework (PHPUnit/Pest/none)
   - Prompt: "Write a test that reproduces this bug. Do not modify source code."
   - Constrain output: test file path must be under detected test root
   - Write to scratch path first, `php -l` to verify syntax, then place
   - Run the test on unpatched source → must FAIL (baseline). If PASS: discard, retry once, then `NO_PATCH`
2. Feed the failing test + failure output into `PatchPhase` as context
3. Guardrail: never overwrite existing tests (check `file_exists` before write)
4. Wire into `CheckCommand` as a `--author-tests` flag (default: false for deterministic, true for model-driven)
5. Unit tests: mock the agent, assert baseline-fail requirement, retry-on-pass, no-overwrite

### Done when
- Model-driven run shows "authored test → FAIL (baseline)" in the trace
- Patch phase receives the test failure output as context

---

## Day 3 — Cost tracking + `--max-cost` gate (P2-10)

**Goal:** Know what the agent spent, and stop it mid-loop if it exceeds budget.

### Tasks
1. Add `cost` and `wallTime` fields to `Evidence` DTO
2. Track token usage from Symfony AI platform responses (`ResultInterface::getMetadata()`)
3. Add `--max-cost=N` option to `CheckCommand` (default: unlimited)
4. If cumulative cost exceeds N mid-loop: abort, verdict `BLOCKED`, reason `cost_exceeded`
5. Add `cost` and `wallTime` to `--json` output and human report
6. Add to suite score table: per-case cost and wall time
7. Unit tests: mock cost tracking, assert threshold blocking

### Done when
- `php bin/capsule check ... --max-cost=0.01` aborts with `BLOCKED · cost_exceeded` on a model that would exceed
- `--json` includes `cost` and `wallTime`

---

## Day 4 — Test discovery (P2-13)

**Goal:** Point Capsule Repeater at any repo and have it figure out how to test.

### Tasks
1. Create `src/Service/TestDiscovery.php`:
   - Scan repo root for known test configs:
     - `phpunit.xml` / `phpunit.xml.dist` → `php vendor/bin/phpunit`
     - `composer.json` scripts.test → `composer test`
     - `playwright.config.ts` / `playwright.config.js` → `npx playwright test`
     - `package.json` scripts.test → `npm test`
     - `.github/workflows/*.yml` → extract `run:` steps
   - Return `?string $testCommand` (null if nothing found)
2. Add `--auto-discover` flag to `CheckCommand` (default: false)
   - If `--test-command` not provided and `--auto-discover` set: run discovery
   - If discovery finds nothing: error with helpful message
3. Add `node`, `npx`, `npm` to `Sandbox::ALLOWED_BINARIES` (behind a config flag `--allow-node`)
4. Unit tests: mock filesystem, assert detection for each config type

### Done when
- `php bin/capsule check --repo=some-repo --auto-discover` finds and runs the right test command
- Playwright tests can run (with `--allow-node`)

---

## Day 5 — Polish + commit + push

### Tasks
1. Run full test suite, fix any regressions
2. Update README with new features
3. Update corpus with new test cases (blast radius, cost gate)
4. Push to `main`
5. Update video script if needed

---

## Architecture after v2

```
src/
├── Command/
│   ├── CheckCommand.php        # + --max-cost, --auto-discover, --max-blast-radius
│   └── SuiteCommand.php
├── Phase/
│   ├── TestAuthorPhase.php     # NEW — writes failing test first
│   └── (existing phases)
├── Service/
│   ├── BlastRadius.php         # NEW — consumer cross-reference
│   ├── TestDiscovery.php      # NEW — auto-detect test command
│   └── (existing)
├── Verify/
│   ├── DifferentialValidator.php
│   ├── VerificationRunner.php  # + blast radius, cost tracking
│   └── (existing guards)
└── Verdict/
    └── Evidence.php            # + cost, wallTime, structured blastRadius
```

---

## What NOT to do (learned from v1)

- Don't add multi-agent voting — the verifier must stay deterministic
- Don't add a web UI — the terminal IS the product
- Don't add hash-chained evidence bundles unless time permits
- Don't expand the binary allowlist beyond what's needed for the specific test runner
