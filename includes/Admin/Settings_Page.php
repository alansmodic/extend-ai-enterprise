<?php
/**
 * Two admin pages under Tools:
 *   - Tools → AI Enterprise          (policy form)
 *   - Tools → AI Enterprise · Prompts (React app for per-ability prompts)
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Admin;

final class Settings_Page {

	private const SLUG_MAIN    = 'extend-ai-enterprise';
	private const SLUG_PROMPTS = 'extend-ai-enterprise-prompts';

	public function register(): void {
		add_action( 'admin_menu',         [ $this, 'menu' ] );
		add_action( 'admin_init',         [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function menu(): void {
		add_submenu_page(
			'tools.php',
			__( 'AI Enterprise', 'extend-ai-enterprise' ),
			__( 'AI Enterprise', 'extend-ai-enterprise' ),
			'manage_options',
			self::SLUG_MAIN,
			[ $this, 'render_main' ]
		);
		add_submenu_page(
			'tools.php',
			__( 'AI Prompts', 'extend-ai-enterprise' ),
			__( 'AI Prompts', 'extend-ai-enterprise' ),
			'manage_options',
			self::SLUG_PROMPTS,
			[ $this, 'render_prompts' ]
		);
	}

	public function enqueue( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::SLUG_PROMPTS ) ) {
			return;
		}
		$src = plugins_url( 'assets/admin.js', EXTEND_AI_ENTERPRISE_FILE );

		wp_enqueue_script(
			'extend-ai-enterprise-admin',
			$src,
			[ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-dom-ready', 'wp-i18n' ],
			(string) filemtime( EXTEND_AI_ENTERPRISE_DIR . '/assets/admin.js' ),
			true
		);
		wp_enqueue_style( 'wp-components' );
	}

	public function register_settings(): void {
		foreach ( [
			'extend_ai_policy_preamble',
			'extend_ai_rate_limits',
			'extend_ai_monthly_user_cap_usd',
			'extend_ai_log_retention_days',
			'extend_ai_model_allowlist',
			'extend_ai_disabled_features',
			'extend_ai_banned_phrases',
			'extend_ai_redact_pii',
			'extend_ai_role_map',
			'extend_ai_vault_enabled',
		] as $option ) {
			register_setting( 'extend_ai_enterprise', $option );
		}
	}

	public function render_main(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Enterprise', 'extend-ai-enterprise' ); ?></h1>
			<p>
				<?php esc_html_e( 'Governance layer for the WordPress AI plugin. Per-ability prompts are managed under', 'extend-ai-enterprise' ); ?>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::SLUG_PROMPTS ) ); ?>"><?php esc_html_e( 'Tools → AI Prompts', 'extend-ai-enterprise' ); ?></a>.
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'extend_ai_enterprise' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="extend_ai_policy_preamble"><?php esc_html_e( 'Global policy preamble', 'extend-ai-enterprise' ); ?></label></th>
						<td>
							<textarea id="extend_ai_policy_preamble" name="extend_ai_policy_preamble" rows="5" class="large-text code"><?php
								echo esc_textarea( (string) get_option( 'extend_ai_policy_preamble', '' ) );
							?></textarea>
							<p class="description"><?php esc_html_e( 'Prepended to every AI ability prompt, after any per-ability override.', 'extend-ai-enterprise' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="extend_ai_monthly_user_cap_usd"><?php esc_html_e( 'Monthly cap per user (USD)', 'extend-ai-enterprise' ); ?></label></th>
						<td><input type="number" step="0.01" id="extend_ai_monthly_user_cap_usd" name="extend_ai_monthly_user_cap_usd" value="<?php echo esc_attr( (string) get_option( 'extend_ai_monthly_user_cap_usd', 0 ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="extend_ai_log_retention_days"><?php esc_html_e( 'Log retention (days)', 'extend-ai-enterprise' ); ?></label></th>
						<td><input type="number" id="extend_ai_log_retention_days" name="extend_ai_log_retention_days" value="<?php echo esc_attr( (string) get_option( 'extend_ai_log_retention_days', 90 ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="extend_ai_redact_pii"><?php esc_html_e( 'Redact PII in inputs', 'extend-ai-enterprise' ); ?></label></th>
						<td><input type="checkbox" id="extend_ai_redact_pii" name="extend_ai_redact_pii" value="1" <?php checked( (bool) get_option( 'extend_ai_redact_pii', true ) ); ?> /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function render_prompts(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Prompts', 'extend-ai-enterprise' ); ?></h1>
			<div id="extend-ai-enterprise-prompts-root"></div>
		</div>
		<?php
	}
}
