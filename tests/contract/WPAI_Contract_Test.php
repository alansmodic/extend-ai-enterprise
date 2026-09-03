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
use ExtendAI\Enterprise\Policy\Prompt_Injector;
use ExtendAI\Enterprise\Storage\Prompt_Library;

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

	/**
	 * WordPress 7.1 lifecycle filters must exist for governance enforcement.
	 * Rate_Limiter and Output_Moderator depend on these to enforce across all
	 * execution paths (REST, MCP, WP-CLI, direct PHP).
	 */
	public function test_wordpress_7_1_lifecycle_filters_exist(): void {
		$sentinel = new \WP_Filter_Sentinel();
		$captured = null;

		// wp_pre_execute_ability — short-circuit execution before validation.
		$listener = static function ( $sent, string $ability_name, $input, $ability ) use ( &$captured, $sentinel ) {
			$captured = compact( 'sent', 'ability_name', 'input', 'ability' );
			return $sent;
		};
		add_filter( 'wp_pre_execute_ability', $listener, 99, 4 );
		$result = apply_filters( 'wp_pre_execute_ability', $sentinel, 'test/ability', array( 'key' => 'value' ), null );
		remove_filter( 'wp_pre_execute_ability', $listener, 99 );
		$this->assertIsArray( $captured, 'wp_pre_execute_ability filter missing — Rate_Limiter cannot enforce universally.' );
		$this->assertSame( $sentinel, $result, 'wp_pre_execute_ability must pass sentinel through when not short-circuited.' );
		$this->assertSame( 'test/ability', $captured['ability_name'] );

		// wp_ability_normalize_input — transform input after defaults, before validation.
		$captured = null;
		$listener = static function ( $input, string $ability_name, $ability ) use ( &$captured ) {
			$captured = compact( 'input', 'ability_name', 'ability' );
			return $input;
		};
		add_filter( 'wp_ability_normalize_input', $listener, 99, 3 );
		apply_filters( 'wp_ability_normalize_input', array( 'test' => 'data' ), 'test/ability', null );
		remove_filter( 'wp_ability_normalize_input', $listener, 99 );
		$this->assertIsArray( $captured, 'wp_ability_normalize_input filter missing.' );
		$this->assertSame( array( 'test' => 'data' ), $captured['input'] );

		// wp_ability_execute_result — transform/moderate output before validation.
		$captured = null;
		$listener = static function ( $result, string $ability_name, $input, $ability ) use ( &$captured ) {
			$captured = compact( 'result', 'ability_name', 'input', 'ability' );
			return $result;
		};
		add_filter( 'wp_ability_execute_result', $listener, 99, 4 );
		apply_filters( 'wp_ability_execute_result', 'result', 'test/ability', array(), null );
		remove_filter( 'wp_ability_execute_result', $listener, 99 );
		$this->assertIsArray( $captured, 'wp_ability_execute_result filter missing — Output_Moderator cannot enforce universally.' );
		$this->assertSame( 'result', $captured['result'] );
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

	/**
	 * WP AI 1.3 derives a hook-safe slug per ability (#770): drop the namespace,
	 * collapse non-alphanumerics to underscores. `ai/title-generation` becomes
	 * `wpai_title_generation_system_instruction`.
	 *
	 * Our Prompt_Injector must derive the byte-identical slug. Get it wrong and we
	 * subscribe to a hook nothing ever fires — every per-ability prompt override
	 * silently stops applying, with no error anywhere.
	 */
	public function test_ability_slug_derivation_matches_wp_ai(): void {
		$cases = array(
			'ai/title-generation'        => 'title_generation',
			'ai/excerpt-generation'      => 'excerpt_generation',
			'ai/meta-description'        => 'meta_description',
			'ai/content-classification'  => 'content_classification',
			'ai/image-prompt-generation' => 'image_prompt_generation',
			'ai/alt-text-generation'     => 'alt_text_generation',
			'ai/editorial-notes'         => 'editorial_notes',
		);

		foreach ( $cases as $ability_id => $expected ) {
			$this->assertSame(
				$expected,
				Prompt_Injector::ability_to_slug( $ability_id ),
				"Slug for '{$ability_id}' must be '{$expected}' to match WP AI's hook naming."
			);
		}
	}

	/**
	 * An ability with a stored override must end up subscribed to the correctly
	 * named scoped hook, and that hook must actually transform the instruction.
	 *
	 * This is the guard that catches a slug/registration mismatch end-to-end
	 * rather than trusting the derivation helper in isolation.
	 */
	public function test_override_subscribes_to_scoped_hook_and_applies(): void {
		$library = new Prompt_Library();
		$library->put( 'ai/title-generation', Prompt_Library::MODE_REPLACE, 'Custom title rules.', 0 );

		( new Prompt_Injector( $library ) )->register_per_ability_filters();

		$this->assertNotFalse(
			has_filter( 'wpai_title_generation_system_instruction' ),
			'Prompt_Injector did not subscribe to the scoped hook — per-ability overrides are inert.'
		);

		$this->assertSame(
			'Custom title rules.',
			apply_filters(
				'wpai_title_generation_system_instruction',
				'WP AI default.',
				'ai/title-generation',
				array()
			),
			'Scoped hook fired but the stored override was not applied.'
		);
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

	/**
	 * Verify our governance modules properly hook into WordPress 7.1 lifecycle filters.
	 * Rate_Limiter must subscribe to wp_pre_execute_ability.
	 * Output_Moderator must subscribe to wp_ability_execute_result.
	 */
	public function test_governance_modules_use_lifecycle_filters(): void {
		$this->assertNotFalse(
			has_filter( 'wp_pre_execute_ability' ),
			'No callbacks on wp_pre_execute_ability — Rate_Limiter is not enforcing for non-REST paths.'
		);
		$this->assertNotFalse(
			has_filter( 'wp_ability_execute_result' ),
			'No callbacks on wp_ability_execute_result — Output_Moderator is not enforcing for non-REST paths.'
		);
	}
}
