<?php
/**
 * Per-user / per-feature rate limiting and burst control.
 *
 * Strategy: intercept ability invocation via REST pre-dispatch on the Abilities API
 * namespace (`wp-abilities/v1`). Bucket counts in transients; reject with 429 once
 * exceeded. This is enforced *before* a model call is made — no provider spend.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Governance;

final class Rate_Limiter {

	private const NS = 'wp-abilities/v1';

	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'enforce' ), 10, 3 );
	}

	/**
	 * @param mixed            $result  Existing short-circuit result (or null).
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 */
	public function enforce( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}
		$route = (string) $request->get_route();
		if ( ! str_contains( $route, self::NS ) ) {
			return $result;
		}

		$user_id    = get_current_user_id();
		$ability_id = $this->route_to_ability( $route );
		$limits     = $this->limits();

		foreach ( array(
			'minute' => 60,
			'day'    => DAY_IN_SECONDS,
		) as $window => $ttl ) {
			$max = (int) ( $limits[ $window ] ?? 0 );
			if ( $max <= 0 ) {
				continue;
			}
			$key   = sprintf( 'extai_rl_%s_%d_%s', $window, $user_id, md5( $ability_id ) );
			$count = (int) get_transient( $key );
			if ( $count >= $max ) {
				return new \WP_Error(
					'extend_ai_rate_limited',
					sprintf( 'AI rate limit exceeded (%d per %s).', $max, $window ),
					array( 'status' => 429 )
				);
			}
			set_transient( $key, $count + 1, $ttl );
		}

		return $result;
	}

	private function route_to_ability( string $route ): string {
		// `/wp-abilities/v1/ai/title-generation/run` → `ai/title-generation`.
		$tail = preg_replace( '#^/' . self::NS . '/#', '', $route ) ?? '';
		$tail = preg_replace( '#/(run|describe).*$#', '', $tail ) ?? $tail;
		return (string) $tail;
	}

	/** @return array<string,int> */
	private function limits(): array {
		$opt = (array) get_option(
			'extend_ai_rate_limits',
			array(
				'minute' => 20,
				'day'    => 500,
			)
		);
		/** @var array<string,int> */
		return (array) apply_filters( 'extend_ai_rate_limits', $opt );
	}
}
