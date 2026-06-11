<?php
/**
 * Per-role enable/disable of WP AI experiments and abilities.
 *
 * Hooks:
 *  - `wpai_feature_{$feature_id}_enabled` (filter) — one per feature.
 *  - `user_has_cap` (filter) — gate Abilities API invocation by capability check.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Access;

final class Role_Gate {

	/** @var array<string,string[]> ability_id => allowed role slugs */
	private const DEFAULT_MAP = array(
		'ai/comment-moderation' => array( 'administrator', 'editor' ),
		'ai/image-generation'   => array( 'administrator', 'editor' ),
		// All other abilities default to "any user with publish_posts" (WP AI plugin default).
	);

	public function register(): void {
		add_action( 'init', array( $this, 'attach_feature_filters' ), 5 );
		add_filter( 'user_has_cap', array( $this, 'gate_ability_caps' ), 10, 4 );
	}

	public function attach_feature_filters(): void {
		$disabled = (array) get_option( 'extend_ai_disabled_features', array() );
		foreach ( $disabled as $feature_id ) {
			$hook = 'wpai_feature_' . sanitize_key( (string) $feature_id ) . '_enabled';
			add_filter( $hook, '__return_false', 100 );
		}
	}

	/**
	 * Block ability invocation for users whose role isn't in the allowlist.
	 *
	 * @param array<string,bool> $allcaps
	 * @param array<int,string>  $caps
	 * @param array<int,mixed>   $args  [ $cap, $user_id, ...context ]
	 * @param \WP_User           $user
	 * @return array<string,bool>
	 */
	public function gate_ability_caps( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		$cap = (string) ( $args[0] ?? '' );
		if ( ! str_starts_with( $cap, 'wp_ability_' ) ) {
			return $allcaps;
		}

		$ability_id = $this->cap_to_ability_id( $cap );
		$map        = $this->role_map();
		$allowed    = $map[ $ability_id ] ?? null;

		if ( $allowed === null ) {
			return $allcaps; // Not configured — defer to default permission_callback.
		}

		$has_role = (bool) array_intersect( $allowed, (array) $user->roles );
		foreach ( $caps as $required ) {
			$allcaps[ $required ] = $has_role;
		}
		return $allcaps;
	}

	private function cap_to_ability_id( string $cap ): string {
		// `wp_ability_ai_title_generation` → `ai/title-generation`.
		$trim = substr( $cap, strlen( 'wp_ability_' ) );
		$trim = str_replace( '_', '-', $trim );
		return preg_replace( '/^ai-/', 'ai/', $trim, 1 ) ?? $trim;
	}

	/** @return array<string,string[]> */
	private function role_map(): array {
		$opt = (array) get_option( 'extend_ai_role_map', self::DEFAULT_MAP );
		/** @var array<string,string[]> */
		return (array) apply_filters( 'extend_ai_role_map', $opt );
	}
}
