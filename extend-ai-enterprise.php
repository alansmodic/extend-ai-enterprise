<?php
/**
 * Plugin Name: Extend AI — Enterprise
 * Description: Enterprise governance wrapper around the WordPress AI plugin (wordpress/ai). Adds policy, RBAC, audit retention, rate limits, cost tracking, and output moderation via the plugin's documented filters — no fork required.
 * Version: 0.1.0
 * Requires at least: 6.6
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

const VERSION   = '0.1.0';
const PLUGIN_ID = 'extend-ai-enterprise';

define( 'EXTEND_AI_ENTERPRISE_FILE', __FILE__ );
define( 'EXTEND_AI_ENTERPRISE_DIR', __DIR__ );

require_once __DIR__ . '/includes/autoload.php';

register_activation_hook(
	__FILE__,
	static function (): void {
		Storage\Usage_Repository::install();
		Storage\Prompt_Library::install();
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

		Plugin::instance()->boot();
	},
	20
);
