<?php
/**
 * Per-ability prompt customization. Applies overrides stored in Prompt_Library
 * to the WP AI plugin's `wpai_system_instruction` filter.
 *
 * Resolution order, applied in sequence on top of the WP AI default:
 *   1. Global policy preamble (option `extend_ai_policy_preamble`) — prepended.
 *   2. Per-ability override (table `wp_extend_ai_prompts`) — prepend/append/replace.
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

	public function __construct( private ?Prompt_Library $library = null ) {
		$this->library ??= new Prompt_Library();
	}

	public function register(): void {
		add_filter( 'wpai_system_instruction', [ $this, 'inject' ], 10, 3 );
	}

	/**
	 * @param string              $instruction  Default system instruction from the ability.
	 * @param string              $ability_name e.g. "ai/title-generation".
	 * @param array<string,mixed> $data         Per-call data the ability is about to use.
	 */
	public function inject( string $instruction, string $ability_name, array $data ): string {
		$result = $this->apply_override( $instruction, $ability_name, $data );
		$result = $this->apply_global_preamble( $result, $ability_name );
		return $result;
	}

	private function apply_override( string $instruction, string $ability_name, array $data ): string {
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

	private function apply_global_preamble( string $instruction, string $ability_name ): string {
		$default  = (string) get_option( 'extend_ai_policy_preamble', '' );
		$preamble = (string) apply_filters( 'extend_ai_policy_preamble', $default, $ability_name );
		return $preamble === '' ? $instruction : $preamble . "\n\n" . $instruction;
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
		$vars = [
			'ability'      => $ability_name,
			'user_login'   => $user instanceof \WP_User ? $user->user_login : '',
			'user_role'    => $user instanceof \WP_User ? ( $user->roles[0] ?? '' ) : '',
			'site_name'    => (string) get_bloginfo( 'name' ),
			'site_url'     => (string) home_url(),
			'current_date' => gmdate( 'Y-m-d' ),
		];

		// If the ability passed a post_id, expose common post fields.
		$post_id = (int) ( $data['post_id'] ?? 0 );
		if ( $post_id > 0 && ( $post = get_post( $post_id ) ) ) {
			$vars['post_title'] = (string) $post->post_title;
			$vars['post_type']  = (string) $post->post_type;
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
}
