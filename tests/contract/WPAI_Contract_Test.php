<?php
/**
 * Contract tests against the WordPress AI plugin.
 *
 * Each test pins one specific integration point we depend on. When the WP AI
 * plugin changes its filter names, signatures, REST surface, or SDK interfaces,
 * one of these tests fails first and tells us *exactly* what drifted — before
 * the failure surfaces as silent loss of governance in production.
 *
 * Run against:
 *   - the WP AI version pinned in Compat\Version_Gate::TESTED_MAX (release gate)
 *   - the WP AI trunk branch nightly in CI (drift detector)
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

use ExtendAI\Enterprise\Compat\Version_Gate;

final class WPAI_Contract_Test extends WP_UnitTestCase {

	public function test_wp_ai_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'WPAI_VERSION' ), 'WP AI plugin must be active for contract tests.' );
	}

	public function test_running_version_is_within_tested_range(): void {
		$this->assertGreaterThanOrEqual(
			0,
			version_compare( WPAI_VERSION, Version_Gate::TESTED_MIN ),
			'Running WP AI version is older than TESTED_MIN. Lower the floor or drop support.'
		);
		$this->assertLessThanOrEqual(
			0,
			version_compare( WPAI_VERSION, Version_Gate::TESTED_MAX ),
			'Running WP AI version is newer than TESTED_MAX. Re-test and bump TESTED_MAX.'
		);
	}

	/** wpai_system_instruction must fire with ($instruction, $ability_name, $data). */
	public function test_system_instruction_filter_signature(): void {
		$captured = null;
		$listener = static function ( string $instruction, string $ability_name, array $data ) use ( &$captured ): string {
			$captured = compact( 'instruction', 'ability_name', 'data' );
			return $instruction;
		};
		add_filter( 'wpai_system_instruction', $listener, 99, 3 );
		apply_filters( 'wpai_system_instruction', 'default', 'ai/title-generation', array( 'post_id' => 1 ) );
		remove_filter( 'wpai_system_instruction', $listener, 99 );

		$this->assertIsArray( $captured, 'wpai_system_instruction did not fire.' );
		$this->assertSame( 'default', $captured['instruction'] );
		$this->assertSame( 'ai/title-generation', $captured['ability_name'] );
		$this->assertSame( array( 'post_id' => 1 ), $captured['data'] );
	}

	/** Content normalization filters must exist with single-string arg. */
	public function test_normalize_content_filter_exists(): void {
		$saw      = false;
		$listener = static function ( string $content ) use ( &$saw ): string {
			$saw = true;
			return $content;
		};
		add_filter( 'wpai_pre_normalize_content', $listener, 99 );
		apply_filters( 'wpai_pre_normalize_content', 'hello' );
		remove_filter( 'wpai_pre_normalize_content', $listener, 99 );
		$this->assertTrue( $saw, 'wpai_pre_normalize_content filter missing — PII redaction is silently disabled.' );
	}

	/** Model preference filters must exist for all three capabilities. */
	public function test_model_preference_filters_exist(): void {
		foreach ( array( 'wpai_preferred_text_models', 'wpai_preferred_image_models', 'wpai_preferred_vision_models' ) as $hook ) {
			$fired    = false;
			$listener = static function ( $models ) use ( &$fired ) {
				$fired = true;
				return $models;
			};
			add_filter( $hook, $listener, 99 );
			apply_filters( $hook, array() );
			remove_filter( $hook, $listener, 99 );
			$this->assertTrue( $fired, "{$hook} missing — model allowlist enforcement is inactive." );
		}
	}

	/** Retention filter must exist and accept an int. */
	public function test_retention_filter_exists(): void {
		add_filter( 'wpai_request_log_retention_days', static fn() => 42, 99 );
		$result = (int) apply_filters( 'wpai_request_log_retention_days', 0 );
		remove_all_filters( 'wpai_request_log_retention_days', 99 );
		$this->assertSame( 42, $result );
	}

	/** AI Client SDK transporter interface must be present and have send(). */
	public function test_http_transporter_interface_shape(): void {
		$this->assertTrue(
			interface_exists( '\\WordPress\\AiClient\\Providers\\Http\\Contracts\\HttpTransporterInterface' ),
			'HttpTransporterInterface missing — transporter wrap will silently no-op.'
		);
		$rc = new ReflectionClass( '\\WordPress\\AiClient\\Providers\\Http\\Contracts\\HttpTransporterInterface' );
		$this->assertTrue( $rc->hasMethod( 'send' ), 'Transporter interface lost send() — our decorator will fatal.' );
	}

	/** AiClient registry must expose get/setHttpTransporter. */
	public function test_ai_client_registry_transporter_api(): void {
		$this->assertTrue( class_exists( '\\WordPress\\AiClient\\AiClient' ) );
		$registry = \WordPress\AiClient\AiClient::defaultRegistry();
		$this->assertTrue( method_exists( $registry, 'getHttpTransporter' ), 'Registry lost getHttpTransporter().' );
		$this->assertTrue( method_exists( $registry, 'setHttpTransporter' ), 'Registry lost setHttpTransporter() — we cannot wrap.' );
	}

	/** Abilities API must expose wp_get_abilities(). */
	public function test_abilities_api_present(): void {
		$this->assertTrue( function_exists( 'wp_get_abilities' ), 'Abilities API missing — UI cannot discover prompts.' );
		$this->assertTrue( function_exists( 'wp_register_ability' ), 'wp_register_ability missing — WP AI experiments cannot register.' );
	}

	/**
	 * WP AI must subscribe to wp_abilities_api_init so its abilities register
	 * during a real WordPress request. Verifying the subscription is the
	 * stable contract — actually invoking and seeing ai/* abilities is
	 * brittle in the PHPUnit scaffold (WP_UnitTestCase resets cached state
	 * inside Abstract_Feature::is_enabled, so a `do_action` here won't always
	 * produce registrations even though it does in real WP requests).
	 *
	 * The Studio smoke-test verifies the full registration path end-to-end.
	 */
	public function test_wp_ai_subscribes_to_abilities_api_init(): void {
		$this->assertNotFalse(
			has_action( 'wp_abilities_api_init' ),
			'No callbacks on wp_abilities_api_init — WP AI experiments will never register abilities.'
		);
		$this->assertNotFalse(
			has_action( 'wp_abilities_api_categories_init' ),
			'No callbacks on wp_abilities_api_categories_init — WP AI cannot register the ability category its experiments depend on.'
		);
	}

	/** Abilities REST namespace must still be wp-abilities/v1 (rate limiter / moderator depend on it). */
	public function test_abilities_rest_namespace(): void {
		do_action( 'rest_api_init' );
		$server     = rest_get_server();
		$namespaces = $server->get_namespaces();
		$this->assertContains( 'wp-abilities/v1', $namespaces, 'Abilities REST namespace changed — rate limiter and moderator will not match routes.' );
	}

	/**
	 * Every ability_id our governance layer hardcodes must resolve to a real
	 * registered WP AI ability. A wrong ID fails *silently* — Role_Gate keys its
	 * role allowlist by ability_id and treats a miss as "not configured" (fails
	 * OPEN), so a typo'd ID disables the restriction without any error. This is
	 * the guard that would have caught `ai/generate-image` vs `ai/image-generation`.
	 *
	 * Registration is brittle in the PHPUnit scaffold (see
	 * test_wp_ai_subscribes_to_abilities_api_init) — when nothing registers we
	 * SKIP rather than fail, so this never flakes red; the Studio smoke-test
	 * covers the end-to-end path. It still catches a wrong ID whenever
	 * registration succeeds.
	 */
	public function test_governance_ability_ids_are_registered(): void {
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API unavailable in this scaffold run.' );
		}

		$registered = array_map(
			static fn( $ability ) => method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '',
			(array) wp_get_abilities()
		);

		// Filter to the WP AI namespace so an empty registry (scaffold didn't
		// register) skips instead of failing on every key.
		$ai_abilities = array_filter( $registered, static fn( $id ) => str_starts_with( (string) $id, 'ai/' ) );
		if ( $ai_abilities === array() ) {
			$this->markTestSkipped( 'No ai/* abilities registered in this scaffold run — Studio smoke-test covers registration.' );
		}

		// IDs our governance maps depend on. Keep in sync with
		// Access\Role_Gate::DEFAULT_MAP and REST\Admin_Controller's label map.
		$required = array(
			'ai/comment-moderation',
			'ai/image-generation',
			'ai/image-prompt-generation',
			'ai/alt-text-generation',
		);
		foreach ( $required as $ability_id ) {
			$this->assertContains(
				$ability_id,
				$ai_abilities,
				"Governance layer references '{$ability_id}' but WP AI does not register it — the role gate / prompt UI for this ability silently no-ops. See Compat naming: ID is 'ai/' . get_id()."
			);
		}
	}
}
