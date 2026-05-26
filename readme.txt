=== Extend AI — Enterprise ===
Contributors: extend-ai
Requires at least: 6.6
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later

Enterprise governance wrapper around the WordPress AI plugin. Adds prompt policy,
model allowlists, PII redaction, RBAC, rate limits, cost caps, output moderation,
and audit retention — all via the plugin's documented filters. No fork required.

== Architecture ==

Layers, each one a folder in /includes:

  Policy/      — shapes inputs before they leave WP
    Prompt_Injector   → wpai_system_instruction
    Model_Allowlist   → wpai_preferred_text|image|vision_models
    PII_Redactor      → wpai_pre_normalize_content

  Access/      — who can use what
    Role_Gate         → wpai_feature_{id}_enabled + user_has_cap
    Credential_Vault  → wpai_has_ai_credentials, wpai_pre_has_valid_credentials_check

  Governance/  — limits, costs, audit, moderation
    Rate_Limiter      → rest_pre_dispatch on /wp-abilities/v1
    Cost_Tracker      → consumes logging events, gates allowlist at budget
    Output_Moderator  → rest_post_dispatch on /wp-abilities/v1
    Retention         → daily cron purges wp_ai_request_log

  REST/        — /wp-json/extend-ai/v1/{policies,usage}
  Admin/       — Tools → AI Enterprise settings page

== Status ==

This is a scaffold. Every module wires the correct hook and has a clear TODO for
the business logic (e.g. real pricing table, vault adapter, moderation API call).
