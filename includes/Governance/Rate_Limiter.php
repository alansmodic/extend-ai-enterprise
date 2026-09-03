<?php
/**
 * Per-user / per-feature rate limiting and burst control.
 *
 * Strategy: intercept ability execution via the WordPress 7.1 wp_pre_execute_ability
 * lifecycle filter. This enforces limits for all execution paths — REST, MCP, WP-CLI,
 * and direct WP_Ability::execute() calls — before any model call is made. Bucket
 * counts are stored in transients; exceed the limit and execution short-circuits
 * with a WP_Error.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Governance;

final class Rate_Limiter {

	public function register(): void {
		add_filter( 'wp_pre_execute_ability', array( $this, 'enforce' ), 10, 4 );
	}

	/**
	 * Enforce per-user rate limits before ability execution begins.
	 *
	 * @param \WP_Filter_Sentinel $sentinel     The sentinel instance. Must return this unchanged to pass.
	 * @param string              $ability_name The ability being executed (e.g. 'ai/title-generation').
	 * @param mixed               $input        Normalized input (not yet validated).
	 * @param \WP_Ability         $ability      The ability instance.
	 * @return \WP_Filter_Sentinel|\WP_Error Sentinel to proceed, WP_Error to short-circuit.
	 */
	public function enforce( $sentinel, string $ability_name, $input, $ability ) {
		unset( $input, $ability );

		// Only enforce for AI abilities, not arbitrary WordPress abilities.
		if ( ! str_starts_with( $ability_name, 'ai/' ) ) {
			return $sentinel;
		}

		$user_id = get_current_user_id();
		$limits  = $this->limits();

		foreach ( array(
			'minute' => 60,
			'day'    => DAY_IN_SECONDS,
		) as $window => $ttl ) {
			$max = (int) ( $limits[ $window ] ?? 0 );
			if ( $max <= 0 ) {
				continue;
			}
			$key   = sprintf( 'extai_rl_%s_%d_%s', $window, $user_id, md5( $ability_name ) );
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

		return $sentinel;
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
