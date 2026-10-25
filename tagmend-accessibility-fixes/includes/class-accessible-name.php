<?php
/**
 * The accessible name of one link, button or form field.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Naming facts for one element, gathered once by the analyse pass and shared
 * by every fix and check that asks whether a control has a name (F03, F05,
 * F06, F07, F08, F19, F21, R03 to R07). The name follows the order of the
 * accessible name computation for these elements: aria-labelledby (when at
 * least one id exists), aria-label, the native source (a label for fields,
 * the value or a default for input buttons, the content for links and
 * buttons), title, and placeholder for text fields. Icon-font glyphs (Private
 * Use Area characters) and whitespace do not count as a name.
 *
 * Content and label text are complete once the element and its labels have
 * closed; ids named by aria-labelledby and label[for] may appear later, so
 * the engine calls resolve() after the last token, before any finish().
 */
final class Accessible_Name {

	const KIND_LINK   = 'link';
	const KIND_BUTTON = 'button';
	const KIND_FIELD  = 'field';
	const KIND_OTHER  = 'other';

	/**
	 * Attributes read from a link's opener when present (naming, F05, F21).
	 */
	const LINK_ATTRIBUTES = array(
		'aria-describedby' => true,
		'aria-label'       => true,
		'aria-labelledby'  => true,
		'hidden'           => true,
		'href'             => true,
		'target'           => true,
		'title'            => true,
	);

	/**
	 * Attributes read from a button's opener when present (naming, F06).
	 */
	const BUTTON_ATTRIBUTES = array(
		'alt'             => true,
		'aria-controls'   => true,
		'aria-label'      => true,
		'aria-labelledby' => true,
		'class'           => true,
		'hidden'          => true,
		'title'           => true,
		'type'            => true,
		'value'           => true,
	);

	/**
	 * Attributes read from a field's opener when present (naming, F08).
	 */
	const FIELD_ATTRIBUTES = array(
		'aria-label'      => true,
		'aria-labelledby' => true,
		'hidden'          => true,
		'id'              => true,
		'name'            => true,
		'placeholder'     => true,
		'title'           => true,
		'type'            => true,
	);

	/**
	 * Attributes read from other records (a without href, buttons with another role).
	 */
	const OTHER_ATTRIBUTES = array(
		'alt'             => true,
		'aria-label'      => true,
		'aria-labelledby' => true,
		'hidden'          => true,
		'title'           => true,
	);

	/**
	 * Input types that are buttons.
	 */
	const BUTTON_TYPES = array(
		'submit' => true,
		'reset'  => true,
		'button' => true,
		'image'  => true,
	);

	/**
	 * Input types whose placeholder is shown as text (and counts as a last-resort name).
	 */
	const PLACEHOLDER_TYPES = array(
		'text'     => true,
		'search'   => true,
		'email'    => true,
		'tel'      => true,
		'url'      => true,
		'password' => true,
		'number'   => true,
	);

	/**
	 * Browser default names of submit and reset buttons without a value attribute.
	 */
	const DEFAULT_NAMES = array(
		'submit' => 'Submit',
		'reset'  => 'Reset',
	);

	/**
	 * Opener ordinal.
	 *
	 * @var int
	 */
	public $ordinal;

	/**
	 * Upper-case tag name.
	 *
	 * @var string
	 */
	public $tag;

	/**
	 * Lower-case explicit role, or ''.
	 *
	 * @var string
	 */
	public $role;

	/**
	 * One of the KIND_* constants.
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * Lower-case input type ('text' when absent), '' for other elements.
	 *
	 * @var string
	 */
	public $type = '';

	/**
	 * Privacy-stripped signature, '' when the run keeps no details.
	 *
	 * @var string
	 */
	public $sig = '';

	/**
	 * Whether the element is out of the accessibility tree: aria-hidden="true"
	 * on itself or a tracked ancestor, or a hidden attribute on itself.
	 *
	 * @var bool
	 */
	public $hidden = false;

	/**
	 * The element's own frame (links, buttons, role="button" elements) while it
	 * is open; close() keeps its text and lets the frame go.
	 *
	 * @var Element|null
	 */
	public $frame = null;

	/**
	 * Collected text of the element once it closed, as found.
	 *
	 * @var string
	 */
	private $text = '';

	/**
	 * Content without icon glyphs, computed on first use.
	 *
	 * @var string|null
	 */
	private $content = null;

	/**
	 * The label element wrapping a field, if any.
	 *
	 * @var Element|null
	 */
	public $label = null;

