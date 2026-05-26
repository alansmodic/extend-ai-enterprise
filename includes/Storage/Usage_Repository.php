<?php
/**
 * Custom table for per-user / per-period AI spend rollups.
 *
 * One row per (user_id, period, provider, model). Periods are `YYYY-MM` strings
 * for monthly rollups; if you need daily, store `YYYY-MM-DD` instead and switch
 * the index. Updates are idempotent via ON DUPLICATE KEY UPDATE.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Storage;

final class Usage_Repository {

	private const VERSION = '1';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'extend_ai_usage';
	}

	public static function install(): void {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			period       CHAR(7)         NOT NULL,
			provider     VARCHAR(64)     NOT NULL DEFAULT '',
			model        VARCHAR(128)    NOT NULL DEFAULT '',
			requests     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tokens_in    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tokens_out   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			usd_spent    DECIMAL(12,6)   NOT NULL DEFAULT 0,
			updated_at   DATETIME        NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_period_model (user_id, period, provider, model),
			KEY period (period),
			KEY user_id (user_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'extend_ai_usage_schema', self::VERSION, false );
	}

	/**
	 * Increment a rollup row. Safe under concurrency via UNIQUE key + UPSERT.
	 */
	public function increment(
		int $user_id,
		string $period,
		string $provider,
		string $model,
		int $tokens_in,
		int $tokens_out,
		float $usd
	): void {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				(user_id, period, provider, model, requests, tokens_in, tokens_out, usd_spent, updated_at)
			 VALUES (%d, %s, %s, %s, 1, %d, %d, %f, %s)
			 ON DUPLICATE KEY UPDATE
				requests   = requests + 1,
				tokens_in  = tokens_in + VALUES(tokens_in),
				tokens_out = tokens_out + VALUES(tokens_out),
				usd_spent  = usd_spent + VALUES(usd_spent),
				updated_at = VALUES(updated_at)",
				$user_id,
				$period,
				$provider,
				$model,
				$tokens_in,
				$tokens_out,
				$usd,
				$now
			)
		);
	}

	public function spent_in_period( int $user_id, string $period ): float {
		global $wpdb;
		$table = self::table();
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(usd_spent), 0) FROM {$table} WHERE user_id = %d AND period = %s",
				$user_id,
				$period
			)
		);
	}

	/**
	 * @return array<int, array{user_id:int, requests:int, tokens_in:int, tokens_out:int, usd_spent:float}>
	 */
	public function by_user_for_period( string $period ): array {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id,
			        SUM(requests)   AS requests,
			        SUM(tokens_in)  AS tokens_in,
			        SUM(tokens_out) AS tokens_out,
			        SUM(usd_spent)  AS usd_spent
			   FROM {$table}
			  WHERE period = %s
			  GROUP BY user_id
			  ORDER BY usd_spent DESC",
				$period
			),
			ARRAY_A
		);

		return array_map(
			static fn( $r ) => array(
				'user_id'    => (int) $r['user_id'],
				'requests'   => (int) $r['requests'],
				'tokens_in'  => (int) $r['tokens_in'],
				'tokens_out' => (int) $r['tokens_out'],
				'usd_spent'  => (float) $r['usd_spent'],
			),
			$rows
		);
	}

	public function purge_older_than( string $cutoff_period ): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE period < %s",
				$cutoff_period
			)
		);
	}
}
