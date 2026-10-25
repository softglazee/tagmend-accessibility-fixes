<?php
/**
 * What the analyse pass learns about the document.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts and references gathered while the analyse pass runs. The index grows
 * token by token, so it is complete only in Fix_Base::finish(); fixes that
 * need the whole document (a target id that may appear later) decide there.
 */
final class Document_Index {

	/**
	 * 'page' (a whole document) or 'content' (a fragment).
	 *
	 * @var string
	 */
	public $scope;

	/**
	 * Engine mode of the run.
	 *
	 * @var string
	 */
	public $mode;

	/**
	 * Id attribute value => number of elements carrying it.
	 *
	 * @var int[]
	 */
	private $ids = array();

	/**
	 * Id => number of label elements whose for attribute names it.
	 *
	 * @var int[]
	 */
	private $label_for = array();

	/**
	 * Upper-case tag name => number of openers.
	 *
	 * @var int[]
	 */
	private $tags = array();

	/**
	 * Number of tag openers seen.
	 *
	 * @var int
	 */
	private $openers = 0;

	/**
	 * Attachment ids and upload URLs found in the document, loaded in one batch.
	 *
	 * @var Attachment_Lookup
	 */
	private $attachments;

	/**
	 * Naming facts of links, buttons and fields, in document order.
	 *
	 * @var Accessible_Name[]
	 */
	private $names = array();

	/**
	 * Id => text of the first element carrying it, for elements whose text the pass collects.
	 *
	 * @var string[]
	 */
	private $id_text = array();

	/**
	 * Id => text of the labels whose for attribute names it.
	 *
	 * @var string[]
	 */
	private $label_text = array();

	/**
	 * Id => whether the first element carrying it is a menu (nav, or a list
	 * or container with a menu class or role), recorded when names are used.
	 *
	 * @var bool[]
	 */
	private $menus = array();

	/**
	 * Main and navigation landmarks in document order, recorded when a fix
	 * or check uses landmarks. Each entry: ordinal, tag, sig, role ('main' or
	 * 'navigation'), id, label (trimmed aria-label), labelledby (trimmed
	 * aria-labelledby), hidden (hidden attribute or aria-hidden on it or an
	 * ancestor).
	 *
	 * @var array[]
	 */
	private $landmarks = array();

	/**
	 * Creates an empty index.
	 *
	 * @param string                 $scope       'page' or 'content'.
	 * @param string                 $mode        Engine mode.
	 * @param Attachment_Lookup|null $attachments Lookup shared by the engine's runs; a new one when null.
	 */
	public function __construct( string $scope, string $mode, $attachments = null ) {
		$this->scope       = $scope;
		$this->mode        = $mode;
		$this->attachments = $attachments instanceof Attachment_Lookup ? $attachments : new Attachment_Lookup();
	}

	/**
	 * The attachment lookup. Fixes request ids and URLs while the pass runs
	 * and call load() in finish() before reading any alt text; the first call
	 * loads everything in one batch, later calls only what is new.
	 *
	 * @return Attachment_Lookup
	 */
	public function attachments(): Attachment_Lookup {
		return $this->attachments;
	}

	/**
	 * Records a tag opener.
	 *
	 * @param string $tag Upper-case tag name.
	 * @param string $id  Trimmed id attribute value, or ''.
	 * @return void
	 */
	public function add_opener( string $tag, string $id ): void {
		++$this->openers;
		$this->tags[ $tag ] = isset( $this->tags[ $tag ] ) ? $this->tags[ $tag ] + 1 : 1;
		if ( '' !== $id ) {
			$this->ids[ $id ] = isset( $this->ids[ $id ] ) ? $this->ids[ $id ] + 1 : 1;
		}
	}

	/**
	 * Records the for attribute of a label.
	 *
	 * @param string $id Trimmed for attribute value.
	 * @return void
	 */
	public function add_label_for( string $id ): void {
		if ( '' !== $id ) {
			$this->label_for[ $id ] = isset( $this->label_for[ $id ] ) ? $this->label_for[ $id ] + 1 : 1;
		}
	}

	/**
	 * Number of elements carrying an id.
	 *
	 * @param string $id Id value.
	 * @return int
	 */
	public function id_count( string $id ): int {
		return isset( $this->ids[ $id ] ) ? $this->ids[ $id ] : 0;
	}

	/**
	 * Ids used more than once, with their counts.
	 *
	 * @return int[]
	 */
	public function duplicate_ids(): array {
		return array_filter(
			$this->ids,
			static function ( $count ) {
				return $count > 1;
			}
		);
	}

