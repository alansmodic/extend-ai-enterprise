=== Extend AI — Enterprise ===
Contributors: extend-ai
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later

Enterprise governance wrapper around the WordPress AI plugin. Adds prompt policy,
model allowlists, PII redaction, RBAC, rate limits, cost caps, and output
moderation — all via the plugin's documented filters and the WordPress Abilities
API execution lifecycle. No fork required.

== Architecture ==

Layers, each one a folder in /includes:

  Policy/      — shapes inputs before they leave WP
    Prompt_Injector   → wpai_system_instruction (policy preamble)
                      → wpai_{slug}_system_instruction (per-ability overrides)
    Model_Allowlist   → wpai_preferred_text|image|vision_models
    PII_Redactor      → wpai_pre_normalize_content

  Access/      — who can use what
    Role_Gate         → wpai_feature_{id}_enabled + user_has_cap
    Credential_Vault  → wpai_has_ai_credentials, wpai_pre_has_valid_credentials_check

  Governance/  — limits, costs, moderation
    Rate_Limiter      → wp_pre_execute_ability
    Cost_Tracker      → consumes logging events, denies wp_ability_* over budget
    Output_Moderator  → wp_ability_execute_result
    Retention         → wpai_request_log_retention_days + daily cron

  REST/        — /wp-json/extend-ai/v1/{policies,usage,prompts}
  Admin/       — Tools → AI Enterprise settings page

Rate limiting, output moderation, and spend caps hook the WordPress 7.1 Abilities
API execution lifecycle, so they apply to every caller — REST, MCP, WP-CLI, and
direct WP_Ability::execute() — not just REST requests.

== Site guidelines ==

The WordPress AI plugin injects site guidelines natively as of 1.3.0. This plugin
does not duplicate that. Enable the Gutenberg Guidelines experiment and control
injection with the WP AI plugin's own wpai_use_guidelines filter.

== Status ==

This is a scaffold. Every module wires the correct hook and has a clear TODO for
the business logic (e.g. real pricing table, vault adapter, moderation API call).
