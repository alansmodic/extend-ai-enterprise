<?php
/**
 * Audit retention. Two responsibilities:
 *
 *  1. Set the retention window on the WP AI request log via the plugin's own
 *     filter (`wpai_request_log_retention_days`). WP AI handles the cron itself.
 *
 *  2. Purge old rows from our own `extend_ai_usage` rollup table via daily cron.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Governance;

use ExtendAI\Enterprise\Storage\Usage_Repository;

final class Retention {

	private const HOOK = 'extend_ai_usage_sweep';

	public function __construct( private ?Usage_Repository $repo = null ) {
		$this->repo ??= new Usage_Repository();
	}

	public function register(): void {
		add_filter( 'wpai_request_log_retention_days', [ $this, 'log_retention_days' ] );

		add_action( self::HOOK, [ $this, 'sweep_usage' ] );
		add_action( 'init',     [ $this, 'schedule' ] );
	}

	public function log_retention_days( int $current ): int {
		$days = (int) get_option( 'extend_ai_log_retention_days', 0 );
		return $days > 0 ? $days : $current;
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public function sweep_usage(): void {
		$months = (int) get_option( 'extend_ai_usage_retention_months', 24 );
		if ( $months <= 0 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m', strtotime( "-{$months} months" ) );
		$count  = $this->repo->purge_older_than( $cutoff );
		do_action( 'extend_ai_usage_swept', $count, $cutoff );
	}
}
