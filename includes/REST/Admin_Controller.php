<?php
/**
 * REST endpoints for enterprise admins: usage rollups, policy CRUD, allowlist edit.
 *
 * Namespace: `extend-ai/v1`.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\REST;

final class Admin_Controller {

	private const NS = 'extend-ai/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/usage',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'usage' ),
				'permission_callback' => array( $this, 'is_admin' ),
			)
		);

		register_rest_route(
			self::NS,
			'/prompts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_prompts' ),
				'permission_callback' => array( $this, 'is_admin' ),
			)
		);

		register_rest_route(
			self::NS,
			'/prompts/(?P<ability_id>[a-zA-Z0-9_\-/]+)/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'prompt_history' ),
				'permission_callback' => array( $this, 'is_admin' ),
			)
		);

		register_rest_route(
			self::NS,
			'/prompts/(?P<ability_id>[a-zA-Z0-9_\-/]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_prompt' ),
					'permission_callback' => array( $this, 'is_admin' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'put_prompt' ),
					'permission_callback' => array( $this, 'is_admin' ),
					'args'                => array(
						'mode'     => array(
							'type' => 'string',
							'enum' => \ExtendAI\Enterprise\Storage\Prompt_Library::MODES,
						),
						'template' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_prompt' ),
					'permission_callback' => array( $this, 'is_admin' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/policies',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_policies' ),
					'permission_callback' => array( $this, 'is_admin' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'put_policies' ),
					'permission_callback' => array( $this, 'is_admin' ),
					'args'                => array(
						'preamble'           => array( 'type' => 'string' ),
						'rate_limits'        => array( 'type' => 'object' ),
						'monthly_user_cap'   => array( 'type' => 'number' ),
						'log_retention_days' => array( 'type' => 'integer' ),
						'use_guidelines'     => array( 'type' => 'boolean' ),
					),
				),
			)
		);
	}

	public function is_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	public function usage( \WP_REST_Request $req ): \WP_REST_Response {
		$month = (string) $req->get_param( 'month' );
		if ( '' === $month ) {
			$month = gmdate( 'Y-m' );
		}
		$repo = new \ExtendAI\Enterprise\Storage\Usage_Repository();
		return new \WP_REST_Response(
			array(
				'month' => $month,
				'users' => $repo->by_user_for_period( $month ),
			)
		);
	}

	public function list_prompts(): \WP_REST_Response {
		$library   = new \ExtendAI\Enterprise\Storage\Prompt_Library();
		$overrides = $library->all();
		$abilities = $this->discover_abilities();

		$out = array();
		foreach ( $abilities as $id => $meta ) {
			$out[] = array(
				'ability_id'        => $id,
				'label'             => $meta['label'],
				'description'       => $meta['description'],
				'default'           => $meta['default_instruction'],
				'default_available' => $meta['default_available'],
				'override'          => $overrides[ $id ] ?? null,
			);
		}
		return new \WP_REST_Response( $out );
	}

	public function get_prompt( \WP_REST_Request $req ): \WP_REST_Response {
		$id        = (string) $req->get_param( 'ability_id' );
		$library   = new \ExtendAI\Enterprise\Storage\Prompt_Library();
		$abilities = $this->discover_abilities();

		if ( ! isset( $abilities[ $id ] ) ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'unknown_ability',
					'message' => 'No such ability.',
				),
				404
			);
		}
		return new \WP_REST_Response(
			array(
				'ability_id'        => $id,
				'label'             => $abilities[ $id ]['label'],
				'default'           => $abilities[ $id ]['default_instruction'],
				'default_available' => $abilities[ $id ]['default_available'],
				'override'          => $library->get( $id ),
			)
		);
	}

	public function prompt_history( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (string) $req->get_param( 'ability_id' );
		$rows = ( new \ExtendAI\Enterprise\Storage\Prompt_Library() )->history( $id );
		return new \WP_REST_Response(
			array(
				'ability_id' => $id,
				'history'    => $rows,
			)
		);
	}

	public function put_prompt( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (string) $req->get_param( 'ability_id' );
		$mode = (string) $req->get_param( 'mode' );
		if ( '' === $mode ) {
			$mode = \ExtendAI\Enterprise\Storage\Prompt_Library::MODE_PREPEND;
		}
		$template = (string) $req->get_param( 'template' );

		( new \ExtendAI\Enterprise\Storage\Prompt_Library() )->put( $id, $mode, $template, get_current_user_id() );
		return $this->get_prompt( $req );
	}

	public function delete_prompt( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (string) $req->get_param( 'ability_id' );
		( new \ExtendAI\Enterprise\Storage\Prompt_Library() )->delete( $id );
		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Discover all registered abilities (built-in + custom) and capture their
	 * default system instructions. Uses the Abilities API if present; falls
	 * back to a hardcoded list of WP AI's built-ins.
	 *
	 * `default_available` is false when the WP AI ability requires runtime context
	 * (post id, content, etc.) to compute its system instruction — in which case
	 * the UI must say "default computed per call, not previewable here."
	 *
	 * @return array<string, array{label:string, description:string, default_instruction:string, default_available:bool}>
	 */
	private function discover_abilities(): array {
		$out = array();

		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( (array) wp_get_abilities() as $ability ) {
				$id = method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
				if ( $id === '' || ! str_starts_with( $id, 'ai/' ) ) {
					continue;
				}
				$label = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $id;
				$descr = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';

				$default   = '';
				$available = false;
				if ( method_exists( $ability, 'get_system_instruction' ) ) {
					try {
						$default   = (string) $ability->get_system_instruction( array() );
						$available = true;
					} catch ( \Throwable $e ) {
						$available = false;
					}
				}

				$out[ $id ] = array(
					'label'               => $label,
					'description'         => $descr,
					'default_instruction' => $default,
					'default_available'   => $available,
				);
			}
		}

		if ( $out === array() ) {
			foreach ( array(
				'ai/title-generation'       => 'Title generation',
				'ai/excerpt-generation'     => 'Excerpt generation',
				'ai/meta-description'       => 'Meta description',
				'ai/summarization'          => 'Summarization',
				'ai/content-classification' => 'Content classification',
				'ai/content-resizing'       => 'Content resizing',
				'ai/comment-moderation'     => 'Comment moderation',
				'ai/alt-text'               => 'Image alt text',
				'ai/generate-image'         => 'Image generation',
				'ai/generate-image-prompt'  => 'Image prompt generation',
				'ai/editorial-notes'        => 'Editorial notes',
				'ai/editorial-updates'      => 'Editorial updates',
			) as $id => $label ) {
				$out[ $id ] = array(
					'label'               => $label,
					'description'         => '',
					'default_instruction' => '',
					'default_available'   => false,
				);
			}
		}

		return $out;
	}

	public function get_policies(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'preamble'            => (string) get_option( 'extend_ai_policy_preamble', '' ),
				'rate_limits'         => (array) get_option(
					'extend_ai_rate_limits',
					array(
						'minute' => 20,
						'day'    => 500,
					)
				),
				'monthly_user_cap'    => (float) get_option( 'extend_ai_monthly_user_cap_usd', 0 ),
				'log_retention_days'  => (int) get_option( 'extend_ai_log_retention_days', 90 ),
				'model_allowlist'     => (array) get_option( 'extend_ai_model_allowlist', array() ),
				'disabled_features'   => (array) get_option( 'extend_ai_disabled_features', array() ),
				'banned_phrases'      => (array) get_option( 'extend_ai_banned_phrases', array() ),
				'redact_pii'          => (bool) get_option( 'extend_ai_redact_pii', true ),
				'use_guidelines'      => (bool) get_option( 'extend_ai_use_guidelines', true ),
				// Read-only: whether the Gutenberg Guidelines experiment is active here.
				'guidelines_detected' => ( new \ExtendAI\Enterprise\Policy\Guidelines_Bridge() )->is_available(),
			)
		);
	}

	public function put_policies( \WP_REST_Request $req ): \WP_REST_Response {
		foreach ( array(
			'preamble'           => 'extend_ai_policy_preamble',
			'rate_limits'        => 'extend_ai_rate_limits',
			'monthly_user_cap'   => 'extend_ai_monthly_user_cap_usd',
			'log_retention_days' => 'extend_ai_log_retention_days',
			'model_allowlist'    => 'extend_ai_model_allowlist',
			'disabled_features'  => 'extend_ai_disabled_features',
			'banned_phrases'     => 'extend_ai_banned_phrases',
			'redact_pii'         => 'extend_ai_redact_pii',
			'use_guidelines'     => 'extend_ai_use_guidelines',
		) as $param => $option ) {
			$val = $req->get_param( $param );
			if ( $val !== null ) {
				update_option( $option, $val );
			}
		}
		return $this->get_policies();
	}
}
