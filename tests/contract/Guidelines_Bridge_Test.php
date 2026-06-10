<?php
/**
 * Pins the storage contract of the Gutenberg "Guidelines" experiment and
 * verifies the Guidelines → review-notes prompt integration end to end.
 *
 * Gutenberg is not loaded in this scaffold, so these tests register the
 * guidelines CPT/taxonomy/meta exactly the way Gutenberg does (see
 * lib/experimental/guidelines/ in the Gutenberg repo). If upstream renames the
 * post type, taxonomy, term, or meta keys again, the fixtures here are the
 * single place to update — and Guidelines_Bridge's constants alongside them.
 *
 * The absent-CPT tests double as the "not all sites have the plugin enabled"
 * guarantee: with no Guidelines feature registered, prompts pass through
 * untouched.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

use ExtendAI\Enterprise\Policy\Guidelines_Bridge;
use ExtendAI\Enterprise\Storage\Prompt_Library;

final class Guidelines_Bridge_Test extends WP_UnitTestCase {

	public function tear_down(): void {
		foreach ( Guidelines_Bridge::POST_TYPES as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				unregister_post_type( $post_type );
			}
		}
		if ( taxonomy_exists( Guidelines_Bridge::TAXONOMY ) ) {
			unregister_taxonomy( Guidelines_Bridge::TAXONOMY );
		}
		delete_option( 'extend_ai_use_guidelines' );
		( new Prompt_Library() )->delete( 'ai/editorial-notes' );
		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// Fixtures that mirror Gutenberg's registration.
	// -----------------------------------------------------------------

	private function register_guidelines_feature( string $post_type = 'wp_guideline', bool $with_taxonomy = true ): void {
		register_post_type(
			$post_type,
			array(
				'public'   => false,
				'supports' => array( 'title', 'editor', 'revisions' ),
			)
		);
		if ( $with_taxonomy ) {
			register_taxonomy(
				Guidelines_Bridge::TAXONOMY,
				$post_type,
				array(
					'public'       => false,
					'hierarchical' => true,
				)
			);
		}
	}

	/** @param array<string,string> $meta */
	private function create_guidelines_post( array $meta, string $post_type = 'wp_guideline' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => 'Guidelines',
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		if ( taxonomy_exists( Guidelines_Bridge::TAXONOMY ) ) {
			$term = term_exists( Guidelines_Bridge::TERM_CONTENT, Guidelines_Bridge::TAXONOMY );
			if ( ! $term ) {
				$term = wp_insert_term( Guidelines_Bridge::TERM_CONTENT, Guidelines_Bridge::TAXONOMY );
			}
			wp_set_object_terms( $post_id, (int) $term['term_id'], Guidelines_Bridge::TAXONOMY );
		}
		return $post_id;
	}

	private function injected( string $ability = 'ai/editorial-notes', array $data = array() ): string {
		return (string) apply_filters( 'wpai_system_instruction', 'default instruction', $ability, $data );
	}

	// -----------------------------------------------------------------
	// Absent feature → silent no-op.
	// -----------------------------------------------------------------

	public function test_prompt_unchanged_when_guidelines_feature_absent(): void {
		foreach ( Guidelines_Bridge::POST_TYPES as $post_type ) {
			$this->assertFalse( post_type_exists( $post_type ), "{$post_type} unexpectedly registered in scaffold." );
		}
		$this->assertSame( 'default instruction', $this->injected() );
		$this->assertFalse( ( new Guidelines_Bridge() )->is_available() );
	}

	// -----------------------------------------------------------------
	// Feature present → guidelines reach the editorial prompt.
	// -----------------------------------------------------------------

	public function test_editorial_notes_prompt_includes_guidelines(): void {
		$this->register_guidelines_feature();
		$this->create_guidelines_post(
			array(
				'_guideline_site' => 'We are a bakery in Lisbon.',
				'_guideline_copy' => 'Tone: warm and direct. Avoid jargon.',
			)
		);

		$result = $this->injected();

		$this->assertStringStartsWith( 'default instruction', $result );
		$this->assertStringContainsString( '## Site guidelines', $result );
		$this->assertStringContainsString( 'We are a bakery in Lisbon.', $result );
		$this->assertStringContainsString( 'Tone: warm and direct. Avoid jargon.', $result );
	}

	public function test_non_editorial_abilities_are_not_auto_appended(): void {
		$this->register_guidelines_feature();
		$this->create_guidelines_post( array( '_guideline_copy' => 'Tone: warm.' ) );

		$this->assertSame( 'default instruction', $this->injected( 'ai/title-generation' ) );
	}

	public function test_legacy_content_guideline_post_type_is_detected(): void {
		// Gutenberg 22.7 shipped the CPT as `wp_content_guideline`, without the type taxonomy.
		$this->register_guidelines_feature( 'wp_content_guideline', false );
		$this->create_guidelines_post( array( '_guideline_copy' => 'Oxford commas always.' ), 'wp_content_guideline' );

		$this->assertStringContainsString( 'Oxford commas always.', $this->injected() );
	}

	public function test_toggle_off_disables_injection(): void {
		$this->register_guidelines_feature();
		$this->create_guidelines_post( array( '_guideline_copy' => 'Tone: warm.' ) );
		update_option( 'extend_ai_use_guidelines', '' );

		$this->assertSame( 'default instruction', $this->injected() );
	}

	// -----------------------------------------------------------------
	// Block rules are scoped to the post under review.
	// -----------------------------------------------------------------

	public function test_block_rules_scoped_to_blocks_present_in_reviewed_post(): void {
		$this->register_guidelines_feature();
		$this->create_guidelines_post(
			array(
				'_guideline_block_core_paragraph' => 'Short sentences.',
				'_guideline_block_core_image'     => 'Always include alt text.',
			)
		);

		$reviewed = self::factory()->post->create(
			array( 'post_content' => "<!-- wp:paragraph -->\n<p>Hello.</p>\n<!-- /wp:paragraph -->" )
		);

		$result = $this->injected( 'ai/editorial-notes', array( 'post_id' => $reviewed ) );
		$this->assertStringContainsString( 'core/paragraph: Short sentences.', $result );
		$this->assertStringNotContainsString( 'core/image', $result );

		// Without a reviewable post, all block rules are included.
		$result = $this->injected();
		$this->assertStringContainsString( 'core/paragraph: Short sentences.', $result );
		$this->assertStringContainsString( 'core/image: Always include alt text.', $result );
	}

	// -----------------------------------------------------------------
	// Template variables and audit action.
	// -----------------------------------------------------------------

	public function test_guidelines_variable_in_override_template_suppresses_auto_append(): void {
		$this->register_guidelines_feature();
		$this->create_guidelines_post( array( '_guideline_copy' => 'Tone: warm.' ) );

		( new Prompt_Library() )->put(
			'ai/editorial-notes',
			Prompt_Library::MODE_PREPEND,
			'House copy rules: {guidelines_copy}',
			0
		);

		$result = $this->injected();

		$this->assertStringContainsString( 'House copy rules: Tone: warm.', $result );
		$this->assertStringNotContainsString( '{guidelines_copy}', $result );
		$this->assertStringNotContainsString( '## Site guidelines', $result, 'Auto-append must be suppressed when the template consumed a {guidelines*} variable.' );
		$this->assertSame( 1, substr_count( $result, 'Tone: warm.' ), 'Guidelines text must appear exactly once.' );
	}

	public function test_guidelines_variables_resolve_to_empty_string_when_feature_absent(): void {
		( new Prompt_Library() )->put(
			'ai/editorial-notes',
			Prompt_Library::MODE_PREPEND,
			'House copy rules: {guidelines_copy} {guidelines}',
			0
		);

		$result = $this->injected();

		$this->assertStringNotContainsString( '{guidelines', $result, 'Placeholders must never leak literally into prompts.' );
	}

	public function test_guidelines_applied_action_reports_post_and_revision(): void {
		$this->register_guidelines_feature();
		$guideline_id = $this->create_guidelines_post( array( '_guideline_copy' => 'Tone: warm.' ) );

		$captured = null;
		add_action(
			'extend_ai_guidelines_applied',
			static function ( string $ability, int $post_id, int $revision_id ) use ( &$captured ): void {
				$captured = compact( 'ability', 'post_id', 'revision_id' );
			},
			10,
			3
		);

		$this->injected();

		$this->assertIsArray( $captured, 'extend_ai_guidelines_applied did not fire.' );
		$this->assertSame( 'ai/editorial-notes', $captured['ability'] );
		$this->assertSame( $guideline_id, $captured['post_id'] );
		$this->assertIsInt( $captured['revision_id'] );
	}
}
