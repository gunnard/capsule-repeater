# Capsule Repeater

**A verification gate for AI-written patches.**

The model proposes. The code decides the verdict.

```
VERIFIED    the patch is load-bearing: base fails, head passes, revert fails again
UNVERIFIED  a patch was produced but could not be proven
NO_PATCH    the bug could not be reproduced, so no honest patch exists
BLOCKED     a guardrail refused: injection, sandbox escape, patch over budget
```

Most AI coding tools stop when the tests turn green. That is a weak signal: a model
that cannot pass a test can often edit the test. Capsule Repeater does not ask
whether the tests pass. It asks whether **the patch is the reason they pass** — and
it proves that by removing the patch again.

---

## Run it

Requires PHP 8.4.1+ and Composer. Nothing else: no database, no API key, no Docker.

```bash
git clone https://github.com/gunnard/capsule-repeater
cd capsule-repeater
composer install

# A bundled bug that genuinely fails, with a patch that genuinely fixes it
php bin/capsule check \
  --repo=fixtures/shipping \
  --issue=fixtures/shipping/issue.md \
  --test-command="php check.php" \
  --patch=fixtures/shipping/fix.patch
```

Expected: `VERIFIED`, exit code `0`, and three phases in the output — base FAIL,
head PASS, revert FAIL.

```bash
# The gate also has to say no. Six frozen cases, six correct refusals.
php bin/capsule suite
```

Expected: `6/6`, including five cases that must *not* be `VERIFIED`.

```bash
# Its own test suite
composer test
```

Expected: `80 tests, 160 assertions`, all passing.

---

## The model path

The deterministic run above takes a patch as input. Without `--patch`, the gate
asks a model to write the fix, and judges it the same way.

```bash
export OPENAI_API_KEY=sk-...
export CAPSULE_MODEL=gpt-4o   # gpt-4o-mini is cheaper but does not complete this task

php bin/capsule check \
  --repo=fixtures/shipping \
  --issue=fixtures/shipping/issue.md \
  --test-command="php check.php" \
  --author-tests
```

`--author-tests` is the TDD gate. Before the model is allowed to patch anything,
it must write a test that **fails on the unpatched code**. That test is then
installed in the sandbox for all three phases, so the differential proof runs
against a reproduction neither the model nor the patch can touch. The authored
test is deliberately kept out of the diff: if it travelled with the patch,
reverting the patch would delete the test and the revert phase would fail for
the wrong reason.

### Flags

| Flag | Effect |
|---|---|
| `--patch=FILE` | Verify this patch deterministically; no model is called |
| `--author-tests` | Require a failing reproduction before patching (model-driven) |
| `--auto-discover` | Detect the test command from the repository's own config |
| `--max-blast-radius=N` | `BLOCKED` if the patch touches more than N consumers |
| `--max-cost=USD` | `BLOCKED` once measured spend passes the ceiling |
| `--json` | Full evidence as JSON, including token usage and cost |

Cost and wall time are informational unless a ceiling is passed; they are
reported either way, because a verification run that costs $0.04 and one that
costs nothing are different claims.

---

## What it proves, and how

A verdict rests on three runs of the same check, in a throwaway copy of the repo:

| Phase | State | Required result | What a failure means |
|---|---|---|---|
| Base | unpatched | must **FAIL** | the bug is not reproduced → `NO_PATCH` |
| Head | patched | must **PASS** | the patch does not fix it → `UNVERIFIED` |
| Revert | patch removed | must **FAIL** | the patch was decorative → `UNVERIFIED` |

The third row is the one that matters. A patch that keeps the tests green after you
remove it did not fix anything; the tests were already passing, or the patch changed
something that was never load-bearing. Conventional "tests pass" tooling cannot
distinguish these cases. A differential proof can.

### The guardrails are part of the claim

`VERIFIED` is only meaningful if the exam cannot be rewritten. So:

- **`ReproductionGuard`** refuses any patch touching tests, test config, CI config,
  `composer.json`/`composer.lock`, or the file the check command actually executes.
  Without it, an agent told to "make the tests pass" can simply make the tests agree.
- **`InjectionGuard`** scans the issue text for prompt injection before it reaches a
  model. A detected attempt is `BLOCKED`, not silently stripped, because "the model
  ignored it" is not something anyone can prove.
