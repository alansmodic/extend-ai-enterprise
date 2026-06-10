<?php
/**
 * Top-level plugin bootstrap. Wires every module's hooks once WP AI is loaded.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise;

use ExtendAI\Enterprise\Access\Credential_Vault;
use ExtendAI\Enterprise\Access\Role_Gate;
use ExtendAI\Enterprise\Admin\Settings_Page;
use ExtendAI\Enterprise\Compat\Version_Gate;
use ExtendAI\Enterprise\Governance\Cost_Tracker;
use ExtendAI\Enterprise\Governance\Output_Moderator;
use ExtendAI\Enterprise\Governance\Rate_Limiter;
use ExtendAI\Enterprise\Governance\Retention;
use ExtendAI\Enterprise\Logging\Transporter_Wrap;
use ExtendAI\Enterprise\Policy\Guidelines_Bridge;
use ExtendAI\Enterprise\Policy\Model_Allowlist;
use ExtendAI\Enterprise\Policy\PII_Redactor;
use ExtendAI\Enterprise\Policy\Prompt_Injector;
use ExtendAI\Enterprise\REST\Admin_Controller;

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		// Compatibility — warn when running outside the tested WP AI version range.
		( new Version_Gate() )->register();

		// Transport-level event source — emits extend_ai_request_completed.
		( new Transporter_Wrap() )->register();

		// Policy layer — shapes inputs before they reach the model.
		$guidelines = new Guidelines_Bridge();
		$guidelines->register();
		( new Prompt_Injector( null, $guidelines ) )->register();
		( new Model_Allowlist() )->register();
		( new PII_Redactor() )->register();

		// Access layer — who can use what.
		( new Role_Gate() )->register();
		( new Credential_Vault() )->register();

		// Governance layer — limits, costs, audit, moderation.
		( new Rate_Limiter() )->register();
		( new Cost_Tracker() )->register();
		( new Output_Moderator() )->register();
		( new Retention() )->register();

		// Admin surface.
		( new Admin_Controller() )->register();
		( new Settings_Page() )->register();
	}

	private function __construct() {}
}
