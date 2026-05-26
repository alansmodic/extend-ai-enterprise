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

## What it adds

| Concern                         | Capability                                                                                          |
| ------------------------------- | --------------------------------------------------------------------------------------------------- |
| **Per-ability prompt control**  | Override any of the 11+ built-in ability prompts. Prepend, append, or replace. Variable interpolation. Version history per edit. |
| **Site-wide policy preamble**   | Inject brand voice / compliance language ahead of every AI call.                                    |
| **Model allowlist**             | Restrict the AI plugin to an approved set of provider/model pairs for text, image, and vision.      |
| **PII redaction**               | Pattern-based redaction of email, SSN, phone numbers from inputs before they leave WordPress.       |
| **Role-based access**           | Per-ability allowlist of WP roles. Disable specific experiments per-role or globally.               |
| **Credential delegation**       | Hand off credential resolution to an enterprise vault (AWS Secrets Manager, HashiCorp Vault, SSO).  |
| **Rate limiting**               | Per-minute and per-day quotas per user, enforced at the REST layer (no provider spend on rejected). |
| **Cost tracking + caps**        | Per-user monthly spend rollup in a dedicated table. Hard cap drops the model allowlist to nothing.  |
| **Output moderation**           | Banned-phrase scan on every ability response. Pluggable backend for richer moderation.              |
| **Audit retention**             | Sets the WP AI log retention via its own filter. Separate cron for our usage table.                 |
| **Drift detection**             | Version pin + admin notice when running outside the tested WP AI range.                             |
| **Wrap-failure telemetry**      | Action + admin notice when the transporter wrap fails so silent governance gaps get loud.           |

---

## How it works

We hook the WordPress AI plugin's **public extension points** — never its
internals. Six categories of hook:

```
┌───────────────────────────────────────────────────────────────────────┐
│  WordPress AI plugin                                                  │
│                                                                       │
│   Ability runs ──► wpai_pre_normalize_content   (input scrubbing)     │
│                ──► wpai_system_instruction      (prompt shaping)      │
│                ──► wpai_preferred_*_models      (model selection)     │
│                ──► wpai_has_ai_credentials      (credential probe)    │
│                ──► AiClient::defaultRegistry()->setHttpTransporter()  │
│                ──► /wp-abilities/v1/{ability}   (REST invocation)     │
└─────┬─────────────────┬───────────────┬──────────────┬──────────────┘
      │                 │               │              │
      ▼                 ▼               ▼              ▼
  PII_Redactor     Prompt_Injector  Model_Allowlist  Credential_Vault
  Output_Moderator Prompt_Library                    Rate_Limiter
                                                     Transporter_Wrap
                                                          │
                                                          ▼
                                                  extend_ai_request_completed
                                                          │
                                                          ▼
                                                     Cost_Tracker → wp_extend_ai_usage
```

Two custom tables back the moving parts:

- `wp_extend_ai_prompts` — one row per ability override (mode + template).
- `wp_extend_ai_prompts_history` — append-only audit of every prompt edit.
- `wp_extend_ai_usage` — per-user-per-month spend rollups, indexed for fast aggregation.

---

## Installation

### Prerequisites

- WordPress 6.6 or newer
- PHP 8.1 or newer
- [WordPress AI plugin](https://wordpress.org/plugins/ai/) v1.0.0 (the upstream
  version we've tested against — see `Compat\Version_Gate::TESTED_MAX` for the
  ceiling)

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

---

## Architecture

```
extend-ai-enterprise/
├── extend-ai-enterprise.php           bootstrap, activation
├── assets/admin.js                    React admin app (no build step)
├── includes/
│   ├── Plugin.php                     wires every module on plugins_loaded
│   ├── Compat/Version_Gate.php        TESTED_MIN..TESTED_MAX + drift notice
│   ├── Policy/
│   │   ├── Prompt_Injector.php        wpai_system_instruction
│   │   ├── Model_Allowlist.php        wpai_preferred_*_models
│   │   └── PII_Redactor.php           wpai_pre_normalize_content
│   ├── Access/
│   │   ├── Role_Gate.php              wpai_feature_{id}_enabled + user_has_cap
│   │   └── Credential_Vault.php       wpai_has_ai_credentials
│   ├── Governance/
│   │   ├── Rate_Limiter.php           rest_pre_dispatch on wp-abilities/v1
│   │   ├── Cost_Tracker.php           consumes extend_ai_request_completed
│   │   ├── Output_Moderator.php       rest_post_dispatch on wp-abilities/v1
│   │   └── Retention.php              wpai_request_log_retention_days + cron
│   ├── Logging/Transporter_Wrap.php   wraps AiClient HTTP transporter
│   ├── Storage/
│   │   ├── Prompt_Library.php         wp_extend_ai_prompts + history
│   │   └── Usage_Repository.php       wp_extend_ai_usage
│   ├── REST/Admin_Controller.php      /wp-json/extend-ai/v1/*
│   └── Admin/Settings_Page.php        Tools menu pages + script enqueue
└── tests/
    ├── bootstrap.php
    └── contract/WPAI_Contract_Test.php
```

### Module boot order

1. **Version_Gate** — admin notice if running outside `TESTED_MIN..TESTED_MAX`.
2. **Transporter_Wrap** — installs decorator on `AiClient::defaultRegistry()` at `wp_loaded:20` and `admin_init:20` (after upstream's wrap at priority 1). Emits `extend_ai_request_completed` for every provider call.
3. **Policy modules** — register their filters on the AI plugin's documented hooks.
4. **Access modules** — register role gates and credential delegation.
5. **Governance modules** — subscribe to `extend_ai_request_completed`, register REST pre/post dispatch filters, schedule cron.
6. **Admin** — REST controller + Tools pages + script enqueue.

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

3. **Contract tests.** Nine tests in `tests/contract/WPAI_Contract_Test.php`
   pin every integration point: filter names, filter signatures, REST
   namespace, SDK interface shape, abilities API. CI runs them against:
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
