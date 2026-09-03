<?php
/**
 * One-shot schema migrations between plugin versions.
 *
 * WordPress does not fire the activation hook on updates, so this runs on
 * every boot and no-ops once `extend_ai_schema_version` matches VERSION.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Compat;

use ExtendAI\Enterprise\Storage\Prompt_Library;

final class Upgrader {

	public const OPTION = 'extend_ai_schema_version';

	/** Placeholders the 0.1 Guidelines_Bridge interpolated; 0.2 defines none of them. */
	private const STALE_GUIDELINES_VARS = array(
		'{guidelines}',
		'{guidelines_site}',
		'{guidelines_copy}',
		'{guidelines_images}',
		'{guidelines_additional}',
		'{guidelines_blocks}',
	);

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
		self::maybe_run();
	}

	/**
	 * Apply any migrations whose target version is newer than the stored schema.
	 */
	public static function maybe_run(): void {
		$from = (string) get_option( self::OPTION, '0.1.0' );
		if ( version_compare( $from, \ExtendAI\Enterprise\VERSION, '>=' ) ) {
			return;
		}

		if ( version_compare( $from, '0.2.0', '<' ) ) {
			self::migrate_to_0_2_0();
		}

		update_option( self::OPTION, \ExtendAI\Enterprise\VERSION, false );
	}

	private static function migrate_to_0_2_0(): void {
		delete_option( 'extend_ai_use_guidelines' );

		self::rename_comment_moderation_ability();
		$rewritten = self::strip_stale_guidelines_placeholders();

		if ( $rewritten > 0 ) {
			set_transient( 'extend_ai_upgrade_notice_0_2', $rewritten, WEEK_IN_SECONDS );
		}
	}

	/**
	 * WP AI registers `ai/comment-analysis` (feature id is still comment-moderation).
	 * 0.1 stored the wrong ability id; Role_Gate treated a miss as "not configured"
	 * and failed open.
	 */
	private static function rename_comment_moderation_ability(): void {
		$library = new Prompt_Library();
		$old     = $library->get( 'ai/comment-moderation' );
		if ( $old && null === $library->get( 'ai/comment-analysis' ) ) {
			$library->put(
				'ai/comment-analysis',
				$old['mode'],
				$old['template'],
				$old['updated_by']
			);
		}
		if ( $old ) {
			$library->delete( 'ai/comment-moderation' );
		}

		$map = get_option( 'extend_ai_role_map', null );
		if ( is_array( $map ) && isset( $map['ai/comment-moderation'] ) ) {
			if ( ! isset( $map['ai/comment-analysis'] ) ) {
				$map['ai/comment-analysis'] = $map['ai/comment-moderation'];
			}
			unset( $map['ai/comment-moderation'] );
			update_option( 'extend_ai_role_map', $map );
		}
	}

	/**
	 * `{guidelines*}` variables are gone with Guidelines_Bridge. interpolate()
	 * leaves unknown placeholders verbatim, so they would be sent to the model
	 * as literal text.
	 *
	 * @return int Number of stored templates rewritten or deleted.
	 */
	private static function strip_stale_guidelines_placeholders(): int {
		$library = new Prompt_Library();
		$count   = 0;

		foreach ( $library->all() as $ability_id => $row ) {
			$template = (string) $row['template'];
			$cleaned  = str_ireplace( self::STALE_GUIDELINES_VARS, '', $template );
			$cleaned  = trim( (string) preg_replace( "/\n{3,}/", "\n\n", $cleaned ) );

			if ( $cleaned === $template ) {
				continue;
			}

			++$count;
			if ( $cleaned === '' ) {
				$library->delete( $ability_id );
				continue;
			}

			$library->put( $ability_id, $row['mode'], $cleaned, (int) $row['updated_by'] );
		}

		return $count;
	}

	public function maybe_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$count = get_transient( 'extend_ai_upgrade_notice_0_2' );
		if ( ! is_numeric( $count ) || (int) $count < 1 ) {
			return;
		}
		delete_transient( 'extend_ai_upgrade_notice_0_2' );

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Extend AI — Enterprise:', 'extend-ai-enterprise' ),
			esc_html(
				sprintf(
				/* translators: %d: number of prompt templates rewritten */
					_n(
						'%d stored prompt template referenced the removed {guidelines*} variables and was updated. Review Tools → AI Prompts.',
						'%d stored prompt templates referenced the removed {guidelines*} variables and were updated. Review Tools → AI Prompts.',
						(int) $count,
						'extend-ai-enterprise'
					),
					(int) $count
				)
			)
		);
	}
}