	/**
	 * The form (or search element) around the element, if any.
	 *
	 * @var Element|null
	 */
	public $form = null;

	/**
	 * The article around the element, if any.
	 *
	 * @var Element|null
	 */
	public $article = null;

	/**
	 * Class names of icon elements (i, span, svg) inside a link or button.
	 *
	 * @var string[]
	 */
	public $icon_classes = array();

	/**
	 * Images inside a link or button.
	 *
	 * @var int
	 */
	public $images = 0;

	/**
	 * Images without an alt attribute inside a link or button.
	 *
	 * @var int
	 */
	public $images_without_alt = 0;

	/**
	 * Inline SVG elements inside a link or button.
	 *
	 * @var int
	 */
	public $svgs = 0;

	/**
	 * Decoded attribute values by lower-case name (only those present).
	 *
	 * @var string[]
	 */
	private $attributes;

	/**
	 * Resolved name; null until resolve() ran. Scalars rather than an array
	 * keep each of the many records small.
	 *
	 * @var string|null
	 */
	private $name = null;

	/**
	 * Resolved source of the name.
	 *
	 * @var string
	 */
	private $source = '';

	/**
	 * Whether the resolved name text is complete.
	 *
	 * @var bool
	 */
	private $exact = true;

	/**
	 * Resolved label-element text of a field.
	 *
	 * @var string
	 */
	private $label_text = '';

	/**
	 * Creates the facts for an opener.
	 *
	 * @param int      $ordinal    Opener ordinal.
	 * @param string   $tag        Upper-case tag name.
	 * @param string   $role       Lower-case explicit role, or ''.
	 * @param string   $kind       One of the KIND_* constants (see kind_for()).
	 * @param string[] $attributes Decoded values of the attributes_for( $kind ) present ('' for boolean attributes).
	 */
	public function __construct( int $ordinal, string $tag, string $role, string $kind, array $attributes ) {
		$this->ordinal    = $ordinal;
		$this->tag        = $tag;
		$this->role       = $role;
		$this->kind       = $kind;
		$this->attributes = $attributes;
		if ( 'INPUT' === $tag ) {
			$type       = strtolower( $this->attr( 'type' ) );
			$this->type = '' === $type ? 'text' : $type;
		}
		$this->hidden = isset( $attributes['hidden'] );
	}

	/**
	 * The attributes an opener of a kind keeps (lower-case name => true).
	 *
	 * @param string $kind One of the KIND_* constants.
	 * @return bool[]
	 */
	public static function attributes_for( string $kind ): array {
		switch ( $kind ) {
			case self::KIND_LINK:
				return self::LINK_ATTRIBUTES;
			case self::KIND_BUTTON:
				return self::BUTTON_ATTRIBUTES;
			case self::KIND_FIELD:
				return self::FIELD_ATTRIBUTES;
		}
		return self::OTHER_ATTRIBUTES;
	}

	/**
	 * Keeps the element's collected text when its frame closes (the engine calls this).
	 *
	 * @param string $text Collected text, as found.
	 * @return void
	 */
	public function close( string $text ): void {
		$this->text    = $text;
		$this->frame   = null;
		$this->content = null;
	}

	/**
	 * Which kind of record an opener gets, or '' for none.
	 *
	 * @param string $tag  Upper-case tag name.
	 * @param string $role Lower-case explicit role, or ''.
	 * @param string $type Lower-case type attribute (inputs), or ''.
	 * @param bool   $href Whether an href attribute is present.
	 * @return string
	 */
	public static function kind_for( string $tag, string $role, string $type, bool $href ): string {
		if ( 'button' === $role ) {
			return 'INPUT' === $tag && 'hidden' === $type ? '' : self::KIND_BUTTON;
		}
		if ( 'link' === $role ) {
			return self::KIND_LINK;
		}
		switch ( $tag ) {
			case 'A':
				return '' === $role && $href ? self::KIND_LINK : self::KIND_OTHER;
			case 'BUTTON':
				return '' === $role ? self::KIND_BUTTON : self::KIND_OTHER;
			case 'INPUT':
				if ( 'hidden' === $type ) {
					return '';
				}
				return isset( self::BUTTON_TYPES[ $type ] ) ? self::KIND_BUTTON : self::KIND_FIELD;
			case 'SELECT':
			case 'TEXTAREA':
				return self::KIND_FIELD;
		}
		return '';
	}

