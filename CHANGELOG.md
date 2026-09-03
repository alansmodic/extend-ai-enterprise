# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

_Nothing yet._

---

## [0.2.0] — 2026-09-03

### Breaking

- **WordPress 7.1 is now the minimum** (`Requires at least: 7.1`). Rate limiting
  and output moderation moved off the REST hooks entirely and onto the Abilities
  API execution lifecycle, which ships in 7.1. On an older release those gates
  would register against hooks that never fire and enforce *nothing* — so the
  plugin now declines to activate rather than fail open silently.
- **`replace` prompt mode no longer re-applies the policy preamble.** The
  preamble now rides on the global `wpai_system_instruction` filter and overrides
  on the ability-scoped one, which WP AI runs afterwards; a `replace` template
  therefore supersedes both, matching what the editor UI has always claimed
  ("Replace uses only your template"). Use `prepend`/`append` to keep the
  preamble, or restate the policy text inside the template.

### Removed

- **Guidelines_Bridge module** — WordPress AI 1.3.0 ships native Guidelines
  injection (WordPress/ai #359, `includes/Services/Guidelines.php`). Our
  `Policy/Guidelines_Bridge` duplicated this functionality and caused
  double-injection when both were enabled. DELETED: `Guidelines_Bridge.php`,
  `Guidelines_Bridge_Test.php`, `extend_ai_use_guidelines` option,
  `extend_ai_guidelines_*` filters, admin UI toggle, REST API
  `guidelines_detected` field, `{guidelines*}` prompt template variables.
  **Migration:** Enable Gutenberg Guidelines experiment and use WordPress AI's
  `wpai_use_guidelines` filter to control injection.

### Changed

- **Prompt_Injector retargeted to WordPress AI 1.3 per-ability filters.**
  Prompt overrides now use native `wpai_{slug}_system_instruction` filters
  (e.g., `wpai_title_generation_system_instruction`) instead of hooking only
  the global `wpai_system_instruction`. Global policy preamble still uses
  `wpai_system_instruction`. Per-ability filters are registered dynamically for
  abilities with prompt overrides in the library.
- **WordPress AI compatibility updated to 1.3.x.** `Compat\Version_Gate::TESTED_MIN`
  raised to `1.3.0` and `TESTED_MAX` to `1.3.99`, reflecting testing against
  WordPress AI 1.3.0 which added per-ability filter extension points, Custom
  Abilities experiment, and other API changes. README prerequisites updated to
  the v1.3.x tested range.
- **Rate_Limiter enforcement moved to ability execution layer.** Previously
  enforced only at REST pre-dispatch (`rest_pre_dispatch` on `wp-abilities/v1`),
  rate limiting now hooks `wp_pre_execute_ability` (WordPress 7.1) to enforce
  before any execution begins, regardless of how the ability was invoked. This
  closes the MCP, WP-CLI, and direct PHP bypass routes.
- **Output_Moderator enforcement moved to ability execution layer.** Previously
  enforced only at REST post-dispatch (`rest_post_dispatch` on `wp-abilities/v1`),
  output moderation now hooks `wp_ability_execute_result` (WordPress 7.1) to
  scan results before they return to any caller. MCP, WP-CLI, and PHP calls now
  pass through the same banned-phrase scanner as REST requests.
- **Cost_Tracker continues to enforce universally via `user_has_cap`.** No
  change required — the existing `user_has_cap` filter already gates every
  `wp_ability_*` capability check regardless of execution path. Verified that
  this pattern remains universal with WordPress 7.1.

### Added

- **Contract tests for WordPress 7.1 lifecycle filters.** New tests validate
  that `wp_pre_execute_ability`, `wp_ability_normalize_input`, and
  `wp_ability_execute_result` filters exist and have the correct signatures.
  Tests also verify our governance modules properly subscribe to these filters.
- **Contract tests for the ability→slug derivation.** The per-ability filter name
  is computed, so a wrong slug means subscribing to a hook nothing fires — a
  failure with no error anywhere. `test_ability_slug_derivation_matches_wp_ai`
  pins the derivation against the documented ability names, and
  `test_override_subscribes_to_scoped_hook_and_applies` seeds a real override and
  asserts it both lands on the correctly named hook and transforms the
  instruction under WP AI's actual 2-arg signature.
- **0.1 → 0.2 stored-state upgrader.** `Compat\Upgrader` runs on boot (WordPress
  does not fire the activation hook on updates): drops `extend_ai_use_guidelines`,
  rewrites `{guidelines*}` out of stored templates, and renames
  `ai/comment-moderation` overrides / role-map keys to `ai/comment-analysis`.
- **CI matrix updated to WordPress AI 1.3.0.** `.github/workflows/contract.yml`
  now tests against WordPress AI 1.3.0 and develop (removed 1.0.0/1.0.1).

### Documentation & demo

- **Playground blueprint rebuilt around what the plugin still does.** It no
  longer installs Gutenberg or hand-seeds a guidelines CPT (that storage layout
  was never ours to reverse-engineer, and guidelines are WP AI's job now).
  Instead it seeds a policy preamble, a per-ability override, and governance
  limits low enough to actually trip during a demo. The prompt-preview helper
  applies the global and ability-scoped filters in WP AI's real order.
- **Architecture docs corrected.** The README tree and `readme.txt` still listed
  `Guidelines_Bridge`, the deleted `Guidelines_Bridge_Test`, and
  `rest_pre_dispatch`/`rest_post_dispatch` for the governance modules. `readme.txt`
  also still sold "site-Guidelines-aware review prompts" as a feature.
- **`Transporter_Wrap`, `Credential_Vault`, and `Retention` demoted in the docs**
  from headline capabilities to implementation details. No code change: the wrap
  is still required because native `log_ai_request()` does not produce the
  per-user USD rollups `wp_extend_ai_usage` needs, and the vault remains a thin
  filter seam rather than an integration.

### Fixed

- **Per-ability prompt overrides never applied.** Two stacked bugs: `ability_to_slug()`
  stripped the `ai/` namespace but left hyphens intact, producing
  `wpai_title-generation_system_instruction` where WP AI fires
  `wpai_title_generation_system_instruction`; and the callback was registered
  with the global-filter signature `($instruction, $ability_name, $data)` even
  though WP AI 1.3's scoped hook is `apply_filters( $hook, $instruction, $data )`.
  PHP 8 would TypeError on the array `$data` payload the first time an override
  ran. Derivation now matches WP AI's helper, and the callback captures the
  ability id in a 2-arg closure. A contract test pins WP AI's call site so a
  signature drift fails CI instead of production.
- **Role gate for comment analysis failed open.** `Role_Gate` and the prompt-UI
  fallback map keyed `ai/comment-moderation`; WP AI registers the ability as
  `ai/comment-analysis` (the *feature* id is still `comment-moderation`). An
  unmatched key is treated as "not configured", so the restriction never
  applied. The 0.1→0.2 upgrader renames stored overrides and role-map entries.
- **Stale `{guidelines*}` variables advertised in the prompt editor.** The help
  text still listed `{guidelines}`, `{guidelines_copy}` and friends after the
  bridge was deleted. Nothing defined them, and unresolved placeholders are left
  verbatim in the template, so they were being sent to the model as literal text.
  The 0.2 upgrader strips those placeholders from stored templates (and deletes
  an override that would become empty) and drops the leftover
  `extend_ai_use_guidelines` option.
- **Governance enforcement gaps closed.** Rate limiting and output moderation
  previously applied only to REST API invocations. MCP servers (via
  `log_ai_request()` in WordPress AI 1.3.0), WP-CLI commands, and direct PHP
  calls to `WP_Ability::execute()` bypassed those gates entirely. Moving
  enforcement to the WordPress 7.1 execution lifecycle filters ensures every
  AI ability invocation — regardless of caller — passes through rate limits,
  spend caps, and output moderation before any model call or result return.

### Compatibility Notes

- **WordPress 7.1+ required, enforced at activation.** The lifecycle filters
  (`wp_pre_execute_ability`, `wp_ability_execute_result`) ship in WordPress 7.1,
  and the REST-layer hooks they replaced are gone. There is no partial-enforcement
  fallback on older releases: the gates would be inert, so `Requires at least`
  blocks activation instead.
- **WordPress AI 1.3.x recommended.** This release is tested against WordPress
  AI 1.3.0. The 1.0.x branch is no longer tested; see the contract test matrix
  for version coverage.
- **Custom Abilities experiment** (WordPress AI 1.3.0+) gates abilities like
  `ai/get-post-details` and `core/read-content` behind an opt-in toggle in
  Settings → AI → Admin Experiments. Enable it if your integration relies on
  those abilities.
- **Guidelines now use WordPress AI native support.** WordPress AI 1.3.0 ships
  native Guidelines injection. Use `wpai_use_guidelines` filter to control.
  Our Guidelines_Bridge has been removed to avoid double-injection.

---

## [0.1.0] — 2026-06-19

### Added

**Contract test matrix now covers `WordPress/ai@1.0.1`** alongside `@1.0.0`
  and `@develop`, reflecting upstream's 1.0.1 release. README prerequisites
  updated to the v1.0.0–v1.0.1 tested range. (`Compat\Version_Gate::TESTED_MAX`
  already accepted 1.0.1, so no runtime gate change was needed.)

### Fixed

- **Monthly spend cap now hard-blocks instead of failing open.**
  `Cost_Tracker` enforced the cap by emptying the model allowlist, but
  `Model_Allowlist` reads an empty allowlist as "allow all defaults" — so going
  over budget *removed* the configured model restriction instead of blocking
  use. Enforcement moved to the capability layer: an over-budget user is denied
  every `ai/*` ability via `user_has_cap` (the same mechanism `Role_Gate` uses),
  which stops the request at the Abilities API permission check. Admins
  (`manage_options`) are exempt by default so a blown cap can't lock out whoever
  needs to raise it — overridable via the new `extend_ai_budget_cap_exempt`
  filter. New tests pin the over/under/zero-cap behavior, admin exemption, and
  the filter wiring.
- **Governance ability IDs corrected to match upstream.** `Role_Gate` and the
  prompt-admin label map referenced `ai/generate-image`,
  `ai/generate-image-prompt`, and `ai/alt-text`; the real WP AI ability IDs are
  `ai/image-generation`, `ai/image-prompt-generation`, and
  `ai/alt-text-generation`. The mismatch silently disabled the image-generation
  role gate (an unmatched key fails open). Test bootstrap feature slugs fixed to
  match, and a new contract test asserts every governance-referenced ability ID
  is actually registered.
- **Transporter wrap no longer fails on sites without a configured AI
  connector.** The AI Client SDK creates its default HTTP transporter lazily —
  only when a provider registers — so on a fresh site (no connector yet) the
  registry was empty at `wp_loaded` and the wrap recorded a failure ("
  `HttpTransporterInterface instance not set`"), leaving cost tracking
  inactive. The wrap now creates the same default the SDK would
  (`HttpTransporterFactory::createTransporter()`) and decorates it; providers
  registered later adopt it. New contract tests pin the SDK's lazy-init
  behavior and the factory fallback.

## [0.1.0] — 2026-05-25

Initial release. Governance, policy, and audit layer for the
[WordPress AI plugin](https://github.com/WordPress/ai), built entirely on
documented filters, REST hooks, and the AI Client SDK's public interface —
no fork required.

### Policy

- **Per-ability prompt overrides** via the `wpai_system_instruction` filter.
  Supports `prepend`, `append`, and `replace` modes.
- **`{variable}` interpolation** in prompt templates. Built-in vars
  (`{ability}`, `{user_login}`, `{user_role}`, `{site_name}`, `{site_url}`,
  `{current_date}`), post context (`{post_title}`, `{post_type}`,
  `{post_status}` when `post_id` is in the ability data), plus any scalar
  passed by the ability. Extensible via the `extend_ai_prompt_variables` filter.
- **Append-only version history** for every prompt edit
  (`wp_extend_ai_prompts_history`).
- **Site-wide policy preamble** option, applied on top of any per-ability
  override.
- **Model allowlist** for text, image, and vision capabilities via the
  `wpai_preferred_*_models` filters. Empty list = allow everything.
- **PII redactor** hooked into `wpai_pre_normalize_content`. Default patterns
  for email, US SSN, and phone numbers; extensible via
  `extend_ai_pii_patterns`.

### Access

- **Per-ability role allowlist** enforced via `user_has_cap`. Optional
  per-feature kill switches that hook the `wpai_feature_{id}_enabled` filter.
- **Credential vault stub** delegating credential checks to an external system
  via `wpai_has_ai_credentials` and `wpai_pre_has_valid_credentials_check`.

### Governance

- **Rate limiting** at the REST layer (`rest_pre_dispatch` on
  `/wp-abilities/v1`). Per-user per-minute and per-day buckets. Rejects with
  429 before any provider call is made.
- **Cost tracking** in a dedicated `wp_extend_ai_usage` table indexed by
  `(user_id, period, provider, model)`. Idempotent upserts under concurrency.
- **Monthly per-user spend cap** that drops the model allowlist to empty when
  exceeded, failing abilities fast.
- **Output moderation** at `rest_post_dispatch`. Default backend is a banned-
  phrase scan via `extend_ai_banned_phrases`; emits
  `extend_ai_moderation_violation` action for richer pluggable backends.
- **Audit retention.** Sets the WP AI log retention via the upstream
  `wpai_request_log_retention_days` filter; daily cron purges our own usage
  table older than `extend_ai_usage_retention_months`.

### Logging

- **AI Client transporter wrap.** Decorates
  `AiClient::defaultRegistry()->setHttpTransporter()` with a logging proxy
  that emits the `extend_ai_request_completed` action with a payload mirroring
  WP AI's canonical log shape (provider, model, duration, tokens, status,
  error, user, context). Best-effort token parsing for OpenAI, Anthropic, and
  Google response formats.
- **Wrap-failure telemetry.** When the transporter wrap cannot install (SDK
  class missing, interface changed, registry method gone), records a
  transient, fires `extend_ai_transporter_wrap_failed`, and surfaces an admin
  error notice. Auto-clears on next successful wrap.

### Compatibility

- **Version gate** with `TESTED_MIN` / `TESTED_MAX` constants tracking the
  WP AI release range this build was verified against. Non-blocking admin
  notice when running outside the range.

### Admin

- **Tools → AI Enterprise** — global policy preamble, monthly per-user cap,
  log retention, PII redaction toggle.
- **Tools → AI Prompts** — React app (plain JS, no build step) listing every
  registered AI ability via `wp_get_abilities()`. Per-ability editor with
  mode selector, template editor with variable help, save/revert actions, and
  edit history. Surfaces a clear info notice when the upstream default
  prompt is computed per-call (and so not previewable).

### REST API (`extend-ai/v1`)

- `GET    /policies` / `POST /policies` — global settings.
- `GET    /usage?month=YYYY-MM` — per-user rollups for a period.
- `GET    /prompts` — list every discoverable ability with its default
  instruction (when previewable) and current override.
- `GET    /prompts/{ability_id}` / `PUT` / `DELETE` — per-ability override
  CRUD.
- `GET    /prompts/{ability_id}/history` — audit trail.

### Storage

- `wp_extend_ai_prompts` — per-ability overrides keyed by `ability_id`.
- `wp_extend_ai_prompts_history` — append-only audit of every edit.
- `wp_extend_ai_usage` — per-user-per-month spend rollups, indexed for fast
  aggregation. Installed via `dbDelta` on plugin activation.

### Tooling

- **Contract test suite** (`tests/contract/WPAI_Contract_Test.php`). 11
  PHPUnit tests pinning every WordPress AI integration point: filter names,
  filter signatures, REST namespace, SDK interface shape, abilities API
  subscriptions.
- **GitHub Actions: Contract tests** — runs the suite on every PR/push,
  matrix against `WordPress/ai@1.0.0` and `@develop`, plus a nightly cron at
  06:00 UTC to detect upstream drift before any release ships.
- **GitHub Actions: Lint** — PHPCS against WordPress-Core + WordPress-Extra +
  PHPCompatibilityWP on every PR, with findings annotated inline via `cs2pr`.
- `composer.json` scripts: `composer lint`, `composer lint:fix`,
  `composer test:contract`.
- `bin/install-wp-tests.sh` — standard WordPress test scaffold installer for
  local PHPUnit runs.

[Unreleased]: https://github.com/alansmodic/extend-ai-enterprise/compare/v0.2.0...HEAD
[0.2.0]:      https://github.com/alansmodic/extend-ai-enterprise/compare/v0.1.0...v0.2.0
[0.1.0]:      https://github.com/alansmodic/extend-ai-enterprise/releases/tag/v0.1.0
