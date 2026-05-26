<?php
/**
 * Restricts the AI plugin to an enterprise-approved set of provider/model pairs.
 *
 * Hooks: `wpai_preferred_text_models`, `wpai_preferred_image_models`, `wpai_preferred_vision_models`.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Policy;

final class Model_Allowlist {

	public function register(): void {
		add_filter( 'wpai_preferred_text_models', array( $this, 'filter_text' ), 100 );
		add_filter( 'wpai_preferred_image_models', array( $this, 'filter_image' ), 100 );
		add_filter( 'wpai_preferred_vision_models', array( $this, 'filter_vision' ), 100 );
	}

	/** @param array<int, array{0:string,1:string}> $defaults */
	public function filter_text( array $defaults ): array {
		return $this->intersect( $defaults, $this->allowlist( 'text' ) );
	}

	/** @param array<int, array{0:string,1:string}> $defaults */
	public function filter_image( array $defaults ): array {
		return $this->intersect( $defaults, $this->allowlist( 'image' ) );
	}

	/** @param array<int, array{0:string,1:string}> $defaults */
	public function filter_vision( array $defaults ): array {
		return $this->intersect( $defaults, $this->allowlist( 'vision' ) );
	}

	/**
	 * @param array<int, array{0:string,1:string}> $defaults
	 * @param array<int, array{0:string,1:string}> $allow
	 * @return array<int, array{0:string,1:string}>
	 */
	private function intersect( array $defaults, array $allow ): array {
		if ( $allow === array() ) {
			return $defaults; // Allow-everything when unconfigured.
		}
		$allow_keys = array_map( static fn( $p ) => $p[0] . '|' . $p[1], $allow );
		return array_values(
			array_filter(
				$defaults,
				static fn( $p ) => in_array( $p[0] . '|' . $p[1], $allow_keys, true )
			)
		);
	}

	/** @return array<int, array{0:string,1:string}> */
	private function allowlist( string $capability ): array {
		$opt  = get_option( 'extend_ai_model_allowlist', array() );
		$list = is_array( $opt[ $capability ] ?? null ) ? $opt[ $capability ] : array();

		/**
		 * Filter the model allowlist for a capability.
		 *
		 * @param array<int, array{0:string,1:string}> $list       e.g. [['anthropic','claude-sonnet-4-6']].
		 * @param string                                $capability text|image|vision.
		 */
		return (array) apply_filters( 'extend_ai_model_allowlist', $list, $capability );
	}
}