	/**
	 * Number of labels pointing at an id.
	 *
	 * @param string $id Id value.
	 * @return int
	 */
	public function label_for_count( string $id ): int {
		return isset( $this->label_for[ $id ] ) ? $this->label_for[ $id ] : 0;
	}

	/**
	 * Number of openers of a tag.
	 *
	 * @param string $tag Upper-case tag name.
	 * @return int
	 */
	public function tag_count( string $tag ): int {
		return isset( $this->tags[ $tag ] ) ? $this->tags[ $tag ] : 0;
	}

	/**
	 * Number of tag openers seen.
	 *
	 * @return int
	 */
	public function opener_count(): int {
		return $this->openers;
	}

	/**
	 * Records the naming facts of a link, button or field.
	 *
	 * @param Accessible_Name $name Facts.
	 * @return void
	 */
	public function add_name( Accessible_Name $name ): void {
		$this->names[] = $name;
	}

	/**
	 * Naming facts of every link, button and field, in document order.
	 * Resolved (complete) in Fix_Base::finish().
	 *
	 * @return Accessible_Name[]
	 */
	public function names(): array {
		return $this->names;
	}

	/**
	 * Records the collected text of an element with an id; the first element wins.
	 *
	 * @param string $id   Id value.
	 * @param string $text Collected text, as found.
	 * @return void
	 */
	public function set_id_text( string $id, string $text ): void {
		if ( '' !== $id && ! isset( $this->id_text[ $id ] ) ) {
			$this->id_text[ $id ] = $text;
		}
	}

	/**
	 * Text of the element carrying an id (icon glyphs removed, whitespace
	 * collapsed), or null when its text was not collected.
	 *
	 * @param string $id Id value.
	 * @return string|null
	 */
	public function id_text( string $id ) {
		return isset( $this->id_text[ $id ] ) ? Element::without_private_use( $this->id_text[ $id ] ) : null;
	}

	/**
	 * Adds the collected text of a label whose for attribute names an id.
	 *
	 * @param string $id   The for attribute value.
	 * @param string $text Collected label text, as found.
	 * @return void
	 */
	public function add_label_text( string $id, string $text ): void {
		if ( '' === $id ) {
			return;
		}
		$this->label_text[ $id ] = isset( $this->label_text[ $id ] ) ? $this->label_text[ $id ] . ' ' . $text : $text;
	}

	/**
	 * Text of the labels for an id (icon glyphs removed, whitespace
	 * collapsed), or null when no label names it.
	 *
	 * @param string $id Id value.
	 * @return string|null
	 */
	public function label_text( string $id ) {
		return isset( $this->label_text[ $id ] ) ? Element::without_private_use( $this->label_text[ $id ] ) : null;
	}

	/**
	 * Records whether the first element with an id is a menu.
	 *
	 * @param string $id   Id value.
	 * @param bool   $menu Whether it is a nav element or a menu list.
	 * @return void
	 */
	public function set_menu( string $id, bool $menu ): void {
		if ( '' !== $id && ! isset( $this->menus[ $id ] ) ) {
			$this->menus[ $id ] = $menu;
		}
	}

	/**
	 * Whether the element with an id is a nav element or a menu list.
	 *
	 * @param string $id Id value.
	 * @return bool
	 */
	public function is_menu( string $id ): bool {
		return ! empty( $this->menus[ $id ] );
	}

	/**
	 * Records a main or navigation landmark.
	 *
	 * @param array $landmark Facts (see the property).
	 * @return void
	 */
	public function add_landmark( array $landmark ): void {
		$this->landmarks[] = $landmark;
	}

	/**
	 * Landmarks with a role, in document order; hidden ones only when asked.
	 *
	 * @param string $role           'main' or 'navigation'.
	 * @param bool   $include_hidden Whether hidden landmarks are included.
	 * @return array[]
	 */
	public function landmarks( string $role, bool $include_hidden = false ): array {
		$found = array();
		foreach ( $this->landmarks as $landmark ) {
			if ( $role === $landmark['role'] && ( $include_hidden || ! $landmark['hidden'] ) ) {
				$found[] = $landmark;
			}
		}
		return $found;
	}

	/**
	 * The only visible main landmark, or null when there are none or several.
	 *
	 * @return array|null
	 */
	public function single_main() {
		$mains = $this->landmarks( 'main' );
		return 1 === count( $mains ) ? $mains[0] : null;
	}
}
