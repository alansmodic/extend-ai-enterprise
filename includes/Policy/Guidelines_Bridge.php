<?php
/**
 * Bridge to the Gutenberg "Guidelines" experiment (Gutenberg 22.7+).
 *
 * Guidelines gives site owners a single place to define content standards
 * (site context, copy/voice, images, per-block rules). This bridge detects the
 * feature at runtime, reads the published content-guidelines singleton, and
 * composes a prompt section so editorial-review abilities evaluate content
 * against the site's actual standards.
 *
 * Sites without the Gutenberg plugin — or with the experiment switched off —
 * never register the CPT, so every entry point here degrades to a silent no-op.
 *
 * Storage contract we depend on (pinned by tests/contract/Guidelines_Bridge_Test.php):
 *   - CPT `wp_guideline` (Gutenberg trunk) or `wp_content_guideline` (22.7 release),
 *   - taxonomy `wp_guideline_type` with the `content` term marking the singleton,
 *   - post meta `_guideline_{site|copy|images|additional}` per category,
 *   - post meta `_guideline_block_{namespace}_{block}` for per-block rules.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

namespace ExtendAI\Enterprise\Policy;

final class Guidelines_Bridge {

	/** CPT names, newest naming first. Gutenberg renamed the post type after 22.7. */
	public const POST_TYPES = array( 'wp_guideline', 'wp_content_guideline' );

	public const TAXONOMY     = 'wp_guideline_type';
	public const TERM_CONTENT = 'content';

	/** Category meta key suffixes (full key: `_guideline_{category}`). */
	public const CATEGORY_META_KEYS = array( 'site', 'copy', 'images', 'additional' );

	public const BLOCK_META_PREFIX = '_guideline_block_';

	private const CATEGORY_LABELS = array(
		'site'       => 'Site context',
		'copy'       => 'Copy & editorial style',
		'images'     => 'Image guidelines',
		'additional' => 'Additional guidelines',
	);

	public function register(): void {
		add_filter( 'extend_ai_prompt_variables', array( $this, 'add_variables' ), 10, 3 );
	}

	/** Whether the admin toggle (and any code-level override) allows guidelines injection. */
	public function is_enabled(): bool {
		$enabled = (bool) get_option( 'extend_ai_use_guidelines', true );

		/**
		 * Filter whether site Guidelines may be injected into AI prompts at all.
		 *
		 * @param bool $enabled
		 */
		return (bool) apply_filters( 'extend_ai_guidelines_enabled', $enabled );
	}

	/** Whether the Gutenberg Guidelines experiment is active on this site. */
	public function is_available(): bool {
		return null !== $this->detect_post_type();
	}

	/** The registered guidelines CPT name, or null when the experiment is off. */
	public function detect_post_type(): ?string {
		foreach ( self::POST_TYPES as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				return $post_type;
			}
		}
		return null;
	}

	/**
	 * The content-guidelines singleton post, or null.
	 *
	 * Mirrors Gutenberg's own lookup: newest post of the guidelines CPT, tagged
	 * with the `content` term when the type taxonomy exists. Upstream includes
	 * drafts by default (the Guidelines settings page saves drafts), so we do
	 * too — restrict via the `extend_ai_guidelines_statuses` filter if needed.
	 */
	public function guidelines_post(): ?\WP_Post {
		$post_type = $this->detect_post_type();
		if ( null === $post_type ) {
			return null;
		}

		/**
		 * Filter which post statuses qualify a guidelines post for injection.
		 *
		 * @param string[] $statuses
		 */
		$statuses = (array) apply_filters( 'extend_ai_guidelines_statuses', array( 'publish', 'draft' ) );

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $statuses,
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		if ( is_object_in_taxonomy( $post_type, self::TAXONOMY ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- singleton lookup, mirrors Gutenberg's own query.
			$args['tax_query'] = array(
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'slug',
					'terms'    => self::TERM_CONTENT,
				),
			);
		}

		$posts = get_posts( $args );

		return $posts[0] ?? null;
	}

	/**
	 * Raw guideline text per category, plus per-block rules.
	 *
	 * @return array{site:string, copy:string, images:string, additional:string, blocks:array<string,string>}
	 */
	public function categories(): array {
		$out = array(
			'site'       => '',
			'copy'       => '',
			'images'     => '',
			'additional' => '',
			'blocks'     => array(),
		);

		$post = $this->guidelines_post();
		if ( ! $post ) {
			return $out;
		}

		foreach ( self::CATEGORY_META_KEYS as $category ) {
			$out[ $category ] = trim( (string) get_post_meta( $post->ID, '_guideline_' . $category, true ) );
		}

		foreach ( (array) get_post_meta( $post->ID ) as $meta_key => $values ) {
			if ( ! str_starts_with( (string) $meta_key, self::BLOCK_META_PREFIX ) ) {
				continue;
			}
			$value = trim( (string) ( $values[0] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			// `_guideline_block_core_paragraph` → `core/paragraph` (first underscore is the namespace separator).
			$block_name = (string) preg_replace(
				'/_/',
				'/',
				substr( (string) $meta_key, strlen( self::BLOCK_META_PREFIX ) ),
				1
			);

			$out['blocks'][ $block_name ] = $value;
		}

		return $out;
	}

	/**
	 * Compose the "Site guidelines" prompt section.
	 *
	 * Returns an empty string when the feature is disabled, unavailable, or the
	 * guidelines are empty — callers can append the result unconditionally.
	 * When `$data` carries a post_id, block rules are narrowed to block types
	 * actually present in that post's content.
	 *
	 * @param array<string,mixed> $data Per-call data from the ability (post_id, etc.).
	 */
	public function compose( array $data = array() ): string {
		if ( ! $this->is_enabled() || ! $this->is_available() ) {
			return '';
		}

		$categories = $this->categories();
		$sections   = array();

		foreach ( self::CATEGORY_LABELS as $category => $label ) {
			if ( '' !== $categories[ $category ] ) {
				$sections[] = '### ' . $label . "\n" . $categories[ $category ];
			}
		}

		$blocks = $this->relevant_blocks( $categories['blocks'], (int) ( $data['post_id'] ?? 0 ) );
		if ( array() !== $blocks ) {
			$lines = array();
			foreach ( $blocks as $block_name => $rule ) {
				$lines[] = '- ' . $block_name . ': ' . $rule;
			}
			$sections[] = "### Block-specific rules\n" . implode( "\n", $lines );
		}

		if ( array() === $sections ) {
			return '';
		}

		$text = "## Site guidelines\n\n"
			. 'The site has defined the following content guidelines. Evaluate the content against these '
			. 'standards and cite the relevant guideline when flagging an issue. Where these guidelines '
			. "conflict with other instructions in this prompt, those instructions take precedence.\n\n"
			. implode( "\n\n", $sections );

		/**
		 * Filter the composed guidelines prompt section.
		 *
		 * @param string              $text
		 * @param array<string,mixed> $data Per-call data from the ability.
		 */
		return (string) apply_filters( 'extend_ai_guidelines_text', $text, $data );
	}

	/**
	 * The guideline post and its latest revision, for audit trails.
	 *
	 * @return array{post_id:int, revision_id:int}|null
	 */
	public function reference(): ?array {
		$post = $this->guidelines_post();
		if ( ! $post ) {
			return null;
		}

		$revisions = wp_get_post_revisions(
			$post->ID,
			array(
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);

		return array(
			'post_id'     => (int) $post->ID,
			'revision_id' => (int) ( array_shift( $revisions ) ?? 0 ),
		);
	}

	/**
	 * Expose guidelines as `{variable}` placeholders for prompt-override templates.
	 *
	 * Always defines the keys (empty when unavailable) so a `{guidelines}`
	 * placeholder never leaks literally into a prompt sent to the model.
	 *
	 * Hooked on `extend_ai_prompt_variables`.
	 *
	 * @param array<string,scalar> $vars
	 * @param string               $ability_name
	 * @param array<string,mixed>  $data
	 * @return array<string,scalar>
	 */
	public function add_variables( array $vars, string $ability_name, array $data ): array {
		unset( $ability_name );

		$vars['guidelines'] = $this->compose( $data );

		$active     = $this->is_enabled() && $this->is_available();
		$categories = $active ? $this->categories() : null;

		foreach ( self::CATEGORY_META_KEYS as $category ) {
			$vars[ 'guidelines_' . $category ] = $categories[ $category ] ?? '';
		}

		$blocks = $categories ? $this->relevant_blocks( $categories['blocks'], (int) ( $data['post_id'] ?? 0 ) ) : array();
		$lines  = array();
		foreach ( $blocks as $block_name => $rule ) {
			$lines[] = '- ' . $block_name . ': ' . $rule;
		}
		$vars['guidelines_blocks'] = implode( "\n", $lines );

		return $vars;
	}

	/**
	 * Narrow per-block rules to block types present in the post under review.
	 * Falls back to all rules when there is no post or it has no parsable blocks.
	 *
	 * @param array<string,string> $rules Block name → rule text.
	 * @return array<string,string>
	 */
	private function relevant_blocks( array $rules, int $post_id ): array {
		if ( array() === $rules ) {
			return array();
		}

		$present = $this->block_names_in_post( $post_id );
		if ( null === $present ) {
			return $rules;
		}

		return array_intersect_key( $rules, array_flip( $present ) );
	}

	/**
	 * Block type names used in a post's content, or null when undeterminable.
	 *
	 * @return string[]|null
	 */
	private function block_names_in_post( int $post_id ): ?array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || '' === $post->post_content || ! has_blocks( $post ) ) {
			return null;
		}

		$names = array();
		$walk  = static function ( array $blocks ) use ( &$walk, &$names ): void {
			foreach ( $blocks as $block ) {
				if ( ! empty( $block['blockName'] ) ) {
					$names[ (string) $block['blockName'] ] = true;
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $post->post_content ) );

		return array() === $names ? null : array_keys( $names );
	}
}
