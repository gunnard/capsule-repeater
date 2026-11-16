# Pre-release findings

Building against `symfony/ai` v1.0.0-BETA1 and `mcp/sdk` v0.9.0. Everything below
is a real failure encountered while building this project, not a review comment.

Verified: **2026-11-16** · PHP 8.5.8 · Composer 2.10.0 · macOS 26.1

Two of these were filed upstream:

- [modelcontextprotocol/php-sdk#746](https://github.com/modelcontextprotocol/php-sdk/issues/746) — docs import a namespace that does not exist
- [symfony/ai#2965](https://github.com/symfony/ai/issues/2965) — `mcp_server` toolbox config rejects empty prefix, and the package check masks the config error

---

## 1. The MCP SDK docs name a namespace that does not exist

`docs/get-started/first-server.md` and the SDK README both show:

```php
use Mcp\Server\Capability\Attribute\McpTool;
```

That namespace is not in v0.9.0. The class lives at:

```php
use Mcp\Capability\Attribute\McpTool;
```

The server-side namespaces were flattened; the docs still describe the old tree.
Copying the quickstart verbatim is a fatal error, and the error message points at
the `use` statement rather than at the docs, so it reads like a typo on your side.

Also listed in the docs but absent from `src/Server/Capability/Attribute/`:
`McpResource`, `McpPrompt`, `McpResourceTemplate` — all four moved to
`Mcp\Capability\Attribute`.

**Suggested fix:** update the quickstart code block, and add a `class_alias` shim
for one release so the documented import keeps working.

---

## 2. `mcp/sdk` 0.10 exists but no Symfony AI package can use it

`mcp/sdk` v0.10.0 is released. Both Symfony AI packages that integrate with it pin
the previous minor:

```
symfony/ai-mcp-tool  v1.0.0-BETA1 requires mcp/sdk ^0.9
symfony/mcp-bundle   v1.0.0-BETA1 requires mcp/sdk ^0.9
```

So requiring `^0.10` in a Symfony AI project fails resolution with a conflict that
names neither package's incompatibility, only the version mismatch:

```
symfony/ai-mcp-tool v1.0.0-BETA1 requires mcp/sdk ^0.9
  -> found mcp/sdk[v0.9.0] but it conflicts with your root composer.json require (^0.10)
```

Not a bug exactly — a release-ordering hazard that will bite every project during
the beta window. Worth a compatibility note in both READMEs.

**Workaround used here:** pin `mcp/sdk ^0.9`.

---

## 3. `agent.tools.services[].mcp_server` fails in two different ways at once

Configuring an agent to use an MCP server as its toolbox:

```yaml
ai:
    agent:
        repair:
            tools:
                services:
                    - mcp_server: 'capsule.repository'
```

produces, in this order:

**(a)** `The path "ai.agent.repair.tools.services.0.prefix" cannot contain an empty value, but got "".`
Setting `prefix: ''` — the obvious way to say "do not prefix my tool names" — is
rejected. The hint says it defaults to `<server>_`, but does not say the empty
string is invalid rather than equivalent to the default.

**(b)** After removing it: `The "mcp_server" tool configuration requires
"symfony/ai-mcp-tool" package. Try running "composer require symfony/ai-mcp-tool".`

That check is `ContainerBuilder::willBeAvailable('symfony/ai-mcp-tool', ...)`.
Installing the package with `composer require symfony/ai-mcp-tool "^1.0@beta"`
makes the error go away — so the message is accurate, but the *first* error sent me
looking for `symfony/ai-mcp-tool` before the config error was fixed, and both had to
be resolved before the container compiled. Two round trips for one feature.

**Suggested fix:** allow `prefix: ''`, and validate tool-configuration problems
before package availability.

---

## 4. `ai.yaml` cannot `import: services.yaml` — and the error suggests it can

Standard Symfony config layout:

```
config/services.yaml
config/packages/ai.yaml      # imports ../services.yaml
```

An `imports: - { resource: services.yaml }` inside `config/packages/ai.yaml`
resolves relative to `config/packages/`, so it looks for
`config/packages/services.yaml` and fails with:

```
The file "services.yaml" does not exist (in: ".../config/packages")
```

Correct, but the message appears while loading `ai.yaml` and names `services.yaml`,
which reads as though `services.yaml` were missing from the project rather than
mis-pathed from the import. A `resource: ../services.yaml` works.

Minor, but it cost a debugging cycle during initial setup, and nothing in the
Symfony AI docs shows a config layout at all.

---

## 5. `#[Option]` on a console command parameter must declare a default

Symfony Console 8 with invokable commands:

```php
#[Option(description: 'Repository to verify')]
string $repo,          // ← fatal
```

> The option parameter "$repo" of "App\Command\CheckCommand::__invoke()" must
> declare a default value.

The error is clear; the constraint is not documented next to `#[Option]`. A required
option has to be modelled as `?string $repo = null` plus a runtime check, or with a
default value. Fine once known.

Related: `Application::add()` was removed in Console 8; `addCommand()` is the
replacement. Not mentioned in the upgrade notes I could find.

---

## What worked, and deserves saying

These were genuinely smooth, and they are the reason the project exists:

- **A Symfony AI agent as an MCP client of its own application's MCP server** —
  `ai.agent.*.tools.services[].mcp_server` plus `mcp.clients.*` is one config block,
  and it works. No hand-written JSON-RPC, no transport code.
- **STDIO round trip through the real bundle** — `debug:mcp --client=capsule` starts
  the server as a subprocess, negotiates capabilities, and lists all seven tools.
- **`#[McpTool]` on an ordinary service method.** Schema generation from the PHP
  signature and DocBlock is accurate enough that a real client call succeeds first try.
- **`mcp:server` handles the STDIO discipline** — a single `echo` in the wrong place
  corrupts the protocol, and the bundle's transport does not write to STDOUT.

The single most valuable thing in the beta set is the client half. Every MCP story in
PHP so far has been "expose a server"; being able to *consume* one from an agent, in
the same application, without writing transport code, is what made the architecture
here possible.
