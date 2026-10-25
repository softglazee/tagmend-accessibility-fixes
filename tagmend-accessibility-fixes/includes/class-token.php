<?php
/**
 * The token a fix sees during the analyse pass.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

use WP_HTML_Tag_Processor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A read-only view of the processor's current token. The engine reuses one
 * instance for the whole pass, so fixes must copy what they need and never
 * keep the object.
 */
final class Token {

	/**
	 * Opener ordinal: for an opener its own, for a closer the ordinal of the
	 * element being closed.
	 *
	 * @var int
	 */
	public $ordinal = -1;

	/**
	 * Upper-case tag name.
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * True when the element is being closed (by its end tag, implicitly, or at the end of input).
	 *
	 * @var bool
	 */
	public $is_closer = false;

	/**
	 * The frame of the current element when it is tracked, otherwise null.
	 *
	 * @var Element|null
	 */
	public $element = null;

	/**
	 * The processor positioned on this token (openers only).
	 *
	 * @var WP_HTML_Tag_Processor|null
	 */
	private $processor = null;

	/**
	 * Open tracked elements, outermost first, excluding the current element.
	 *
	 * @var Element[]
	 */
	private $ancestors = array();

	/**
	 * Points the token at an opener.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor on the opener.
	 * @param int                   $ordinal   Opener ordinal.
	 * @param string                $name      Upper-case tag name.
	 * @param Element|null          $element   Frame when the element is tracked.
	 * @param Element[]             $ancestors Open tracked elements.
	 * @return void
	 */
	public function set_opener( WP_HTML_Tag_Processor $processor, int $ordinal, string $name, $element, array $ancestors ): void {
		$this->processor = $processor;
		$this->ordinal   = $ordinal;
		$this->name      = $name;
		$this->is_closer = false;
		$this->element   = $element;
		$this->ancestors = $ancestors;
	}

	/**
	 * Points the token at a closing tracked element.
	 *
	 * @param Element   $element   The frame being closed.
	 * @param Element[] $ancestors Tracked elements still open around it.
	 * @return void
	 */
	public function set_closer( Element $element, array $ancestors ): void {
		$this->processor = null;
		$this->ordinal   = $element->ordinal;
		$this->name      = $element->tag;
		$this->is_closer = true;
		$this->element   = $element;
		$this->ancestors = $ancestors;
	}

	/**
	 * Releases the processor and the stack after the pass.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->processor = null;
		$this->element   = null;
		$this->ancestors = array();
	}

	/**
	 * Decoded attribute value: a string, true for a boolean attribute, null when absent or on a closer.
	 *
	 * @param string $name Attribute name.
	 * @return string|true|null
	 */
	public function attr( string $name ) {
		if ( null === $this->processor ) {
			return null;
		}
		return $this->processor->get_attribute( $name );
	}

	/**
	 * Attribute value as trimmed text; '' when absent, boolean or empty.
	 *
	 * @param string $name Attribute name.
	 * @return string
	 */
	public function attr_text( string $name ): string {
		$value = $this->attr( $name );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Nearest open tracked ancestor with the given tag name.
	 *
	 * @param string $tag Upper-case tag name.
	 * @return Element|null
	 */
	public function closest( string $tag ) {
		for ( $i = count( $this->ancestors ) - 1; $i >= 0; $i-- ) {
			if ( $tag === $this->ancestors[ $i ]->tag ) {
				return $this->ancestors[ $i ];
			}
		}
		return null;
	}

	/**
	 * Whether any open tracked ancestor carries aria-hidden="true".
	 *
	 * @return bool
	 */
	public function inside_aria_hidden(): bool {
		foreach ( $this->ancestors as $ancestor ) {
			if ( $ancestor->aria_hidden ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Open tracked ancestors, outermost first.
	 *
	 * @return Element[]
	 */
	public function ancestors(): array {
		return $this->ancestors;
	}

	/**
	 * Privacy-stripped signature of the current opener: tag, id, classes, role,
	 * type, name and the path of href or src. Never values, data attributes,
	 * query strings or text content.
	 *
	 * @return string
	 */
	public function signature(): string {
		$signature = strtolower( $this->name );
		if ( null === $this->processor ) {
			return $signature;
		}

		$id = $this->attr_text( 'id' );
		if ( '' !== $id ) {
			$signature .= '#' . self::clip( $id, 80 );
		}

		$classes = preg_split( '/\s+/', $this->attr_text( 'class' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( array_slice( (array) $classes, 0, 3 ) as $class ) {
			$signature .= '.' . self::clip( $class, 40 );
		}

		foreach ( array( 'role', 'type', 'name' ) as $attribute ) {
			$value = $this->attr_text( $attribute );
			if ( '' !== $value ) {
				$signature .= '[' . $attribute . '=' . self::clip( $value, 40 ) . ']';
			}
		}

		foreach ( array( 'href', 'src' ) as $attribute ) {
			$value = $this->attr_text( $attribute );
			if ( '' === $value ) {
				continue;
			}
			$path       = wp_parse_url( $value, PHP_URL_PATH );
			$signature .= '[' . $attribute . '=' . self::clip( is_string( $path ) ? $path : '', 80 ) . ']';
			break;
		}

		return self::clip( $signature, 200 );
	}

	/**
	 * Removes control characters and caps the length.
	 *
	 * @param string $value Text.
	 * @param int    $max   Maximum characters.
	 * @return string
	 */
	private static function clip( string $value, int $max ): string {
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', $value );
		return mb_substr( $value, 0, $max );
	}
}
