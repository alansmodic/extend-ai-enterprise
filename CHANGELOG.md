# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Site Guidelines integration** (Gutenberg "Guidelines" experiment, 22.7+).
  When the experiment is active, the published content-guidelines singleton is
  composed into a "Site guidelines" prompt section and appended to editorial
  review abilities (`ai/editorial-notes`, `ai/editorial-updates`), so
  AI-generated review notes reflect the site's actual standards. Per-block
  rules are narrowed to block types present in the post under review. Sites
  without the experiment are untouched — detection is a runtime
  `post_type_exists()` check covering both the current (`wp_guideline`) and
  the 22.7 (`wp_content_guideline`) post type names.
- New prompt-template variables: `{guidelines}`, `{guidelines_site}`,
  `{guidelines_copy}`, `{guidelines_images}`, `{guidelines_additional}`,
  `{guidelines_blocks}`. Using any of them in an override template suppresses
  the automatic append.
- New filters: `extend_ai_guidelines_enabled`, `extend_ai_guidelines_abilities`,
  `extend_ai_guidelines_statuses`, `extend_ai_guidelines_text`. New action
  `extend_ai_guidelines_applied` (ability, guideline post ID, latest revision
  ID) for audit trails.
- "Use site Guidelines" toggle (option `extend_ai_use_guidelines`, default on)
  on the Tools → AI Enterprise page and in the `/policies` REST endpoint,
  which also reports read-only `guidelines_detected`.

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

[Unreleased]: https://github.com/alansmodic/extend-ai-enterprise/compare/v0.1.0...HEAD
[0.1.0]:      https://github.com/alansmodic/extend-ai-enterprise/releases/tag/v0.1.0
