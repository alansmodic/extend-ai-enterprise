<?php
/**
 * Redacts PII from content before it is sent to an AI provider.
 *
 * Hooks: `wpai_pre_normalize_content` (filter, fires inside helpers.php before HTML stripping).
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Policy;

final class PII_Redactor {

	private const PATTERNS = array(
		'email' => '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
		'ssn'   => '/\b\d{3}-\d{2}-\d{4}\b/',
		'phone' => '/\b(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/',
		// TODO: credit cards (Luhn-validated), API keys, IBAN, internal IDs per config.
	);

	public function register(): void {
		if ( ! (bool) get_option( 'extend_ai_redact_pii', true ) ) {
			return;
		}
		add_filter( 'wpai_pre_normalize_content', array( $this, 'redact' ), 10, 1 );
	}

	public function redact( string $content ): string {
		/**
		 * Filter the redaction pattern map.
		 *
		 * @param array<string,string> $patterns label => regex.
		 */
		$patterns = (array) apply_filters( 'extend_ai_pii_patterns', self::PATTERNS );

		foreach ( $patterns as $label => $regex ) {
			$content = (string) preg_replace( $regex, '[REDACTED:' . $label . ']', $content );
		}
		return $content;
	}
}
