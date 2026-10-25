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
	 * Element openers (other than br and wbr) seen while this frame was the
	 * innermost open frame. A tracked child counts once when it opens, so
	 * zero means the element holds no element at all.
	 *
	 * @var int
	 */
	public $elements = 0;

	/**
	 * Free storage for fixes that decide when the element closes.
	 *
	 * @var array
	 */
	public $data = array();

	/**
	 * Trimmed id attribute value, or ''.
	 *
	 * @var string
	 */
	public $id = '';

	/**
	 * Text found inside this element does not reach the frames around it:
	 * hidden content, a select's options, an element named by its own
	 * aria-label (that label stands in for its content).
	 *
	 * @var bool
	 */
	public $barrier = false;

	/**
	 * The naming facts of a link, button, field or role="button" element,
	 * computed once by the engine and shared by every fix. Null for other
	 * elements and when no active fix or check uses names.
	 *
	 * @var Accessible_Name|null
	 */
	public $accessible = null;

	/**
	 * On LABEL frames: the trimmed for attribute value, or ''.
	 *
	 * @var string
	 */
	public $label_for = '';

	/**
	 * On FORM and SEARCH frames: whether this is a search form (role="search",
	 * a search element, or a field named "s" or of type search inside).
	 *
	 * @var bool
	 */
	public $search = false;

	/**
	 * On ARTICLE frames: the text of the first heading inside, once it closed.
	 *
	 * @var string|null
	 */
	public $heading = null;

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
		$this->barrier       = $aria_hidden;
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
	 * Whether this is a link with href, a button or a role="button" element
	 * that has a non-empty accessible name in the original markup (from its
	 * own attributes or its content; icon-font glyphs do not count). Reliable
	 * once the analyse pass has ended (in Fix_Base::finish()).
	 *
	 * @return bool
	 */
	public function is_named_control(): bool {
		return null !== $this->accessible && $this->accessible->is_control() && $this->accessible->is_named();
	}

	/**
	 * Text with Private Use Area characters (icon-font glyphs) removed, whitespace collapsed and trimmed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function without_private_use( string $text ): string {
		// Private Use Area characters start with one of these bytes in UTF-8; most text has none.
		if ( false !== strpbrk( $text, "\xEE\xEF\xF3\xF4" ) ) {
			$text = (string) preg_replace( '/[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}\x{100000}-\x{10FFFD}]/u', '', $text );
		}
		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}
}
