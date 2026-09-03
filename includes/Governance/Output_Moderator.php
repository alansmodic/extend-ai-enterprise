<?php
/**
 * Post-response moderation: scan model output for PII leaks, policy violations,
 * banned phrases, or hallucinated entities before it reaches the user.
 *
 * Strategy: intercept ability execution results via the WordPress 7.1
 * wp_ability_execute_result lifecycle filter. This enforces moderation for all
 * execution paths — REST, MCP, WP-CLI, and direct WP_Ability::execute() calls.
 * If the output fails moderation, return a WP_Error that replaces the result.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Governance;

final class Output_Moderator {

	public function register(): void {
		add_filter( 'wp_ability_execute_result', array( $this, 'moderate' ), 10, 4 );
	}

	/**
	 * Moderate ability execution results before they are returned to the caller.
	 *
	 * @param mixed       $result       The ability's execution result (before output validation).
	 * @param string      $ability_name The ability that was executed (e.g. 'ai/title-generation').
	 * @param mixed       $input        The normalized input.
	 * @param \WP_Ability $ability      The ability instance.
	 * @return mixed|\WP_Error Original result if clean, WP_Error if blocked.
	 */
	public function moderate( $result, string $ability_name, $input, $ability ) {
		unset( $input, $ability );

		// Only moderate AI abilities, not arbitrary WordPress abilities.
		if ( ! str_starts_with( $ability_name, 'ai/' ) ) {
			return $result;
		}

		// If execution already failed, don't double-moderate the error.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = $this->extract_text( $result );
		if ( $text === '' ) {
			return $result;
		}

		$violation = $this->scan( $text );
		if ( $violation === null ) {
			return $result;
		}

		/**
		 * Fires when model output is blocked by moderation.
		 *
		 * @param string $violation    The reason output was blocked.
		 * @param string $ability_name The ability that was executed.
		 * @param string $text         The text that failed moderation.
		 * @param mixed  $result       The original result before blocking.
		 */
		do_action( 'extend_ai_moderation_violation', $violation, $ability_name, $text, $result );

		return new \WP_Error(
			'extend_ai_output_blocked',
			sprintf( 'AI output blocked: %s', $violation ),
			array( 'status' => 451 )
		);
	}

	private function extract_text( mixed $data ): string {
		if ( is_string( $data ) ) {
			return $data;
		}
		if ( is_array( $data ) ) {
			foreach ( array( 'result', 'output', 'text', 'content' ) as $k ) {
				if ( isset( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
					return $data[ $k ];
				}
			}
		}
		return '';
	}

	private function scan( string $text ): ?string {
		$banned = (array) apply_filters( 'extend_ai_banned_phrases', (array) get_option( 'extend_ai_banned_phrases', array() ) );
		foreach ( $banned as $phrase ) {
			if ( $phrase !== '' && stripos( $text, (string) $phrase ) !== false ) {
				return 'banned phrase detected';
			}
		}
		// TODO: PII echo-back, hallucinated competitor names, external moderation API call.
		return null;
	}
}
