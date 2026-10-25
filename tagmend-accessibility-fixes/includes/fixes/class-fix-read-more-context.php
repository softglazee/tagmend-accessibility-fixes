<?php
/**
 * F19: "Read more" links get the post or section title in their accessible name.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Accessible_Name;
use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;
use WP_HTML_Tag_Processor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A link whose whole visible text is "Read more", "Continue reading",
 * "Learn more" or "More" (translated; punctuation and arrows around it are
 * ignored) and that has no aria-label or aria-labelledby gets
 * aria-label = visible text + ": " + title, so its accessible name starts
 * with the visible text (2.5.3 Label in Name). In content and page scope the
 * title is the text of the first heading of the article around the link; in
 * the the_content_more_link and excerpt_more filters (Front_End) it is the
 * current post's title. Core's Read More block already adds the post title
 * in screen-reader text; a Post Excerpt block's "more" link in a query loop
 * (li.wp-block-post, no article) is listed by R07 instead.
 */
final class Fix_Read_More_Context extends Fix_Base {

	/**
	 * Normalised phrases of this run, built when first needed.
	 *
	 * @var string[]|null
	 */
	private $phrases = null;

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'read-more-context';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Post titles for "Read more" links', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Adds the post title to "Read more" style links, for example "Read more: Opening hours", keeping the visible text at the start of the name.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '2.4.4' );
	}

	/**
	 * No axe rule covers link purpose in context.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array();
	}

	/**
	 * Scopes: the more-link filters, content fragments and the whole page.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'attribute', 'content', 'page' );
	}

	/**
	 * Risk.
	 *
	 * @return string
	 */
	public function risk(): string {
		return 'low';
	}

	/**
	 * Starts in Preview.
	 *
	 * @return bool
	 */
	public function recommended(): bool {
		return true;
	}

	/**
	 * Same group as F05 and F06.
	 *
	 * @return int
	 */
	public function priority(): int {
		return 20;
	}

	/**
	 * Decides from the naming facts in finish(); needs no tokens.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( self::FINISH_ONLY );
	}

	/**
	 * Reads the naming facts.
	 *
	 * @return bool
	 */
	public function uses_names(): bool {
		return true;
	}

	/**
	 * Forgets the phrases of the previous run.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->phrases = null;
	}

	/**
	 * Not used: the fix decides in finish().
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
	}

	/**
	 * Labels the "Read more" links inside articles with a known heading.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		foreach ( $index->names() as $link ) {
			if ( ! $this->in_reach( $link ) ) {
				continue;
			}
			$label = self::label_text( $link->content(), self::context_title( $link ) );
			$this->plan_at( $plan, $link->snapshot(), 'aria-label', $label, $link->raw( 'aria-label' ) );
		}
	}

	/**
	 * Whether this fix labels a link: a visible, text-named a[href] whose text
	 * is one of the phrases, inside an article with a heading. R07 lists the
	 * other ambiguous links.
	 *
	 * @param Accessible_Name $link Naming facts.
	 * @return bool
	 */
	public function in_reach( Accessible_Name $link ): bool {
		return Accessible_Name::KIND_LINK === $link->kind && 'A' === $link->tag && $link->has( 'href' ) && ! $link->hidden
			&& 'content' === $link->source() && $this->is_phrase( $link->content() ) && '' !== self::context_title( $link );
	}

	/**
	 * Whether a visible text is one of the phrases.
	 *
	 * @param string $text Visible text.
	 * @return bool
	 */
	public function is_phrase( string $text ): bool {
		if ( null === $this->phrases ) {
			$this->phrases = array_flip( array_map( array( __CLASS__, 'normalise' ), self::phrases() ) );
		}
		// Most link texts are plain ASCII words: compare them without the Unicode-aware normalisation.
		$plain = strtolower( trim( $text ) );
		if ( isset( $this->phrases[ $plain ] ) ) {
			return true;
		}
		if ( '' === $plain || strlen( $plain ) > 80 || ( false === strpos( $plain, '  ' ) && ctype_alnum( str_replace( ' ', '', $plain ) ) ) ) {
			return false;
		}
		return isset( $this->phrases[ self::normalise( $text ) ] );
	}

	/**
	 * The translated phrases this fix gives context to.
	 *
	 * @return string[]
	 */
	public static function phrases(): array {
		return array(
			__( 'Read more', 'tagmend-accessibility-fixes' ),
			__( 'Continue reading', 'tagmend-accessibility-fixes' ),
			__( 'Learn more', 'tagmend-accessibility-fixes' ),
			__( 'More', 'tagmend-accessibility-fixes' ),
		);
	}

	/**
	 * Lower-case text without leading and trailing punctuation, symbols and
	 * spaces, with inner spaces collapsed: "Read More »" → "read more".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalise( string $text ): string {
		$text = (string) preg_replace( '/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $text );
		return self::lower( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}

	/**
	 * The first heading of the article around a link, as plain text, or ''.
	 *
	 * @param Accessible_Name $link Naming facts.
	 * @return string
	 */
	public static function context_title( Accessible_Name $link ): string {
		if ( null === $link->article || null === $link->article->heading ) {
			return '';
		}
		return self::plain_text( $link->article->heading );
	}

	/**
	 * The label: the visible text first, then ": " and the title.
	 *
	 * @param string $visible Visible link text.
	 * @param string $title   Post or heading title.
	 * @return string
	 */
	public static function label_text( string $visible, string $title ): string {
		return self::plain_text( self::plain_text( $visible ) . ': ' . $title );
	}

	/**
	 * Layer A: labels the "Read more" links in a more-link or excerpt-more
	 * string with the current post's title. Two passes like the engine (the
	 * same opener predicate), attributes only; returns the input when nothing
	 * applies or the result does not keep its < and > counts.
	 *
	 * @param string $html  Markup from the filter.
	 * @param string $title Post title (may contain character references).
	 * @return string
	 */
	public function label_more_links( string $html, string $title ): string {
		$title = self::plain_text( $title );
		if ( '' === $title || false === stripos( $html, '<a' ) ) {
			return $html;
		}

		$targets   = array();
		$processor = new WP_HTML_Tag_Processor( $html );
		$ordinal   = -1;
		$open      = -1;
		$text      = '';
		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();
			if ( '#text' === $type ) {
				if ( $open >= 0 ) {
					$text .= $processor->get_modifiable_text();
				}
				continue;
			}
			if ( '#tag' !== $type ) {
				continue;
			}
			$name = $processor->get_token_name();
			if ( $processor->is_tag_closer() ) {
				if ( 'A' === $name && $open >= 0 ) {
					if ( $this->is_phrase( $text ) ) {
						$targets[ $open ] = self::label_text( $text, $title );
					}
					$open = -1;
				}
				continue;
			}
			++$ordinal;
			if ( 'A' === $name ) {
				$open = -1;
				$text = '';
				if ( null !== $processor->get_attribute( 'href' ) && ! self::has_own_name( $processor ) ) {
					$open = $ordinal;
				}
			} elseif ( $open >= 0 && ( 'IMG' === $name || 'SVG' === $name ) ) {
				$open = -1;
			}
		}
		if ( empty( $targets ) || $processor->paused_at_incomplete_token() ) {
			return $html;
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		$ordinal   = -1;
		while ( $processor->next_token() ) {
			if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
				continue;
			}
			++$ordinal;
			if ( isset( $targets[ $ordinal ] ) && ! $processor->set_attribute( 'aria-label', $targets[ $ordinal ] ) ) {
				return $html;
			}
		}
		$output = $processor->get_updated_html();
		if ( substr_count( $output, '<' ) !== substr_count( $html, '<' ) || substr_count( $output, '>' ) !== substr_count( $html, '>' ) ) {
			return $html;
		}
		return $output;
	}

	/**
	 * Whether the link opener already names itself (a title is only a description next to text).
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor on an A opener.
	 * @return bool
	 */
	private static function has_own_name( WP_HTML_Tag_Processor $processor ): bool {
		foreach ( array( 'aria-label', 'aria-labelledby' ) as $attribute ) {
			$value = $processor->get_attribute( $attribute );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return true;
			}
		}
		return false;
	}
}
