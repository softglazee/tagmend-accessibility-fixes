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

	// TODO(Phase 4A): skip links, main elements and headings for F11, F12 and F20.

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
}
