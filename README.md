# Extend AI — Enterprise

Governance, policy, and audit layer for the [WordPress AI plugin](https://github.com/WordPress/ai).

> **Status:** Early. Working scaffold with one end-to-end integration verified
> on Studio. Not yet hardened for production.

The WordPress AI plugin ships a strong baseline of AI capabilities — title and
excerpt generation, content classification, summarization, alt-text, image
generation, and more — but enterprise teams need to govern those capabilities
before turning them on across a real organization.

This plugin adds that governance layer **without forking** the core AI plugin.
Every integration point is a documented filter, REST hook, or public SDK
interface. When the upstream plugin updates, you update it like any other
plugin and we keep running.

---

## 🚀 Try it in WordPress Playground

Spin up the **whole stack in your browser** — WordPress, the AI plugin, and this
governance layer — with one click. No install, no server, nothing to clean up:

**[▶ Launch the demo in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/alansmodic/extend-ai-enterprise/main/blueprint.json)**

The [blueprint](blueprint.json) preloads:

- The **WordPress AI plugin** with Title Generation, Excerpt Generation, and
  Editorial Notes switched on
- **This plugin**, built from the `main` branch of this repo
- A **site policy preamble** and a **stored prompt override** for
  `ai/title-generation`, so both prompt layers have something to show
- **Governance knobs set low enough to trip**: 5 requests/minute, a
  banned-phrase list, PII redaction on
- A **draft post** full of superlatives and jargon to run abilities against
- A Playground-only **Tools → AI Prompt Preview** page that renders the final
  system instruction — so you can see the integration working **without any AI
  provider key**

### Walkthrough: what to test once it loads

You land on **Tools → AI Enterprise**. From there:

1. **See both prompt layers — no API key needed.** Open **Tools → AI Prompt
   Preview**. It renders the system instruction `ai/title-generation` receives
   after the site policy preamble (applied on `wpai_system_instruction`) and
   the stored override (applied on `wpai_title_generation_system_instruction`).
   The page applies the two filters in the same order WP AI does: global first,
   ability-scoped second.

2. **Change the preamble, watch the prompt follow.** On **Tools → AI
   Enterprise**, edit the policy preamble, save, then reload the preview page.
   The new text is at the top of the instruction.

3. **Edit the override.** **Tools → AI Prompts** → pick `ai/title-generation`.
   Change the mode between prepend / append / replace and reload the preview to
   see how each composes against the default. Note that **replace** uses only
   your template — the preamble is not re-added on top. Every edit is written to
   the append-only history table, visible from the same screen.

4. **Trip a governance gate.** The blueprint sets the rate limit to 5/minute.
   Run any ability six times in a minute and the sixth is rejected before a
   provider call is made. Because enforcement sits on the Abilities API
   execution lifecycle rather than the REST route, the limit applies the same
   way from WP-CLI or a direct `WP_Ability::execute()` call.

5. **Run the real thing (needs a provider key).** **Settings → Connectors** →
   add an OpenAI, Anthropic, or Google key. Then open **Posts → "Why Our New
   Single-Origin Is The Best Coffee Ever"** and run Title Generation; the
   returned title should obey the 60-character override. Usage lands in
   `wp_extend_ai_usage` and shows up under the `/usage` REST route.

> **Site guidelines are not part of this demo.** The WordPress AI plugin injects
> them natively as of 1.3.0 — see [Site Guidelines](#site-guidelines) below.

> Playground sites are ephemeral — refresh the tab and everything resets to
> the blueprint state. Perfect for demos, useless for storing your API key
> long-term.

---

## What it adds

| Concern                         | Capability                                                                                          |
| ------------------------------- | --------------------------------------------------------------------------------------------------- |
| **Per-ability prompt control**  | Override any of the 11+ built-in ability prompts using WordPress AI 1.3's native per-ability filters. Prepend, append, or replace. Variable interpolation. Version history per edit. |
| **Site-wide policy preamble**   | Inject brand voice / compliance language ahead of every AI call.                                    |
| **Model allowlist**             | Restrict the AI plugin to an approved set of provider/model pairs for text, image, and vision.      |
| **PII redaction**               | Pattern-based redaction of email, SSN, phone numbers from inputs before they leave WordPress.       |
| **Role-based access**           | Per-ability allowlist of WP roles. Disable specific experiments per-role or globally.               |
| **Rate limiting**               | Per-minute and per-day quotas per user, enforced at the ability execution layer (WordPress 7.1 `wp_pre_execute_ability` filter) for all paths: REST, MCP, WP-CLI, and direct PHP. No provider spend on rejected requests. |
| **Cost tracking + caps**        | Per-user monthly spend rollup in a dedicated table. Hard cap denies all AI abilities once a user is over budget (admins exempt). Universal enforcement via `user_has_cap`. |
| **Output moderation**           | Banned-phrase scan on every ability response before it returns to the caller (WordPress 7.1 `wp_ability_execute_result` filter). Pluggable backend for richer moderation. Applies to all execution paths. |
| **Drift detection**             | Version pin + admin notice when running outside the tested WP AI range.                             |

---

## How it works

We hook WordPress AI 1.3's **native per-ability filters** and **WordPress 7.1
Abilities API lifecycle filters** — never internals. Clean integration via public APIs:

```
┌───────────────────────────────────────────────────────────────────────┐
│  WordPress 7.1 Abilities API + WordPress AI 1.3 plugin               │
│                                                                       │
│   Ability runs ──► wp_pre_execute_ability       (rate limiting)       │
│                ──► wpai_pre_normalize_content   (input scrubbing)     │
│                ──► wpai_system_instruction      (policy preamble)     │
│                ──► wpai_{slug}_system_instruction (prompt overrides)  │
│                ──► wpai_preferred_*_models      (model selection)     │
│                ──► wp_ability_execute_result    (output moderation)   │
│                ──► AiClient::defaultRegistry()->setHttpTransporter()  │
└─────┬─────────────┬───────────────┬──────────────┬──────────────┬─────┘
      │             │               │              │              │
      ▼             ▼               ▼              ▼              ▼
 Rate_Limiter  PII_Redactor   Prompt_Injector  Model_Allowlist  Output_Moderator
                                 Prompt_Library
                                                  Cost_Tracker
                                                  Transporter_Wrap
                                                       │
                                                       ▼
                                               extend_ai_request_completed
                                                       │
                                                       ▼
                                                  wp_extend_ai_usage
```

**Universal enforcement:** Rate limiting (`wp_pre_execute_ability`), output
moderation (`wp_ability_execute_result`), and spend caps (`user_has_cap`) enforce
on **all execution paths** — REST API, MCP servers, WP-CLI commands, and direct
PHP `WP_Ability::execute()` calls. No bypass routes exist.

**Native integration:** Prompt overrides use WordPress AI 1.3's per-ability
`wpai_{slug}_system_instruction` filters. Guidelines use WordPress AI's native
`wpai_use_guidelines` toggle (disable our plugin's version if you had it enabled).

Two custom tables back the moving parts:

- `wp_extend_ai_prompts` — one row per ability override (mode + template).
- `wp_extend_ai_prompts_history` — append-only audit of every prompt edit.
- `wp_extend_ai_usage` — per-user-per-month spend rollups, indexed for fast aggregation.

---

## Site Guidelines

**WordPress AI 1.3.0+ ships native Guidelines injection.**

This plugin previously provided its own Guidelines integration, but that has been
removed to avoid double-injection. Use WordPress AI's native support instead:

1. Install [Gutenberg plugin](https://wordpress.org/plugins/gutenberg/) 22.7+ and enable the Guidelines experiment
2. Define your standards under **Settings → Guidelines**
3. WordPress AI automatically appends guidelines to editorial abilities
4. Control via `wpai_use_guidelines` filter (see [WordPress AI docs](https://github.com/WordPress/ai))

Our versioned prompt library (Tools → AI Prompts) still works for all other
prompt customizations using WordPress AI 1.3's per-ability filters.

---

## Installation

### Prerequisites

- WordPress 7.1 or newer — **required**, not just recommended. The governance
  gates hook the Abilities API execution lifecycle, which ships in 7.1; on older
  releases they would be inert, so the plugin declines to activate.
- PHP 8.1 or newer
- [WordPress AI plugin](https://wordpress.org/plugins/ai/) — the **1.3.x**
  compatibility band. Tested on v1.3.0; patch releases within the
  band are expected to work (see `Compat\Version_Gate::TESTED_MAX`)

### From source

```bash
cd wp-content/plugins/
git clone https://github.com/alansmodic/extend-ai-enterprise.git
wp plugin activate ai extend-ai-enterprise
```

The activation hook installs the three tables via `dbDelta`. No manual
migration is required.

### With Studio (local development)

```bash
studio site create --name extend-ai-test
cd ~/Studio/extend-ai-test/wp-content/plugins
curl -sL -o ai.zip https://downloads.wordpress.org/plugin/ai.zip && unzip ai.zip
ln -s /path/to/extend-ai-enterprise .
studio wp plugin activate ai extend-ai-enterprise
```

---

## Configuration

### Admin UI

- **Tools → AI Enterprise** — global policy preamble, monthly user cap, log
  retention, PII redaction toggle.
- **Tools → AI Prompts** — React app listing every discovered AI ability. Click
  an ability to view its default prompt (when previewable), set an override
  template with `{variable}` interpolation, and view the edit history.

### Programmatic policy

Most modules expose a WordPress filter so site code can drive policy
declaratively. The most useful:

| Filter                                  | Returns                                       | Purpose                                    |
| --------------------------------------- | --------------------------------------------- | ------------------------------------------ |
| `extend_ai_policy_preamble`             | `string`                                      | Site-wide preamble, per-ability override   |
| `extend_ai_model_allowlist`             | `[['provider','model'], …]`                   | Approved models per capability             |
| `extend_ai_role_map`                    | `[ ability_id => [role, role] ]`              | Role gating per ability                    |
| `extend_ai_pii_patterns`                | `[ label => regex ]`                          | PII redaction regexes                      |
| `extend_ai_rate_limits`                 | `[ 'minute' => int, 'day' => int ]`           | Bucket limits                              |
| `extend_ai_token_rate_input`            | `float`                                       | $/1k input tokens, per provider+model      |
| `extend_ai_token_rate_output`           | `float`                                       | $/1k output tokens                         |
| `extend_ai_banned_phrases`              | `string[]`                                    | Output moderation phrase list              |
| `extend_ai_prompt_variables`            | `array<string,scalar>`                        | Variables for prompt interpolation         |

### Stored options

For ops teams that prefer database-driven config over filters:

```
extend_ai_policy_preamble        TEXT      Global preamble.
extend_ai_monthly_user_cap_usd   FLOAT     Per-user monthly USD cap. 0 disables.
extend_ai_log_retention_days     INT       Days to keep wp_ai_request_log rows.
extend_ai_redact_pii             BOOL      Toggle PII redactor.
extend_ai_rate_limits            ARRAY     { minute: int, day: int }
extend_ai_model_allowlist        ARRAY     { text: [[p,m]], image: …, vision: … }
extend_ai_disabled_features      ARRAY     [ feature_id, … ]
extend_ai_banned_phrases         ARRAY     [ "phrase", … ]
extend_ai_role_map               ARRAY     { ability_id: [role, role] }
extend_ai_vault_enabled          BOOL      Delegate credential checks to vault.
extend_ai_usage_retention_months INT       How far back to keep usage rows.
```

---

## REST API

All routes are under `extend-ai/v1` and require `manage_options`.

### Policies

```http
GET    /wp-json/extend-ai/v1/policies         # current settings
POST   /wp-json/extend-ai/v1/policies         # update any subset
```

### Usage

```http
GET    /wp-json/extend-ai/v1/usage?month=YYYY-MM
```

Returns per-user rollups: requests, tokens in/out, USD spent.

### Prompt library

```http
GET    /wp-json/extend-ai/v1/prompts                       # list all abilities + overrides
GET    /wp-json/extend-ai/v1/prompts/{ability_id}          # one ability
PUT    /wp-json/extend-ai/v1/prompts/{ability_id}          # { mode, template }
DELETE /wp-json/extend-ai/v1/prompts/{ability_id}          # revert to default
GET    /wp-json/extend-ai/v1/prompts/{ability_id}/history  # audit trail
```

### Example: replace the title-generation prompt

```bash
curl -X PUT -H "Content-Type: application/json" \
  --user admin:password \
  -d '{
    "mode": "replace",
    "template": "You are a {site_name} editor. Generate a title ≤60 chars for {post_title}. Direct, no clickbait."
  }' \
  http://localhost:8890/wp-json/extend-ai/v1/prompts/ai/title-generation
```

Available `{variables}`:

- Built-in: `{ability}`, `{user_login}`, `{user_role}`, `{site_name}`, `{site_url}`, `{current_date}`
- Post context (when `post_id` is in the ability data): `{post_title}`, `{post_type}`, `{post_status}`
- Any scalar from the ability's `$data` payload, lowercased

Unrecognized placeholders are left in the template as-is rather than blanked, so
a typo is visible in the prompt preview instead of silently disappearing.

---

## Architecture

```
extend-ai-enterprise/
├── extend-ai-enterprise.php           bootstrap, activation
├── blueprint.json                     WordPress Playground demo blueprint
├── assets/admin.js                    React admin app (no build step)
├── includes/
│   ├── Plugin.php                     wires every module on plugins_loaded
│   ├── Compat/Version_Gate.php        TESTED_MIN..TESTED_MAX + drift notice
│   ├── Compat/Upgrader.php            0.1 → 0.2 stored-state migrations
│   ├── Policy/
│   │   ├── Prompt_Injector.php        wpai_system_instruction (preamble)
│   │   │                              wpai_{slug}_system_instruction (overrides)
│   │   ├── Model_Allowlist.php        wpai_preferred_*_models
│   │   └── PII_Redactor.php           wpai_pre_normalize_content
│   ├── Access/
│   │   ├── Role_Gate.php              wpai_feature_{id}_enabled + user_has_cap
│   │   └── Credential_Vault.php       wpai_has_ai_credentials
│   ├── Governance/
│   │   ├── Rate_Limiter.php           wp_pre_execute_ability
│   │   ├── Cost_Tracker.php           consumes extend_ai_request_completed
│   │   ├── Output_Moderator.php       wp_ability_execute_result
│   │   └── Retention.php              wpai_request_log_retention_days + cron
│   ├── Logging/Transporter_Wrap.php   wraps AiClient HTTP transporter
│   ├── Storage/
│   │   ├── Prompt_Library.php         wp_extend_ai_prompts + history
│   │   └── Usage_Repository.php       wp_extend_ai_usage
│   ├── REST/Admin_Controller.php      /wp-json/extend-ai/v1/*
│   └── Admin/Settings_Page.php        Tools menu pages + script enqueue
└── tests/
    ├── bootstrap.php
    └── contract/
        └── WPAI_Contract_Test.php         pins WP AI filters, REST, SDK shape,
                                           Abilities lifecycle hooks, slug derivation
```

### Module boot order

1. **Upgrader** — one-shot stored-state migrations (schema option `extend_ai_schema_version`).
2. **Version_Gate** — admin notice if running outside `TESTED_MIN..TESTED_MAX`.
3. **Transporter_Wrap** — installs decorator on `AiClient::defaultRegistry()` at `wp_loaded:20` and `admin_init:20` (after upstream's wrap at priority 1). Emits `extend_ai_request_completed` for every provider call.
4. **Policy modules** — register their filters on the AI plugin's documented hooks.
5. **Access modules** — register role gates and credential delegation.
6. **Governance modules** — subscribe to WordPress 7.1 Abilities API lifecycle filters (`wp_pre_execute_ability`, `wp_ability_execute_result`), `user_has_cap`, and `extend_ai_request_completed`. Schedule cron.
7. **Admin** — REST controller + Tools pages + script enqueue.

---

## Updates and compatibility

We never modify the WordPress AI plugin's code. When upstream releases a new
version, you update it like any other plugin. Our risk surface is **contract
drift**, not merge burden.

Three guardrails ship in the box:

1. **Tested version pin.** `Compat\Version_Gate::TESTED_MAX` is the upper
   bound of WP AI versions we've verified the integration against. Running
   outside the range surfaces a non-blocking admin notice with the version
   numbers spelled out so admins know to revalidate.

2. **Wrap-failure telemetry.** If the transporter wrap can't install (SDK
   class missing, interface changed, registry method gone), we set a
   transient, fire the `extend_ai_transporter_wrap_failed` action so
   monitoring plugins can page, and render an admin error notice. The wrap
   silently no-opping was the worst possible outcome — this turns it loud.

3. **Contract tests.** The suite in `tests/contract/` pins every integration
   point: WP AI filter names and signatures, REST namespace, SDK interface
   shape, the WordPress 7.1 Abilities execution lifecycle hooks our governance
   gates depend on, and the ability→slug derivation behind the per-ability
   prompt filters. CI runs them against:
   - The pinned WP AI release (gate for our own releases)
   - The WP AI `develop` branch nightly (drift detector for upstream changes
     before they ship)

### Bumping the supported version

When a new WP AI release lands:

1. Watch the nightly contract job — it tells you if anything broke.
2. If green, locally activate the new version, run `composer test:contract`.
3. Bump `Version_Gate::TESTED_MAX` to the new version, tag, release.

If a test fails, the failure message tells you precisely which contract
drifted (e.g. *"wpai_pre_normalize_content filter missing — PII redaction is
silently disabled"*). Fix the binding in that one module and bump.

---

## Testing

```bash
composer install
bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test
```

The CI workflow at `.github/workflows/contract.yml` runs the same suite on
every PR plus a nightly cron against `WordPress/ai@develop`.

---

## Why not just fork the AI plugin?

The forking path looks tempting — full control, no contracts to honor — but
in practice:

- Every upstream release becomes a merge with conflict resolution.
- Security patches are now your responsibility to backport.
- Divergence compounds. After a year, your fork is its own product.
- The WP AI plugin already exposes every hook we need.

The pattern this plugin uses — separate plugin, documented filters,
defensive SDK wrap, contract tests — is the same shape Yoast, ACF, Polylang,
and WooCommerce extensions use for the same reason. It's the well-trodden
path for extending WordPress plugins at the boundary instead of from inside.

---

## Roadmap

Known gaps with deliberate deferrals:

- **Output moderation backend.** Current scanner is phrase-list only. Real
  moderation (AWS Comprehend, Azure Content Safety, OpenAI Moderations) is
  pluggable but the backend choice is policy-dependent; we ship the seam, not
  the integration.
- **Token pricing table.** Default per-1k rates are placeholders. Replace via
  `extend_ai_token_rate_input` / `_output` filters for accurate cost
  tracking until a real price catalog ships.
- **Multisite scoping.** No per-site policy primitives yet.
- **React build pipeline.** The admin app uses `wp.element.createElement`
  directly so it works without a build step. Converting to JSX +
  `@wordpress/scripts` is straightforward when desired.

---

## License

GPL-2.0-or-later, matching the upstream WordPress AI plugin.
