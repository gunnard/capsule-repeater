# Capsule Repeater

**Agentic issue-to-patch assistant for PHP repos** — powered by `symfony/ai-bundle` and the official PHP MCP SDK (`mcp/sdk`).

> *Contained power, on repeat.* — investigate a bug, propose a fix, validate it with real tools, repeat until it holds.

---

## What it does

Capsule Repeater turns a bug report into a **reviewable, validated patch**. Instead of asking an LLM to guess at a fix, it gives the agent a standard, inspectable toolbelt over MCP:

- **`repo.list_files` / `repo.search` / `repo.read_file`** — understand the codebase
- **`repo.git_status` / `repo.git_diff`** — see what's changed
- **`repo.apply_patch`** — propose/apply a scoped fix in a sandboxed work copy
- **`repo.run`** — execute allowlisted checks (PHPUnit, PHPStan, Symfony lint/test tasks)

The agent layer (Symfony AI) orchestrates the loop: **investigate → plan → patch → validate → report**, iterating when checks fail. It returns a unified diff plus a structured summary — what changed, what was verified (with command/test evidence), and what remains unverified.

This is more than a chatbot: it's a grounded engineering workflow where the model proposes, but repository tools and test results provide evidence.

## What Symfony AI + PHP MCP SDK unlocked

- **MCP (`mcp/sdk`)** unlocked a portable, protocol-defined interface to repository and validation tools. Tool use becomes explicit, reusable, and safer than ad-hoc shell prompting — any MCP-capable client can reuse the same toolbelt.
- **Symfony AI (`symfony/ai-bundle`)** unlocked an agent that can orchestrate multi-step reasoning over those tools within an idiomatic Symfony application/bundle.
- Together they enable a grounded, verifiable **issue-to-patch loop** with clear confidence and limits.

## Quick start (5 minutes)

```bash
git clone https://github.com/gunnard/capsule-repeater.git
cd capsule-repeater
composer install
cp .env.example .env   # set API key / provider
php bin/console app:demo   # runs the bundled bug case end-to-end
```

### Requirements
- PHP 8.3+
- Composer 2+
- Symfony CLI (optional, for the dev server)
- An LLM API key (OpenAI, Anthropic, or Hugging Face Inference Provider — see `.env.example`)

## Architecture

```
src/
├── Agent/              # Symfony AI agent definition + orchestration
├── Mcp/
│   ├── Server/         # PHP MCP SDK server bootstrap
│   └── Tools/          # repo.* tool implementations
├── Service/
│   ├── PatchService    # scoped patch generation/application
│   └── ValidationService # allowlisted command execution
└── Command/
    └── DemoCommand     # CLI entry point for the bundled case
```

## Guardrails

- **Path allowlist:** reads/writes confined to the project/sandbox directory.
- **Command allowlist:** only approved test/lint commands can run via `repo.run`.
- **No destructive ops:** no `rm`, no force-push, no arbitrary shell.
- **Evidence-based reporting:** every result includes what was verified and what was not.

## Demo scenario

The repo ships with a small sample PHP project containing a known bug and a failing test:

1. Run `php bin/console app:demo`
2. The agent investigates the repo via MCP tools (search/read/diff)
3. Proposes a scoped fix and applies it to a sandboxed work copy
4. Runs the test suite via `repo.run`
5. Outputs a unified diff + structured report (verified vs. unverified, with evidence)

## Tags

`Agent` · `MCP` · (add `RAG` only if you implement retrieval over issues/docs)

## License

MIT — see [LICENSE](LICENSE).

---

*Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon) (Oct 26 – Nov 22, 2026).*
