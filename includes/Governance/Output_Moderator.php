<?php
/**
 * Post-response moderation: scan model output for PII leaks, policy violations,
 * banned phrases, or hallucinated entities before it reaches the user.
 *
 * Strategy: filter the REST response for the abilities namespace. If the body
 * fails moderation, replace it with a safe error and log the incident.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Governance;

final class Output_Moderator {

	public function register(): void {
		add_filter( 'rest_post_dispatch', [ $this, 'moderate' ], 10, 3 );
	}

	/**
	 * @param \WP_REST_Response $response
	 * @param \WP_REST_Server   $server
	 * @param \WP_REST_Request  $request
	 * @return \WP_REST_Response
	 */
	public function moderate( $response, $server, $request ) {
		if ( ! str_contains( (string) $request->get_route(), 'wp-abilities/v1' ) ) {
			return $response;
		}

		$data = $response->get_data();
		$text = $this->extract_text( $data );
		if ( $text === '' ) {
			return $response;
		}

		$violation = $this->scan( $text );
		if ( $violation === null ) {
			return $response;
		}

		do_action( 'extend_ai_moderation_violation', $violation, $request, $text );

		$response->set_status( 451 );
		$response->set_data( [
			'code'    => 'extend_ai_output_blocked',
			'message' => sprintf( 'AI output blocked: %s', $violation ),
			'data'    => [ 'status' => 451 ],
		] );
		return $response;
	}

	private function extract_text( mixed $data ): string {
		if ( is_string( $data ) ) {
			return $data;
		}
		if ( is_array( $data ) ) {
			foreach ( [ 'result', 'output', 'text', 'content' ] as $k ) {
				if ( isset( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
					return $data[ $k ];
				}
			}
		}
		return '';
	}

	private function scan( string $text ): ?string {
		$banned = (array) apply_filters( 'extend_ai_banned_phrases', (array) get_option( 'extend_ai_banned_phrases', [] ) );
		foreach ( $banned as $phrase ) {
			if ( $phrase !== '' && stripos( $text, (string) $phrase ) !== false ) {
				return 'banned phrase detected';
			}
		}
		// TODO: PII echo-back, hallucinated competitor names, external moderation API call.
		return null;
	}
}
