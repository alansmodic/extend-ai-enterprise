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
	 * Register a scoped filter for each ability that currently has an override.
	 *
	 * Runs on wp_abilities_api_init so WP AI's abilities (and therefore its
	 * `wpai_{slug}_system_instruction` hooks) exist by the time we subscribe.
	 */
	public function register_per_ability_filters(): void {
		foreach ( array_keys( $this->library->all() ) as $ability_id ) {
			$ability_id = (string) $ability_id;
			$slug       = self::ability_to_slug( $ability_id );
			if ( '' === $slug ) {
				continue;
			}
			// WP AI 1.3 fires this hook as apply_filters( $hook, $instruction, $data )
			// — two args, no ability name. Capture the id so the callback can look
			// up the override without a TypeError on the array $data payload.
			add_filter(
				"wpai_{$slug}_system_instruction",
				fn( string $instruction, array $data ): string => $this->inject_per_ability_override( $instruction, $ability_id, $data ),
				10,
				2
			);
		}
	}

	/**
	 * Apply per-ability prompt override using the native per-ability filter.
	 *
	 * Called from the closure registered in register_per_ability_filters(); not
	 * a filter callback itself. WP AI's scoped hook is ( $instruction, $data ).
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
	 * Derive WP AI's hook-safe slug from an ability name.
	 *
	 * Mirrors the slug helper WP AI 1.3 added in Abstract_Ability (#770): drop the
	 * namespace, then collapse every non-alphanumeric run to a single underscore.
	 * `ai/title-generation` → `title_generation` → `wpai_title_generation_system_instruction`.
	 *
	 * Public and static so the contract suite can pin the derivation directly — a
	 * wrong slug means our filter subscribes to a hook nothing ever fires, which
	 * fails silently.
	 */
	public static function ability_to_slug( string $ability_id ): string {
		$name = (string) preg_replace( '#^[^/]+/#', '', trim( $ability_id ) );
		$slug = (string) preg_replace( '/[^a-z0-9]+/i', '_', $name );
		return strtolower( trim( $slug, '_' ) );
	}
}
