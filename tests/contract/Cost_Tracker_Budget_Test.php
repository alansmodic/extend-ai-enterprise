<?php
/**
 * Pins the monthly-cap enforcement behavior of Cost_Tracker.
 *
 * The cap must HARD-BLOCK: once a user is over budget every `wp_ability_*`
 * capability is denied, which stops the AI request at the Abilities API
 * permission check. Regression guard for the original bug where enforcement
 * went through the model allowlist and an empty allowlist was read as "allow
 * all defaults" — reopening access instead of blocking it.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

use ExtendAI\Enterprise\Governance\Cost_Tracker;
use ExtendAI\Enterprise\Storage\Usage_Repository;

final class Cost_Tracker_Budget_Test extends WP_UnitTestCase {

	private const ABILITY_CAP = 'wp_ability_ai_title_generation';

	private Cost_Tracker $tracker;
	private Usage_Repository $repo;
	private \WP_User $user;

	public function set_up(): void {
		parent::set_up();
		$this->repo    = new Usage_Repository();
		$this->tracker = new Cost_Tracker( $this->repo );
		$this->tracker->register();
		$this->user = self::factory()->user->create_and_get( array( 'role' => 'author' ) );
	}

	public function tear_down(): void {
		remove_filter( 'user_has_cap', array( $this->tracker, 'gate_over_budget' ), 10 );
		delete_option( 'extend_ai_monthly_user_cap_usd' );
		parent::tear_down();
	}

	/** Seed this user's spend for the current period. */
	private function spend( float $usd ): void {
		$this->repo->increment( (int) $this->user->ID, gmdate( 'Y-m' ), 'anthropic', 'claude-sonnet-4-6', 1000, 1000, $usd );
	}

	/** Run the gate against a single ability cap and report whether it survives. */
	private function gate_ability(): bool {
		$out = $this->tracker->gate_over_budget(
			array( self::ABILITY_CAP => true ),
			array( self::ABILITY_CAP ),
			array( self::ABILITY_CAP, $this->user->ID ),
			$this->user
		);
		return (bool) ( $out[ self::ABILITY_CAP ] ?? false );
	}

	public function test_over_budget_denies_ability_caps(): void {
		update_option( 'extend_ai_monthly_user_cap_usd', 5.0 );
		$this->spend( 6.0 );
		$this->assertFalse( $this->gate_ability(), 'Over-budget user must be denied AI ability caps — the cap failed open.' );
	}

	public function test_under_budget_leaves_ability_caps_intact(): void {
		update_option( 'extend_ai_monthly_user_cap_usd', 5.0 );
		$this->spend( 1.0 );
		$this->assertTrue( $this->gate_ability(), 'Under-budget user must retain AI ability caps.' );
	}

	public function test_zero_cap_disables_enforcement(): void {
		update_option( 'extend_ai_monthly_user_cap_usd', 0 );
		$this->spend( 999.0 );
		$this->assertTrue( $this->gate_ability(), 'A cap of 0 disables enforcement — usage must not be gated.' );
	}

	public function test_non_ability_caps_are_never_touched(): void {
		update_option( 'extend_ai_monthly_user_cap_usd', 5.0 );
		$this->spend( 6.0 );
		$out = $this->tracker->gate_over_budget(
			array( 'edit_posts' => true ),
			array( 'edit_posts' ),
			array( 'edit_posts', $this->user->ID ),
			$this->user
		);
		$this->assertTrue( $out['edit_posts'], 'Over-budget gate must only affect wp_ability_* caps, not core capabilities.' );
	}

	public function test_administrators_are_exempt_from_cap(): void {
		update_option( 'extend_ai_monthly_user_cap_usd', 5.0 );
		$admin = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$admin->add_cap( self::ABILITY_CAP );
		$this->repo->increment( (int) $admin->ID, gmdate( 'Y-m' ), 'anthropic', 'claude-sonnet-4-6', 1000, 1000, 6.0 );

		$this->assertTrue(
			user_can( $admin->ID, self::ABILITY_CAP ),
			'Administrators must be exempt from the spend cap so they are not locked out.'
		);
	}

	/** Integration: the filter is actually wired so user_can() reflects the cap. */
	public function test_user_has_cap_filter_is_wired(): void {
		update_option( 'extend_ai_monthly_user_cap_usd', 5.0 );
		$this->user->add_cap( self::ABILITY_CAP );
		$this->spend( 6.0 );

		$this->assertFalse(
			user_can( $this->user->ID, self::ABILITY_CAP ),
			'Over-budget user still passes user_can() — the user_has_cap gate is not wired.'
		);
	}
}
