<?php
/**
 * Plugin Name: Extend AI — Enterprise
 * Description: Enterprise governance wrapper around the WordPress AI plugin (wordpress/ai). Adds policy, RBAC, audit retention, rate limits, cost tracking, and output moderation via the plugin's documented filters — no fork required.
 * Version: 0.2.0
 * Requires at least: 7.1
 * Requires PHP: 8.1
 * Requires Plugins: ai
 * Author: Extend AI
 * License: GPL-2.0-or-later
 * Text Domain: extend-ai-enterprise
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise;

defined( 'ABSPATH' ) || exit;

const VERSION   = '0.2.0';
const PLUGIN_ID = 'extend-ai-enterprise';
const MIN_WP    = '7.1';

define( 'EXTEND_AI_ENTERPRISE_FILE', __FILE__ );
define( 'EXTEND_AI_ENTERPRISE_DIR', __DIR__ );

require_once __DIR__ . '/includes/autoload.php';

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( function_exists( 'is_wp_version_compatible' ) && ! is_wp_version_compatible( MIN_WP ) ) {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html(
					sprintf(
					/* translators: %s: minimum WordPress version */
						__( 'Extend AI — Enterprise requires WordPress %s or later. The governance gates hook the Abilities API execution lifecycle, which is inert on older releases.', 'extend-ai-enterprise' ),
						MIN_WP
					)
				)
			);
		}

		Storage\Usage_Repository::install();
		Storage\Prompt_Library::install();
		Compat\Upgrader::maybe_run();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'WPAI_VERSION' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'Extend AI — Enterprise requires the WordPress AI plugin to be active.', 'extend-ai-enterprise' );
					echo '</p></div>';
				}
			);
			return;
		}

		if ( ! class_exists( \WP_Filter_Sentinel::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html(
						sprintf(
						/* translators: %s: minimum WordPress version */
							__( 'Extend AI — Enterprise requires WordPress %s or later (WP_Filter_Sentinel / Abilities execution lifecycle). Governance is not active.', 'extend-ai-enterprise' ),
							MIN_WP
						)
					);
					echo '</p></div>';
				}
			);
			return;
		}

		Plugin::instance()->boot();
	},
	20
);
