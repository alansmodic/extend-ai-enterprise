<?php
/**
 * Aggregates token usage and cost into the extend_ai_usage table, and enforces
 * monthly per-user spend caps.
 *
 * The cap is enforced at the capability layer: once a user is over budget we
 * deny every `wp_ability_*` capability via `user_has_cap`, which stops the AI
 * request at the Abilities API permission check. Admins (`manage_options`) are
 * exempt so a blown cap can't lock out whoever needs to raise it; override with
 * the `extend_ai_budget_cap_exempt` filter. We deliberately do NOT enforce
 * by emptying the model allowlist — `Model_Allowlist` treats an empty allowlist
 * as "allow all defaults", so that path would reopen access instead of blocking
 * it, and an empty preferred-models list only clears the model preference (the
 * SDK still falls back to a default model) rather than blocking generation.
 *
 * Subscribes to `extend_ai_request_completed` (emitted by Logging\Transporter_Wrap).
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Governance;

use ExtendAI\Enterprise\Storage\Usage_Repository;

final class Cost_Tracker {

	public function __construct( private ?Usage_Repository $repo = null ) {
		$this->repo ??= new Usage_Repository();
	}

	/** @var array<int,bool> Per-request memo of over-budget state, keyed by user ID. */
	private array $over_budget_cache = array();

	public function register(): void {
		add_action( 'extend_ai_request_completed', array( $this, 'record' ), 10, 1 );
		add_filter( 'user_has_cap', array( $this, 'gate_over_budget' ), 10, 4 );
	}

	/** @param array<string,mixed> $payload */
	public function record( array $payload ): void {
		if ( ( $payload['status'] ?? '' ) !== 'success' ) {
			return;
		}
		$tokens_in  = (int) ( $payload['tokens_input'] ?? 0 );
		$tokens_out = (int) ( $payload['tokens_output'] ?? 0 );
		$provider   = (string) ( $payload['provider'] ?? '' );
		$model      = (string) ( $payload['model'] ?? '' );

		$usd = $this->price( $provider, $model, $tokens_in, $tokens_out );

		$this->repo->increment(
			(int) ( $payload['user_id'] ?? 0 ),
			gmdate( 'Y-m' ),
			$provider,
			$model,
			$tokens_in,
			$tokens_out,
			$usd
		);

		// Spend just changed — drop the memo so a later cap check this request re-reads it.
		unset( $this->over_budget_cache[ (int) ( $payload['user_id'] ?? 0 ) ] );
	}

	/**
	 * Deny every AI ability capability for a user who is over their monthly cap.
	 *
	 * `user_has_cap` fires on every capability check, so we bail fast for any cap
	 * that isn't a `wp_ability_*` check and memoize the (DB-backed) budget lookup
	 * per user for the rest of the request.
	 *
	 * @param array<string,bool> $allcaps All capabilities the user currently has.
	 * @param array<int,string>  $caps    Primitive caps being checked.
	 * @param array<int,mixed>   $args    [ $cap, $user_id, ...context ].
	 * @param \WP_User           $user    The user whose caps are being evaluated.
	 * @return array<string,bool>
	 */
	public function gate_over_budget( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		$cap = (string) ( $args[0] ?? '' );
		if ( ! str_starts_with( $cap, 'wp_ability_' ) ) {
			return $allcaps;
		}
		if ( $this->is_exempt( $allcaps, $user ) ) {
			return $allcaps;
		}
		if ( ! $this->is_over_budget( (int) $user->ID ) ) {
			return $allcaps;
		}
		foreach ( $caps as $required ) {
			$allcaps[ $required ] = false;
		}
		return $allcaps;
	}

	/**
	 * Whether a user is exempt from the spend cap. Admins are exempt by default
	 * so a blown budget can't lock out the person who needs to raise it. Reads
	 * `manage_options` from the already-resolved $allcaps to avoid re-entering
	 * the capability check.
	 *
	 * @param array<string,bool> $allcaps Capabilities resolved for this user.
	 * @param \WP_User           $user    The user being evaluated.
	 */
	private function is_exempt( array $allcaps, \WP_User $user ): bool {
		$exempt = ! empty( $allcaps['manage_options'] );

		/**
		 * Filter whether a user is exempt from the monthly spend cap.
		 *
		 * @param bool     $exempt Default: true for users with `manage_options`.
		 * @param \WP_User $user   The user being evaluated.
		 */
		return (bool) apply_filters( 'extend_ai_budget_cap_exempt', $exempt, $user );
	}

	private function is_over_budget( int $user_id ): bool {
		if ( isset( $this->over_budget_cache[ $user_id ] ) ) {
			return $this->over_budget_cache[ $user_id ];
		}
		$cap = (float) get_option( 'extend_ai_monthly_user_cap_usd', 0 );
		if ( $cap <= 0 ) {
			$this->over_budget_cache[ $user_id ] = false;
			return false;
		}
		$spent = $this->repo->spent_in_period( $user_id, gmdate( 'Y-m' ) );
		$over  = ( $spent >= $cap );

		$this->over_budget_cache[ $user_id ] = $over;
		return $over;
	}

	private function price( string $provider, string $model, int $tokens_in, int $tokens_out ): float {
		// Per-1k-token rates. Filter to override per-model. TODO: real pricing table.
		$default_in  = 0.003;
		$default_out = 0.015;

		/** @var float $rate_in  */
		$rate_in = (float) apply_filters( 'extend_ai_token_rate_input', $default_in, $provider, $model );
		/** @var float $rate_out */
		$rate_out = (float) apply_filters( 'extend_ai_token_rate_output', $default_out, $provider, $model );

		return ( $tokens_in / 1000 ) * $rate_in + ( $tokens_out / 1000 ) * $rate_out;
	}
}
