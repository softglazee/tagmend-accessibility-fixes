<?php
/**
 * An open element on the analyse pass's small stack.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A frame for one tracked element (links, buttons, labels, forms, articles,
 * headings, landmarks, inline SVG and anything with aria-hidden="true").
 * Text-collecting frames gather the text that names the element.
 */
final class Element {

	/**
	 * Collected text is capped at this many bytes; names are capped lower.
	 */
	const TEXT_LIMIT = 512;

	/**
	 * Upper-case tag name.
	 *
	 * @var string
	 */
	public $tag;

	/**
	 * Opener ordinal of the element.
	 *
	 * @var int
	 */
	public $ordinal;

	/**
	 * Whether text inside the element is collected.
	 *
	 * @var bool
	 */
	public $collects_text;

	/**
	 * Whether the element carries aria-hidden="true".
	 *
	 * @var bool
	 */
	public $aria_hidden;

	/**
	 * Text content, image alt text and SVG titles found inside, in document order.
	 *
	 * @var string
	 */
	public $text = '';

	/**
	 * True when the element contains an SVG title (set on SVG frames).
	 *
	 * @var bool
	 */
	public $has_svg_title = false;

	/**
	 * Untracked elements with the same tag still open inside this one; their
	 * end tags do not close the frame.
	 *
	 * @var int
	 */
	public $nested = 0;

	/**
	 * Free storage for fixes that decide when the element closes.
	 *
	 * @var array
	 */
	public $data = array();

	/**
	 * For A and BUTTON frames, filled by Token::remember_control() on the
	 * opener: 'control' (a link with href, or a button) and 'own_name'
	 * (a non-empty aria-label, aria-labelledby or title). Null until then.
	 *
	 * @var array|null
	 */
	public $control = null;

	/**
	 * Creates a frame.
	 *
	 * @param string $tag           Upper-case tag name.
	 * @param int    $ordinal       Opener ordinal.
	 * @param bool   $collects_text Whether text inside is collected.
	 * @param bool   $aria_hidden   Whether aria-hidden="true" is set.
	 */
	public function __construct( string $tag, int $ordinal, bool $collects_text, bool $aria_hidden ) {
		$this->tag           = $tag;
		$this->ordinal       = $ordinal;
		$this->collects_text = $collects_text;
		$this->aria_hidden   = $aria_hidden;
	}

	/**
	 * Appends text, up to the cap.
	 *
	 * @param string $text Decoded text.
	 * @return void
	 */
	public function add_text( string $text ): void {
		if ( '' === $text || strlen( $this->text ) >= self::TEXT_LIMIT ) {
			return;
		}
		$this->text .= $text;
	}

	/**
	 * The collected text with whitespace collapsed.
	 *
	 * @return string
	 */
	public function normalized_text(): string {
		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $this->text ) );
	}

	/**
	 * Whether this is a link with href or a button that has a name from its
	 * own attributes or its content. Icon-font glyphs (Private Use Area
	 * characters) do not count as a name. Call after the element closed.
	 *
	 * @return bool
	 */
	public function is_named_control(): bool {
		if ( null === $this->control || ! $this->control['control'] ) {
			return false;
		}
		return $this->control['own_name'] || '' !== self::without_private_use( $this->normalized_text() );
	}

	/**
	 * Text with Private Use Area characters (icon-font glyphs) removed, whitespace collapsed and trimmed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function without_private_use( string $text ): string {
		$text = (string) preg_replace( '/[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}\x{100000}-\x{10FFFD}]/u', '', $text );
		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}
}
