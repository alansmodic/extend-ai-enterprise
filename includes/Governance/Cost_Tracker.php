<?php
/**
 * Aggregates token usage and cost into the extend_ai_usage table, and enforces
 * monthly per-user spend caps by dropping the model allowlist to empty when over.
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

	public function register(): void {
		add_action( 'extend_ai_request_completed', [ $this, 'record' ], 10, 1 );
		add_filter( 'extend_ai_model_allowlist',   [ $this, 'enforce_budget' ], 10, 2 );
	}

	/** @param array<string,mixed> $payload */
	public function record( array $payload ): void {
		if ( ( $payload['status'] ?? '' ) !== 'success' ) {
			return;
		}
		$tokens_in  = (int) ( $payload['tokens_input']  ?? 0 );
		$tokens_out = (int) ( $payload['tokens_output'] ?? 0 );
		$provider   = (string) ( $payload['provider'] ?? '' );
		$model      = (string) ( $payload['model']    ?? '' );

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
	}

	/** @param array<int, array{0:string,1:string}> $list */
	public function enforce_budget( array $list, string $capability ): array {
		$cap = (float) get_option( 'extend_ai_monthly_user_cap_usd', 0 );
		if ( $cap <= 0 ) {
			return $list;
		}
		$spent = $this->repo->spent_in_period( get_current_user_id(), gmdate( 'Y-m' ) );
		return $spent >= $cap ? [] : $list;
	}

	private function price( string $provider, string $model, int $tokens_in, int $tokens_out ): float {
		// Per-1k-token rates. Filter to override per-model. TODO: real pricing table.
		$default_in  = 0.003;
		$default_out = 0.015;

		/** @var float $rate_in  */
		$rate_in  = (float) apply_filters( 'extend_ai_token_rate_input',  $default_in,  $provider, $model );
		/** @var float $rate_out */
		$rate_out = (float) apply_filters( 'extend_ai_token_rate_output', $default_out, $provider, $model );

		return ( $tokens_in / 1000 ) * $rate_in + ( $tokens_out / 1000 ) * $rate_out;
	}
}
