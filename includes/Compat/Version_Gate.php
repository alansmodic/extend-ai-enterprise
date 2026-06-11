<?php
/**
 * Tracks the WordPress AI plugin version this build was tested against and
 * surfaces an admin notice (non-blocking) when the running version drifts
 * outside the tested range. Visible signal beats silent breakage.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Compat;

final class Version_Gate {

	/** Lowest WP AI version we've verified against. */
	public const TESTED_MIN = '1.0.0';

	/**
	 * Ceiling of the WP AI compatibility band this build targets — NOT a version
	 * we pin-test. We verify the band's endpoints in CI (currently 1.0.0 and
	 * 1.0.1) and trust patch releases within it; the nightly drift cron and the
	 * `@develop` contract leg catch real breakage. Raise this when moving the
	 * band to a new minor (e.g. 1.1.x).
	 */
	public const TESTED_MAX = '1.0.99';

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
	}

	public function maybe_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! defined( 'WPAI_VERSION' ) ) {
			return;
		}
		$current = (string) WPAI_VERSION;

		if ( version_compare( $current, self::TESTED_MIN, '>=' ) && version_compare( $current, self::TESTED_MAX, '<=' ) ) {
			return;
		}

		$direction = version_compare( $current, self::TESTED_MAX, '>' ) ? 'newer' : 'older';
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Extend AI — Enterprise:', 'extend-ai-enterprise' ),
			esc_html(
				sprintf(
				/* translators: 1: running WP AI version, 2: direction, 3-4: tested range */
					__( 'The active WordPress AI plugin (v%1$s) is %2$s than the range this governance layer was tested against (v%3$s–v%4$s). Governance hooks may behave unexpectedly. Verify filter and REST contracts before relying on enforcement.', 'extend-ai-enterprise' ),
					$current,
					$direction,
					self::TESTED_MIN,
					self::TESTED_MAX
				)
			)
		);
	}
}