	/**
	 * Attribute value as trimmed text; '' when absent or empty.
	 *
	 * @param string $name Lower-case attribute name.
	 * @return string
	 */
	public function attr( string $name ): string {
		return isset( $this->attributes[ $name ] ) ? trim( $this->attributes[ $name ] ) : '';
	}

	/**
	 * Attribute value as found (decoded, not trimmed), or null when absent.
	 *
	 * @param string $name Lower-case attribute name.
	 * @return string|null
	 */
	public function raw( string $name ) {
		return isset( $this->attributes[ $name ] ) ? $this->attributes[ $name ] : null;
	}

	/**
	 * Whether an attribute is present.
	 *
	 * @param string $name Lower-case attribute name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		return isset( $this->attributes[ $name ] );
	}

	/**
	 * What Fix_Base::plan_at() needs to plan an edit on this element.
	 *
	 * @return array
	 */
	public function snapshot(): array {
		return array(
			'ordinal' => $this->ordinal,
			'tag'     => $this->tag,
			'sig'     => $this->sig,
		);
	}

	/**
	 * A link with href, a button (including input buttons) or a role="button" or role="link" element.
	 *
	 * @return bool
	 */
	public function is_control(): bool {
		return self::KIND_BUTTON === $this->kind || ( self::KIND_LINK === $this->kind && ( 'A' !== $this->tag || $this->has( 'href' ) ) );
	}

	/**
	 * Whether the element wraps content that names it: a, button, or anything with role button or link.
	 *
	 * @return bool
	 */
	public function is_container(): bool {
		return 'A' === $this->tag || 'BUTTON' === $this->tag || 'button' === $this->role || 'link' === $this->role;
	}

	/**
	 * The element's text content without icon glyphs; '' for elements without content.
	 *
	 * @return string
	 */
	public function content(): string {
		if ( null !== $this->frame ) {
			return Element::without_private_use( $this->frame->text );
		}
		if ( null === $this->content ) {
			$this->content = '' === $this->text ? '' : Element::without_private_use( $this->text );
			$this->text    = '';
		}
		return $this->content;
	}

	/**
	 * Completes the facts once the whole document has been read.
	 *
	 * @param Document_Index $index The complete index.
	 * @return void
	 */
	public function resolve( Document_Index $index ): void {
		$facts            = $this->compute( $index );
		$this->name       = $facts['name'];
		$this->source     = $facts['source'];
		$this->exact      = $facts['exact'];
		$this->label_text = $facts['label'];
	}

	/**
	 * The accessible name ('' when none). Before resolve(), aria-labelledby
	 * counts as a name and label[for] is not known yet.
	 *
	 * @return string
	 */
	public function name(): string {
		return null === $this->name ? $this->compute( null )['name'] : $this->name;
	}

	/**
	 * Where the name comes from: 'aria-labelledby', 'aria-label', 'label',
	 * 'content', 'value', 'alt', 'default', 'title', 'placeholder', or ''.
	 *
	 * @return string
	 */
	public function source(): string {
		return null === $this->name ? $this->compute( null )['source'] : $this->source;
	}

	/**
	 * Whether the element has a non-empty accessible name.
	 *
	 * @return bool
	 */
	public function is_named(): bool {
		return '' !== $this->source();
	}

	/**
	 * False when the name comes from aria-labelledby and the text of a
	 * referenced element was not collected (it exists, so the element is named).
	 *
	 * @return bool
	 */
	public function is_exact(): bool {
		return null === $this->name ? $this->compute( null )['exact'] : $this->exact;
	}

	/**
	 * For fields: whether a label element (label[for] or a wrapping label) gives them text.
	 *
	 * @return bool
	 */
	public function has_label(): bool {
		return '' !== ( null === $this->name ? $this->compute( null )['label'] : $this->label_text );
	}

	/**
	 * The name computation.
	 *
	 * @param Document_Index|null $index The complete index, or null before the pass ended.
	 * @return array Keys name, source, exact, label.
	 */
	private function compute( $index ): array {
		$label = self::KIND_FIELD === $this->kind ? $this->label_text( $index ) : '';
		$found = $this->named_by_labelledby( $index );
		if ( null === $found ) {
			$found = $this->named_by_attributes( $label );
		}
		$found['label'] = $label;
		return $found;
	}

