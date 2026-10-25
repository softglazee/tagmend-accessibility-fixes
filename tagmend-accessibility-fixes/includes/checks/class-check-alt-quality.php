<?php
/**
 * R03: alt text that looks wrong.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Checks;

use SoftGlaze\AccessibilityRepair\Check_Base;
use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags alt text that looks like a file name, starts with "image of" and the
 * like, is longer than 150 characters, or repeats the link text or the
 * caption next to it. Looks at the alt in the markup and, for images without
 * one, at the Media Library alt that F01 would add.
 */
final class Check_Alt_Quality extends Check_Base {

	/**
	 * Longer alt text is flagged (and capped by F01 and F02).
	 */
	const MAX_LENGTH = 150;

	/**
	 * Alt text quoted in a finding is cut to this many characters (report privacy rule).
	 */
	const QUOTE_LENGTH = 80;

	/**
	 * Data key on figure frames.
	 */
	const KEY = 'alt-quality-caption';

	/**
	 * Images seen in this run.
	 *
	 * @var array
	 */
	private $images = array();

	/**
	 * Report id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'R03';
	}

	/**
	 * WCAG success criterion.
	 *
	 * @return string
	 */
	public function wcag(): string {
		return '1.1.1';
	}

	/**
	 * Images and figure captions.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'IMG', 'FIGCAPTION' );
	}

	/**
	 * Reads whether the link or button around an image is named.
	 *
	 * @return bool
	 */
	public function uses_names(): bool {
		return true;
	}

	/**
	 * Clears the images of the previous run.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->images = array();
	}

	/**
	 * Collects images with alt text (or without, for the library alt) and captions.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Recorder $recorder ): void {
		if ( $token->is_closer ) {
			if ( 'FIGCAPTION' === $token->name && null !== $token->element ) {
				$figure = $token->closest( 'FIGURE' );
				if ( null !== $figure ) {
					$figure->data[ self::KEY ] = $token->element->normalized_text();
				}
			}
			return;
		}
		if ( 'IMG' !== $token->name || $token->is_presentational() ) {
			return;
		}

		$alt = $token->attr( 'alt' );
		if ( true === $alt || ( is_string( $alt ) && '' === trim( $alt ) ) ) {
			return;
		}
		$this->images[] = array(
			'sig'     => $token->signature(),
			'alt'     => is_string( $alt ) ? $alt : null,
			'image'   => $index->attachments()->request_image( $token, null === $alt ),
			'control' => $token->closest_control(),
			'figure'  => $token->closest( 'FIGURE' ),
		);
	}

	/**
	 * Checks each alt text once the captions and library alts are known.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function finish( Document_Index $index, Recorder $recorder ): void {
		if ( empty( $this->images ) ) {
			return;
		}
		$lookup = $index->attachments();
		$lookup->load();
		foreach ( $this->images as $image ) {
			$id      = $lookup->attachment_id( $image['image'] );
			$library = null === $image['alt'];
			if ( $library ) {
				// The alt F01 would add; F03 empties images in named controls instead.
				if ( $id <= 0 || ( null !== $image['control'] && $image['control']->is_named_control() ) ) {
					continue;
				}
				$raw = $lookup->alt( $id );
			} else {
				$raw = $image['alt'];
			}

			$alt = Fix_Base::plain_text( $raw, PHP_INT_MAX );
			if ( '' === $alt ) {
				continue;
			}
			$problem = $this->problem( $alt, $image, $library );
			if ( '' === $problem ) {
				continue;
			}
			if ( $library ) {
				/* translators: %s: what is wrong with the alt text, for example: Alt text looks like a file name. */
				$problem = sprintf( __( 'Media Library alt text: %s', 'tagmend-accessibility-fixes' ), $problem );
			}
			$this->report( $recorder, $image['sig'], $problem, $id );
		}
		$this->images = array();
	}

	/**
	 * The first problem found with an alt text, or ''.
	 *
	 * @param string $alt     Plain alt text.
	 * @param array  $image   Collected image.
	 * @param bool   $library Whether the alt comes from the Media Library.
	 * @return string Translated description.
	 */
	private function problem( string $alt, array $image, bool $library ): string {
		$quote = mb_substr( $alt, 0, self::QUOTE_LENGTH );

		if ( self::looks_like_file_name( $alt ) ) {
			/* translators: %s: the alt text */
			return sprintf( __( 'Alt text looks like a file name: "%s".', 'tagmend-accessibility-fixes' ), $quote );
		}
		if ( preg_match( '/^(image|picture|photo|graphic) of\b/i', $alt, $match ) ) {
			/* translators: %s: the opening words of the alt text, for example "Image of" */
			return sprintf( __( 'Alt text starts with "%s"; screen readers already say it is an image.', 'tagmend-accessibility-fixes' ), $match[0] );
		}
		$length = mb_strlen( $alt );
		if ( $length > self::MAX_LENGTH ) {
			/* translators: %d: number of characters */
			return sprintf( __( 'Alt text is %d characters long; keep it under 150 and put longer descriptions in the page.', 'tagmend-accessibility-fixes' ), $length );
		}

		$compare = self::comparable( $alt );
		if ( null !== $image['control'] && $image['control']->is_named_control() ) {
			$text = $image['control']->normalized_text();
			if ( ! $library ) {
				// The control's text holds this alt once; compare what is left.
				$position = mb_stripos( $text, $image['alt'] );
				if ( false !== $position ) {
					$text = mb_substr( $text, 0, $position ) . mb_substr( $text, $position + mb_strlen( $image['alt'] ) );
				}
			}
			if ( '' !== $compare && self::comparable( $text ) === $compare ) {
				return __( 'Alt text repeats the link or button text, so it is read twice.', 'tagmend-accessibility-fixes' );
			}
		}
		if ( null !== $image['figure'] && isset( $image['figure']->data[ self::KEY ] ) && '' !== $compare && self::comparable( $image['figure']->data[ self::KEY ] ) === $compare ) {
			return __( 'Alt text repeats the caption, so it is read twice.', 'tagmend-accessibility-fixes' );
		}
		return '';
	}

	/**
	 * Whether a text looks like a file name: an image extension, a camera
	 * prefix (IMG_, DSC) followed by digits, or digits only.
	 *
	 * @param string $alt Plain alt text.
	 * @return bool
	 */
	private static function looks_like_file_name( string $alt ): bool {
		return 1 === preg_match( '/\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff?|heic)$/i', $alt )
			|| 1 === preg_match( '/^(?:img|dsc|dscn|dscf|pxl)[\s_-]?\d+/i', $alt )
			|| 1 === preg_match( '/^[\d\s._-]+$/', $alt );
	}

	/**
	 * Lower-case text without punctuation and repeated spaces, for comparisons.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function comparable( string $text ): string {
		$text = mb_strtolower( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) );
		return trim( $text );
	}
}
