<?php
/**
 * Pins the 0.1 → 0.2 stored-state migration.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

use ExtendAI\Enterprise\Compat\Upgrader;
use ExtendAI\Enterprise\Storage\Prompt_Library;

final class Upgrader_Test extends WP_UnitTestCase {

	private Prompt_Library $library;

	public function set_up(): void {
		parent::set_up();
		$this->library = new Prompt_Library();
		delete_option( Upgrader::OPTION );
		delete_option( 'extend_ai_use_guidelines' );
		delete_option( 'extend_ai_role_map' );
		delete_transient( 'extend_ai_upgrade_notice_0_2' );
	}

	public function tear_down(): void {
		foreach ( array_keys( $this->library->all() ) as $id ) {
			$this->library->delete( $id );
		}
		delete_option( Upgrader::OPTION );
		delete_option( 'extend_ai_use_guidelines' );
		delete_option( 'extend_ai_role_map' );
		delete_transient( 'extend_ai_upgrade_notice_0_2' );
		parent::tear_down();
	}

	public function test_strips_stale_guidelines_placeholders(): void {
		$this->library->put(
			'ai/editorial-notes',
			Prompt_Library::MODE_APPEND,
			"Voice.\n{guidelines_copy}\nNo superlatives.",
			0
		);
		update_option( 'extend_ai_use_guidelines', true );

		Upgrader::maybe_run();

		$row = $this->library->get( 'ai/editorial-notes' );
		$this->assertNotNull( $row );
		$this->assertStringNotContainsString( '{guidelines', $row['template'] );
		$this->assertStringContainsString( 'No superlatives.', $row['template'] );
		$this->assertFalse( get_option( 'extend_ai_use_guidelines' ) );
		$this->assertSame( \ExtendAI\Enterprise\VERSION, get_option( Upgrader::OPTION ) );
		$this->assertSame( 1, (int) get_transient( 'extend_ai_upgrade_notice_0_2' ) );
	}

	public function test_deletes_override_that_was_only_guidelines_placeholders(): void {
		$this->library->put( 'ai/editorial-notes', Prompt_Library::MODE_REPLACE, '{guidelines}', 0 );

		Upgrader::maybe_run();

		$this->assertNull( $this->library->get( 'ai/editorial-notes' ) );
	}

	public function test_renames_comment_moderation_ability_id(): void {
		$this->library->put( 'ai/comment-moderation', Prompt_Library::MODE_PREPEND, 'Be civil.', 0 );
		update_option(
			'extend_ai_role_map',
			array(
				'ai/comment-moderation' => array( 'administrator' ),
			)
		);

		Upgrader::maybe_run();

		$this->assertNull( $this->library->get( 'ai/comment-moderation' ) );
		$moved = $this->library->get( 'ai/comment-analysis' );
		$this->assertNotNull( $moved );
		$this->assertSame( 'Be civil.', $moved['template'] );

		$map = (array) get_option( 'extend_ai_role_map' );
		$this->assertArrayNotHasKey( 'ai/comment-moderation', $map );
		$this->assertSame( array( 'administrator' ), $map['ai/comment-analysis'] );
	}

	public function test_is_idempotent_once_schema_is_current(): void {
		$this->library->put( 'ai/title-generation', Prompt_Library::MODE_APPEND, 'Keep it short.', 0 );
		Upgrader::maybe_run();
		$first = $this->library->get( 'ai/title-generation' );

		$this->library->put( 'ai/title-generation', Prompt_Library::MODE_APPEND, '{guidelines} leftover', 0 );
		Upgrader::maybe_run();

		$second = $this->library->get( 'ai/title-generation' );
		$this->assertSame( '{guidelines} leftover', $second['template'] );
		$this->assertSame( $first['mode'], $second['mode'] );
	}
}