	/**
	 * The aria-labelledby step: null when it does not name the element.
	 *
	 * @param Document_Index|null $index Index, or null before the pass ended.
	 * @return array|null
	 */
	private function named_by_labelledby( $index ) {
		if ( ! isset( $this->attributes['aria-labelledby'] ) ) {
			return null;
		}
		$ids = preg_split( '/\s+/', $this->attr( 'aria-labelledby' ), -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $ids ) ) {
			return null;
		}
		if ( null === $index ) {
			return self::found( '', 'aria-labelledby', false );
		}
		$valid = false;
		$exact = true;
		$parts = array();
		foreach ( (array) $ids as $id ) {
			if ( $index->id_count( (string) $id ) < 1 ) {
				continue;
			}
			$valid = true;
			$text  = $index->id_text( (string) $id );
			if ( null === $text ) {
				$exact = false;
			} else {
				$parts[] = $text;
			}
		}
		if ( ! $valid ) {
			return null;
		}
		$text = Element::without_private_use( implode( ' ', $parts ) );
		// An empty result passes on to the next step, unless some referenced text is unknown.
		if ( '' === $text && $exact ) {
			return null;
		}
		return self::found( $text, 'aria-labelledby', $exact );
	}

	/**
	 * The steps after aria-labelledby.
	 *
	 * @param string $label Label text of a field.
	 * @return array
	 */
	private function named_by_attributes( string $label ): array {
		$aria_label = isset( $this->attributes['aria-label'] ) ? Element::without_private_use( $this->attributes['aria-label'] ) : '';
		if ( '' !== $aria_label ) {
			return self::found( $aria_label, 'aria-label' );
		}

		if ( 'INPUT' === $this->tag && self::KIND_FIELD !== $this->kind ) {
			$native = $this->input_button_name();
			if ( null !== $native ) {
				return $native;
			}
		} elseif ( self::KIND_FIELD === $this->kind ) {
			if ( '' !== $label ) {
				return self::found( $label, 'label' );
			}
		} elseif ( 'IMG' === $this->tag ) {
			$alt = Element::without_private_use( $this->attr( 'alt' ) );
			if ( '' !== $alt ) {
				return self::found( $alt, 'alt' );
			}
		} else {
			$content = $this->content();
			if ( '' !== $content ) {
				return self::found( $content, 'content' );
			}
		}

		$title = isset( $this->attributes['title'] ) ? Element::without_private_use( $this->attributes['title'] ) : '';
		if ( '' !== $title ) {
			return self::found( $title, 'title' );
		}

		if ( self::KIND_FIELD === $this->kind && isset( $this->attributes['placeholder'] ) && ( 'TEXTAREA' === $this->tag || isset( self::PLACEHOLDER_TYPES[ $this->type ] ) ) ) {
			$placeholder = Element::without_private_use( $this->attr( 'placeholder' ) );
			if ( '' !== $placeholder ) {
				return self::found( $placeholder, 'placeholder' );
			}
		}
		return self::found( '', '' );
	}

	/**
	 * Native name of an input button, or null to go on to title.
	 *
	 * @return array|null
	 */
	private function input_button_name() {
		if ( 'image' === $this->type ) {
			$alt = Element::without_private_use( $this->attr( 'alt' ) );
			return '' === $alt ? null : self::found( $alt, 'alt' );
		}
		if ( $this->has( 'value' ) ) {
			$value = Element::without_private_use( $this->attr( 'value' ) );
			return '' === $value ? null : self::found( $value, 'value' );
		}
		if ( isset( self::DEFAULT_NAMES[ $this->type ] ) ) {
			return self::found( self::DEFAULT_NAMES[ $this->type ], 'default' );
		}
		return null;
	}

	/**
	 * Text of the label elements of a field: label[for] its id, and a wrapping
	 * label unless that label points at another element.
	 *
	 * @param Document_Index|null $index Index, or null before the pass ended.
	 * @return string
	 */
	private function label_text( $index ): string {
		$parts = array();
		$id    = $this->attr( 'id' );
		if ( '' !== $id && null !== $index ) {
			$text = $index->label_text( $id );
			if ( null !== $text ) {
				$parts[] = $text;
			}
		}
		if ( null !== $this->label && ( '' === $this->label->label_for || $id === $this->label->label_for ) ) {
			$parts[] = $this->label->text;
		}
		return Element::without_private_use( implode( ' ', $parts ) );
	}

	/**
	 * One result.
	 *
	 * @param string $name   Name.
	 * @param string $source Source.
	 * @param bool   $exact  Whether the name text is complete.
	 * @return array
	 */
	private static function found( string $name, string $source, bool $exact = true ): array {
		return array(
			'name'   => $name,
			'source' => $source,
			'exact'  => $exact,
		);
	}
}
