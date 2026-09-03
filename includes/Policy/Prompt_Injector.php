<?php
/**
 * Per-ability prompt customization. Applies overrides stored in Prompt_Library
 * to WordPress AI 1.3's native per-ability filters (wpai_{slug}_system_instruction).
 *
 * Resolution order, applied in sequence on top of the WP AI default:
 *   1. Global policy preamble (option `extend_ai_policy_preamble`) — prepended via
 *      the global wpai_system_instruction filter.
 *   2. Per-ability override (table `wp_extend_ai_prompts`) — prepend/append/replace
 *      via per-ability wpai_{slug}_system_instruction filters.
 *
 * Templates support `{var}` interpolation from the filter's `$data` payload plus
 * a handful of built-in variables (user_login, site_name, current_date).
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Policy;

use ExtendAI\Enterprise\Storage\Prompt_Library;

final class Prompt_Injector {

	public function __construct(
		private ?Prompt_Library $library = null
	) {
		$this->library ??= new Prompt_Library();
	}

	public function register(): void {
		// Global policy preamble on the global filter.
		add_filter( 'wpai_system_instruction', array( $this, 'inject_global_preamble' ), 10, 3 );

		// Register per-ability filters for all abilities with overrides.
		add_action( 'wp_abilities_api_init', array( $this, 'register_per_ability_filters' ), 20 );
	}

	/**
	 * Apply global policy preamble to all abilities.
	 *
	 * @param string              $instruction  Default system instruction from the ability.
	 * @param string              $ability_name e.g. "ai/title-generation".
	 * @param array<string,mixed> $data         Per-call data the ability is about to use.
	 */
	public function inject_global_preamble( string $instruction, string $ability_name, array $data ): string {
		unset( $data );
		$default  = (string) get_option( 'extend_ai_policy_preamble', '' );
		$preamble = (string) apply_filters( 'extend_ai_policy_preamble', $default, $ability_name );
		return $preamble === '' ? $instruction : $preamble . "\n\n" . $instruction;
	}

	/**
	 * Register per-ability filters for abilities with prompt overrides.
	 * Called on wp_abilities_api_init so we can enumerate registered abilities.
	 */
	public function register_per_ability_filters(): void {
		// Get all abilities that have prompt overrides.
		global $wpdb;
		$table     = $wpdb->prefix . 'extend_ai_prompts';
		$abilities = $wpdb->get_col( "SELECT ability_id FROM {$table}" );

		foreach ( $abilities as $ability_id ) {
			$slug = $this->ability_to_slug( $ability_id );
			add_filter( "wpai_{$slug}_system_instruction", array( $this, 'inject_per_ability_override' ), 10, 3 );
		}
	}

	/**
	 * Apply per-ability prompt override using the native per-ability filter.
	 *
	 * @param string              $instruction  Default system instruction from the ability.
	 * @param string              $ability_name e.g. "ai/title-generation".
	 * @param array<string,mixed> $data         Per-call data the ability is about to use.
	 */
	public function inject_per_ability_override( string $instruction, string $ability_name, array $data ): string {
		$override = $this->library->get( $ability_name );
		if ( ! $override ) {
			return $instruction;
		}

		$template = $this->interpolate( $override['template'], $ability_name, $data );

		return match ( $override['mode'] ) {
			Prompt_Library::MODE_REPLACE => $template,
			Prompt_Library::MODE_APPEND  => rtrim( $instruction ) . "\n\n" . $template,
			default                      => $template . "\n\n" . ltrim( $instruction ),
		};
	}


	/** @param array<string,mixed> $data */
	private function interpolate( string $template, string $ability_name, array $data ): string {
		$vars = $this->variables( $ability_name, $data );

		return (string) preg_replace_callback(
			'/\{([a-z0-9_.]+)\}/i',
			static function ( array $m ) use ( $vars ): string {
				$key = strtolower( $m[1] );
				return array_key_exists( $key, $vars ) ? (string) $vars[ $key ] : $m[0];
			},
			$template
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,scalar>
	 */
	private function variables( string $ability_name, array $data ): array {
		$user = wp_get_current_user();
		$vars = array(
			'ability'      => $ability_name,
			'user_login'   => $user instanceof \WP_User ? $user->user_login : '',
			'user_role'    => $user instanceof \WP_User ? ( $user->roles[0] ?? '' ) : '',
			'site_name'    => (string) get_bloginfo( 'name' ),
			'site_url'     => (string) home_url(),
			'current_date' => gmdate( 'Y-m-d' ),
		);

		// If the ability passed a post_id, expose common post fields.
		$post_id = (int) ( $data['post_id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( $post ) {
			$vars['post_title']  = (string) $post->post_title;
			$vars['post_type']   = (string) $post->post_type;
			$vars['post_status'] = (string) $post->post_status;
		}

		// Flat scalar values from $data are exposed as-is for free.
		foreach ( $data as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$vars[ strtolower( (string) $k ) ] = $v;
			}
		}

		/**
		 * Filter the variable map available to prompt templates.
		 *
		 * @param array<string,scalar> $vars
		 * @param string               $ability_name
		 * @param array<string,mixed>  $data
		 */
		return (array) apply_filters( 'extend_ai_prompt_variables', $vars, $ability_name, $data );
	}

	/**
	 * Convert ability ID to slug for filter name.
	 * ai/title-generation → title_generation
	 */
	private function ability_to_slug( string $ability_id ): string {
		return str_replace( array( 'ai/', '/' ), array( '', '_' ), $ability_id );
	}
}
