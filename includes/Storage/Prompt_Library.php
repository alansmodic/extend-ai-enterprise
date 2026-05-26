<?php
/**
 * Per-ability prompt overrides. One row per ability_id.
 *
 * Mode controls how the override combines with the WP AI plugin's default
 * system instruction: `prepend`, `append`, or `replace`.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Storage;

final class Prompt_Library {

	public const MODE_PREPEND = 'prepend';
	public const MODE_APPEND  = 'append';
	public const MODE_REPLACE = 'replace';
	public const MODES        = array( self::MODE_PREPEND, self::MODE_APPEND, self::MODE_REPLACE );

	private const VERSION = '1';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'extend_ai_prompts';
	}

	public static function history_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'extend_ai_prompts_history';
	}

	public static function install(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$current = 'CREATE TABLE ' . self::table() . " (
			ability_id   VARCHAR(191)    NOT NULL,
			mode         VARCHAR(16)     NOT NULL DEFAULT 'prepend',
			template     LONGTEXT        NOT NULL,
			updated_by   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at   DATETIME        NOT NULL,
			PRIMARY KEY (ability_id)
		) {$charset};";

		$history = 'CREATE TABLE ' . self::history_table() . " (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ability_id   VARCHAR(191)    NOT NULL,
			action       VARCHAR(16)     NOT NULL DEFAULT 'put',
			mode         VARCHAR(16)     NOT NULL DEFAULT '',
			template     LONGTEXT        NOT NULL,
			updated_by   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at   DATETIME        NOT NULL,
			PRIMARY KEY (id),
			KEY ability_id (ability_id),
			KEY updated_at (updated_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $current );
		dbDelta( $history );

		update_option( 'extend_ai_prompts_schema', self::VERSION, false );
	}

	/** @return array{mode:string, template:string, updated_by:int, updated_at:string}|null */
	public function get( string $ability_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT mode, template, updated_by, updated_at FROM ' . self::table() . ' WHERE ability_id = %s',
				$ability_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}
		return array(
			'mode'       => (string) $row['mode'],
			'template'   => (string) $row['template'],
			'updated_by' => (int) $row['updated_by'],
			'updated_at' => (string) $row['updated_at'],
		);
	}

	/** @return array<string, array{mode:string, template:string, updated_by:int, updated_at:string}> */
	public function all(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT ability_id, mode, template, updated_by, updated_at FROM ' . self::table(), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['ability_id'] ] = array(
				'mode'       => (string) $r['mode'],
				'template'   => (string) $r['template'],
				'updated_by' => (int) $r['updated_by'],
				'updated_at' => (string) $r['updated_at'],
			);
		}
		return $out;
	}

	public function put( string $ability_id, string $mode, string $template, int $user_id ): void {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			$mode = self::MODE_PREPEND;
		}
		global $wpdb;
		$now = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (ability_id, mode, template, updated_by, updated_at)
			 VALUES (%s, %s, %s, %d, %s)
			 ON DUPLICATE KEY UPDATE mode = VALUES(mode), template = VALUES(template),
			                        updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
				$ability_id,
				$mode,
				$template,
				$user_id,
				$now
			)
		);

		$this->record_history( $ability_id, 'put', $mode, $template, $user_id, $now );
	}

	public function delete( string $ability_id ): void {
		global $wpdb;
		$existing = $this->get( $ability_id );
		$wpdb->delete( self::table(), array( 'ability_id' => $ability_id ), array( '%s' ) );

		if ( $existing ) {
			$this->record_history(
				$ability_id,
				'delete',
				$existing['mode'],
				$existing['template'],
				get_current_user_id(),
				current_time( 'mysql', true )
			);
		}
	}

	/** @return array<int, array{action:string, mode:string, template:string, updated_by:int, updated_at:string}> */
	public function history( string $ability_id, int $limit = 50 ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT action, mode, template, updated_by, updated_at FROM ' . self::history_table()
				. ' WHERE ability_id = %s ORDER BY id DESC LIMIT %d',
				$ability_id,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static fn( $r ) => array(
				'action'     => (string) $r['action'],
				'mode'       => (string) $r['mode'],
				'template'   => (string) $r['template'],
				'updated_by' => (int) $r['updated_by'],
				'updated_at' => (string) $r['updated_at'],
			),
			$rows
		);
	}

	private function record_history( string $ability_id, string $action, string $mode, string $template, int $user_id, string $when ): void {
		global $wpdb;
		$wpdb->insert(
			self::history_table(),
			array(
				'ability_id' => $ability_id,
				'action'     => $action,
				'mode'       => $mode,
				'template'   => $template,
				'updated_by' => $user_id,
				'updated_at' => $when,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}
}
