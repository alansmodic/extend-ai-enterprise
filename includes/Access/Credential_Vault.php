<?php
/**
 * Delegate AI credential resolution to an enterprise vault (AWS Secrets Manager,
 * HashiCorp Vault, internal SSO, etc.) instead of WP options.
 *
 * Hooks:
 *  - `wpai_has_ai_credentials` (filter) — short-circuit credential presence check.
 *  - `wpai_pre_has_valid_credentials_check` (filter) — short-circuit credential validation.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Access;

final class Credential_Vault {

	public function register(): void {
		add_filter( 'wpai_has_ai_credentials', array( $this, 'has_credentials' ), 10, 2 );
		add_filter( 'wpai_pre_has_valid_credentials_check', array( $this, 'valid_credentials' ), 10 );
	}

	/**
	 * @param bool  $has_credentials Default result.
	 * @param array $connectors      Registered connectors.
	 */
	public function has_credentials( bool $has_credentials, array $connectors ): bool {
		if ( ! $this->vault_enabled() ) {
			return $has_credentials;
		}
		// TODO: probe vault for any of the configured connector keys.
		// Returning true here means "we have credentials available via the vault, you may proceed."
		return true;
	}

	/**
	 * @return bool|null Return bool to short-circuit, null to let default run.
	 */
	public function valid_credentials(): ?bool {
		if ( ! $this->vault_enabled() ) {
			return null;
		}
		// TODO: validate against vault, return true/false. Cache result for ttl.
		return true;
	}

	private function vault_enabled(): bool {
		return (bool) get_option( 'extend_ai_vault_enabled', false );
	}
}
