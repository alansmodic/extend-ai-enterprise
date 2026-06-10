<?php
/**
 * Pins the AI Client SDK transporter lifecycle the wrap depends on.
 *
 * The SDK creates its default HTTP transporter lazily — only when a provider
 * registers (ProviderRegistry::registerProvider). On a site with no connector
 * configured, the default registry holds no transporter at `wp_loaded`, and
 * getHttpTransporter() throws. Transporter_Wrap recovers by creating the same
 * default via HttpTransporterFactory and decorating that. These tests pin:
 *
 *   1. the lazy-init contract (fresh registry throws),
 *   2. the factory fallback (createTransporter() yields a transporter),
 *   3. the wrap succeeding on both an empty and a populated registry.
 *
 * If the SDK starts eagerly initializing the transporter, (1) fails and the
 * fallback becomes dead code we can remove. If the factory moves or its
 * discovery breaks, (2) fails before production notices do.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

use ExtendAI\Enterprise\Logging\Transporter_Wrap;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\HttpTransporterFactory;
use WordPress\AiClient\Providers\ProviderRegistry;

final class Transporter_Wrap_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		self::reset_default_registry();
		delete_transient( Transporter_Wrap::FAILURE_TRANSIENT );
	}

	public function tear_down(): void {
		self::reset_default_registry();
		delete_transient( Transporter_Wrap::FAILURE_TRANSIENT );
		parent::tear_down();
	}

	/** AiClient::defaultRegistry() memoizes a private static — reset it so each test sees a fresh registry. */
	private static function reset_default_registry(): void {
		$prop = new ReflectionProperty( AiClient::class, 'defaultRegistry' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	public function test_fresh_registry_has_no_transporter(): void {
		$this->expectException( \Throwable::class );
		( new ProviderRegistry() )->getHttpTransporter();
	}

	public function test_factory_creates_default_transporter(): void {
		$this->assertTrue(
			class_exists( HttpTransporterFactory::class ),
			'HttpTransporterFactory missing — the wrap fallback for unconfigured sites is dead.'
		);
		$this->assertInstanceOf(
			HttpTransporterInterface::class,
			HttpTransporterFactory::createTransporter(),
			'createTransporter() no longer yields a transporter — discovery broke or the factory contract drifted.'
		);
	}

	/** The Playground / fresh-site case: no connector configured, registry empty at wp_loaded. */
	public function test_wrap_succeeds_on_empty_registry(): void {
		( new Transporter_Wrap() )->wrap();

		$this->assertInstanceOf(
			HttpTransporterInterface::class,
			AiClient::defaultRegistry()->getHttpTransporter(),
			'Wrap left the registry without a transporter.'
		);
		$this->assertFalse(
			get_transient( Transporter_Wrap::FAILURE_TRANSIENT ),
			'Wrap recorded a failure on an empty registry — the lazy-init fallback regressed.'
		);
	}

	/** The configured-site case: a transporter already exists and must be decorated, not replaced blindly. */
	public function test_wrap_decorates_existing_transporter(): void {
		$registry = AiClient::defaultRegistry();
		$original = HttpTransporterFactory::createTransporter();
		$registry->setHttpTransporter( $original );

		( new Transporter_Wrap() )->wrap();

		$current = $registry->getHttpTransporter();
		$this->assertInstanceOf( HttpTransporterInterface::class, $current );
		$this->assertNotSame( $original, $current, 'Wrap did not decorate the existing transporter.' );
		$this->assertFalse( get_transient( Transporter_Wrap::FAILURE_TRANSIENT ) );
	}
}
