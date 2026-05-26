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
		apply_filters( 'wpai_system_instruction', 'default', 'ai/title-generation', [ 'post_id' => 1 ] );
		remove_filter( 'wpai_system_instruction', $listener, 99 );

		$this->assertIsArray( $captured, 'wpai_system_instruction did not fire.' );
		$this->assertSame( 'default', $captured['instruction'] );
		$this->assertSame( 'ai/title-generation', $captured['ability_name'] );
		$this->assertSame( [ 'post_id' => 1 ], $captured['data'] );
	}

	/** Content normalization filters must exist with single-string arg. */
	public function test_normalize_content_filter_exists(): void {
		$saw = false;
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
		foreach ( [ 'wpai_preferred_text_models', 'wpai_preferred_image_models', 'wpai_preferred_vision_models' ] as $hook ) {
			$fired = false;
			$listener = static function ( $models ) use ( &$fired ) {
				$fired = true;
				return $models;
			};
			add_filter( $hook, $listener, 99 );
			apply_filters( $hook, [] );
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
	 * WP AI must subscribe to wp_abilities_api_init so its abilities register.
	 * We fire the action ourselves and look for ai/* registrations afterwards.
	 * This is more robust than relying on the test scaffold's request lifecycle
	 * to have fired it for us.
	 */
	public function test_wp_ai_registers_abilities(): void {
		$this->assertNotFalse(
			has_action( 'wp_abilities_api_init' ),
			'No callbacks on wp_abilities_api_init — WP AI experiments will never register abilities.'
		);

		// Categories must register before abilities that reference them.
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );

		$ai_namespace_count = 0;
		foreach ( (array) wp_get_abilities() as $ability ) {
			$name = method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
			if ( str_starts_with( $name, 'ai/' ) ) {
				$ai_namespace_count++;
			}
		}
		$this->assertGreaterThan(
			0,
			$ai_namespace_count,
			'wp_abilities_api_init fired but no ai/* abilities registered — namespace contract broken.'
		);
	}

	/** Abilities REST namespace must still be wp-abilities/v1 (rate limiter / moderator depend on it). */
	public function test_abilities_rest_namespace(): void {
		do_action( 'rest_api_init' );
		$server     = rest_get_server();
		$namespaces = $server->get_namespaces();
		$this->assertContains( 'wp-abilities/v1', $namespaces, 'Abilities REST namespace changed — rate limiter and moderator will not match routes.' );
	}
}