- **`PatchGuard`** caps a patch at 150 changed lines and 8 files. A 200-line rewrite
  is not a one-line fix, whatever the tests say.
- **`Sandbox`** confines every path, refuses any binary outside `php|composer|git|rg`,
  and never uses a shell. The run happens in a `git` copy, so verification can never
  modify the repository it is verifying.

Exit codes are the interface: `0` verified, `1` unverified, `2` no patch, `3` blocked.
A CI job reads the number, not the prose.

---

## What Symfony AI and the PHP MCP SDK made possible

This is the part that was not buildable a month ago.

**The agent is an MCP *client* of this application's own MCP server.** It has no
filesystem access, no shell, and no way to apply a patch except through seven tools
served over the Model Context Protocol by
[`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk):

```
repo_list_files  repo_search  repo_read_file  repo_git_status
repo_git_diff    repo_apply_patch            repo_run
```

That topology is the security model, not a style choice. The capability boundary is
the protocol, so a refusal is enforced by the transport rather than by asking the
model nicely:

```yaml
ai:
    agent:
        repair:
            tools:
                services:
                    - mcp_server: 'capsule.repository'
```

Wiring is [`symfony/mcp-bundle`](https://github.com/symfony/ai) for the server and
client, `symfony/ai-mcp-tool` for the toolbox, and Symfony AI's agent as the reasoning
loop. The same seven tools are reachable from any MCP client — Claude Code, the MCP
Inspector — because there is exactly one implementation of the surface.

**Before this, the honest options were worse.** A framework-internal tool registry is
a convention: the model *usually* respects it, and a prompt injection *sometimes*
gets around it. A separate subprocess with its own permissions is the real boundary,
but writing one in PHP meant hand-rolling JSON-RPC, capability negotiation, and
schema generation. `mcp/sdk` 0.9 plus `symfony/mcp-bundle` 1.0.0-BETA1 is the first
combination where the boundary is a dependency rather than a project.

The verdict itself needs none of this — `DifferentialValidator` is 150 lines of PHP
and three `proc_open` calls. What the stack unlocks is the ability to run an
**untrusted** patch generator against a **trusted** verifier in the same process,
which is the only configuration where the result means anything.

See [`docs/pre-release-findings.md`](docs/pre-release-findings.md) for what broke
while building against the pre-release packages — two of which are now filed
upstream ([php-sdk#746](https://github.com/modelcontextprotocol/php-sdk/issues/746),
[symfony/ai#2965](https://github.com/symfony/ai/issues/2965)).

---

## Layout

```
src/Verdict/         Verdict enum (exit codes), Evidence, CheckResult
src/Verify/          the gate: differential validator, three guards, the runner
src/Sandbox/         disposable copy, path confinement, shell-free execution
src/Mcp/Tool/        the seven #[McpTool] tools — the agent's entire surface
src/Command/         check (one patch), suite (the corpus)
corpus/              six frozen cases, four of which must be refused
fixtures/shipping/   a real bug with a real fix and a real test
tools/               corpus generator (patches built with git diff, never by hand)
```

The corpus is generated by [`tools/build-corpus.php`](tools/build-corpus.php). Patches
come from applying a fix and running `git diff`, because a hand-written hunk header
drifts from reality and teaches a benchmark to lie.

---

## Configuration

`.env` is loaded automatically. For the MCP tool surface only:

```bash
CAPSULE_SANDBOX=/path/to/repo     # required; there is deliberately no default
```

The sandbox has no default root on purpose. A sandbox rooted at the project
directory would let the agent patch the tool that judges it.

`check` and `suite` do not need any of this — they take `--repo` explicitly and need
no model. Wiring a model-driven repair loop is in
[`src/Agent/RepairAgent.php`](src/Agent/RepairAgent.php) and needs a provider key:

```bash
ANTHROPIC_API_KEY=...   # or OPENAI_API_KEY
```

Full disclosure: the model-driven path is wired but not demoed. Everything above —
the four verdicts, the corpus, the tests — is deterministic and runs with no API key.

---

## Status

Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon), October–November 2026.

Verified against `mcp/sdk` v0.9.0 and `symfony/ai-*` v1.0.0-BETA1.

MIT.
