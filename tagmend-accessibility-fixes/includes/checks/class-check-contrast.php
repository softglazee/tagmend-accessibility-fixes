<?php
/**
 * R01: text contrast where both colours are declared on the same element.
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
 * Computes the WCAG contrast ratio only where an element declares both its
 * text colour and its background colour itself: inline style colours, or the
 * block editor's preset classes (has-{slug}-color, has-{slug}-background-color)
 * resolved through the global settings palette. Large text (24px, or 18.66px
 * and bold) is recognised from a preset font-size class or an inline
 * font-size and font-weight. Anything else (inherited colours, images and
 * gradients behind the text, transparency, CSS variables the palette does not
 * define) is left to a check in the browser. Report only: never changes markup.
 */
final class Check_Contrast extends Check_Base {

	const NORMAL_RATIO = 4.5;
	const LARGE_RATIO  = 3.0;

	/**
	 * Large text in CSS pixels: 18pt, or 14pt when bold.
	 */
	const LARGE_PX      = 24.0;
	const LARGE_BOLD_PX = 18.66;

	/**
	 * Pixels per unit for the font sizes that can be known from the markup
	 * (rem against the browser's default 16px root).
	 */
	const UNITS = array(
		'px'  => 1.0,
		'pt'  => 1.3333333333,
		'rem' => 16.0,
	);

	/**
	 * Elements without text content.
	 */
	const EMPTY_ELEMENTS = array(
		'AREA'   => true,
		'BR'     => true,
		'COL'    => true,
		'EMBED'  => true,
		'HR'     => true,
		'IMG'    => true,
		'LINK'   => true,
		'META'   => true,
		'SOURCE' => true,
		'TRACK'  => true,
		'WBR'    => true,
		'IFRAME' => true,
		'SVG'    => true,
		'VIDEO'  => true,
		'AUDIO'  => true,
		'CANVAS' => true,
		'STYLE'  => true,
		'SCRIPT' => true,
	);

	/**
	 * Input types that show no text.
	 */
	const NO_TEXT_INPUTS = array(
		'hidden'   => true,
		'image'    => true,
		'checkbox' => true,
		'radio'    => true,
		'range'    => true,
		'color'    => true,
		'file'     => true,
	);

	/**
	 * CSS named colours (CSS Color Module Level 4).
	 */
	const NAMED = array(
		'aliceblue'            => 'f0f8ff',
		'antiquewhite'         => 'faebd7',
		'aqua'                 => '00ffff',
		'aquamarine'           => '7fffd4',
		'azure'                => 'f0ffff',
		'beige'                => 'f5f5dc',
		'bisque'               => 'ffe4c4',
		'black'                => '000000',
		'blanchedalmond'       => 'ffebcd',
		'blue'                 => '0000ff',
		'blueviolet'           => '8a2be2',
		'brown'                => 'a52a2a',
		'burlywood'            => 'deb887',
		'cadetblue'            => '5f9ea0',
		'chartreuse'           => '7fff00',
		'chocolate'            => 'd2691e',
		'coral'                => 'ff7f50',
		'cornflowerblue'       => '6495ed',
		'cornsilk'             => 'fff8dc',
		'crimson'              => 'dc143c',
		'cyan'                 => '00ffff',
		'darkblue'             => '00008b',
		'darkcyan'             => '008b8b',
		'darkgoldenrod'        => 'b8860b',
		'darkgray'             => 'a9a9a9',
		'darkgreen'            => '006400',
		'darkgrey'             => 'a9a9a9',
		'darkkhaki'            => 'bdb76b',
		'darkmagenta'          => '8b008b',
		'darkolivegreen'       => '556b2f',
		'darkorange'           => 'ff8c00',
		'darkorchid'           => '9932cc',
		'darkred'              => '8b0000',
		'darksalmon'           => 'e9967a',
		'darkseagreen'         => '8fbc8f',
		'darkslateblue'        => '483d8b',
		'darkslategray'        => '2f4f4f',
		'darkslategrey'        => '2f4f4f',
		'darkturquoise'        => '00ced1',
		'darkviolet'           => '9400d3',
		'deeppink'             => 'ff1493',
		'deepskyblue'          => '00bfff',
		'dimgray'              => '696969',
		'dimgrey'              => '696969',
		'dodgerblue'           => '1e90ff',
		'firebrick'            => 'b22222',
		'floralwhite'          => 'fffaf0',
		'forestgreen'          => '228b22',
		'fuchsia'              => 'ff00ff',
		'gainsboro'            => 'dcdcdc',
		'ghostwhite'           => 'f8f8ff',
		'gold'                 => 'ffd700',
		'goldenrod'            => 'daa520',
		'gray'                 => '808080',
		'green'                => '008000',
		'greenyellow'          => 'adff2f',
		'grey'                 => '808080',
		'honeydew'             => 'f0fff0',
		'hotpink'              => 'ff69b4',
		'indianred'            => 'cd5c5c',
		'indigo'               => '4b0082',
		'ivory'                => 'fffff0',
		'khaki'                => 'f0e68c',
		'lavender'             => 'e6e6fa',
		'lavenderblush'        => 'fff0f5',
		'lawngreen'            => '7cfc00',
		'lemonchiffon'         => 'fffacd',
		'lightblue'            => 'add8e6',
		'lightcoral'           => 'f08080',
		'lightcyan'            => 'e0ffff',
		'lightgoldenrodyellow' => 'fafad2',
		'lightgray'            => 'd3d3d3',
		'lightgreen'           => '90ee90',
		'lightgrey'            => 'd3d3d3',
		'lightpink'            => 'ffb6c1',
		'lightsalmon'          => 'ffa07a',
		'lightseagreen'        => '20b2aa',
		'lightskyblue'         => '87cefa',
		'lightslategray'       => '778899',
		'lightslategrey'       => '778899',
		'lightsteelblue'       => 'b0c4de',
		'lightyellow'          => 'ffffe0',
		'lime'                 => '00ff00',
		'limegreen'            => '32cd32',
		'linen'                => 'faf0e6',
		'magenta'              => 'ff00ff',
		'maroon'               => '800000',
		'mediumaquamarine'     => '66cdaa',
		'mediumblue'           => '0000cd',
		'mediumorchid'         => 'ba55d3',
		'mediumpurple'         => '9370db',
		'mediumseagreen'       => '3cb371',
		'mediumslateblue'      => '7b68ee',
		'mediumspringgreen'    => '00fa9a',
		'mediumturquoise'      => '48d1cc',
		'mediumvioletred'      => 'c71585',
		'midnightblue'         => '191970',
		'mintcream'            => 'f5fffa',
		'mistyrose'            => 'ffe4e1',
		'moccasin'             => 'ffe4b5',
		'navajowhite'          => 'ffdead',
		'navy'                 => '000080',
		'oldlace'              => 'fdf5e6',
		'olive'                => '808000',
		'olivedrab'            => '6b8e23',
		'orange'               => 'ffa500',
		'orangered'            => 'ff4500',
		'orchid'               => 'da70d6',
		'palegoldenrod'        => 'eee8aa',
		'palegreen'            => '98fb98',
		'paleturquoise'        => 'afeeee',
		'palevioletred'        => 'db7093',
		'papayawhip'           => 'ffefd5',
		'peachpuff'            => 'ffdab9',
		'peru'                 => 'cd853f',
		'pink'                 => 'ffc0cb',
		'plum'                 => 'dda0dd',
		'powderblue'           => 'b0e0e6',
		'purple'               => '800080',
		'rebeccapurple'        => '663399',
		'red'                  => 'ff0000',
		'rosybrown'            => 'bc8f8f',
		'royalblue'            => '4169e1',
		'saddlebrown'          => '8b4513',
		'salmon'               => 'fa8072',
		'sandybrown'           => 'f4a460',
		'seagreen'             => '2e8b57',
		'seashell'             => 'fff5ee',
		'sienna'               => 'a0522d',
		'silver'               => 'c0c0c0',
		'skyblue'              => '87ceeb',
		'slateblue'            => '6a5acd',
		'slategray'            => '708090',
		'slategrey'            => '708090',
		'snow'                 => 'fffafa',
		'springgreen'          => '00ff7f',
		'steelblue'            => '4682b4',
		'tan'                  => 'd2b48c',
		'teal'                 => '008080',
		'thistle'              => 'd8bfd8',
		'tomato'               => 'ff6347',
		'turquoise'            => '40e0d0',
		'violet'               => 'ee82ee',
		'wheat'                => 'f5deb3',
		'white'                => 'ffffff',
		'whitesmoke'           => 'f5f5f5',
		'yellow'               => 'ffff00',
		'yellowgreen'          => '9acd32',
	);

	/**
	 * Preset colours (slug => array( r, g, b ) or null when not a plain
	 * opaque colour) and font sizes (slug => px or null when not known),
	 * read once per request from the global settings, or injected.
	 *
	 * @var array|null
	 */
	private $presets;

	/**
	 * Findings waiting for finish(): signature, detail.
	 *
	 * @var array[]
	 */
	private $found = array();

	/**
	 * Creates the check.
	 *
	 * @param array|null $presets Optional array( 'colors' => slug => CSS colour, 'sizes' => slug => px or null ),
	 *                            used instead of the global settings (tests).
	 */
	public function __construct( $presets = null ) {
		$this->presets = is_array( $presets ) ? self::normalise_presets( $presets ) : null;
	}

	/**
	 * Report id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'R01';
	}

	/**
	 * WCAG success criterion.
	 *
	 * @return string
	 */
	public function wcag(): string {
		return '1.4.3';
	}

	/**
	 * Openers that carry a style or class attribute (colours can be on any element).
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( Fix_Base::WITH_ATTRIBUTE . 'style', Fix_Base::WITH_ATTRIBUTE . 'class' );
	}

	/**
	 * Forgets the previous document (the presets belong to the request).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->found = array();
	}

	/**
	 * Notes an element whose declared colour pair falls below its threshold.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Recorder $recorder ): void {
		if ( $token->is_closer || isset( self::EMPTY_ELEMENTS[ $token->name ] ) || null !== $token->attr( 'hidden' ) ) {
			return;
		}
		if ( 'INPUT' === $token->name && isset( self::NO_TEXT_INPUTS[ strtolower( $token->attr_text( 'type' ) ) ] ) ) {
			return;
		}
		$class = strtolower( $token->attr_text( 'class' ) );
		$style = $token->attr_text( 'style' );
		// Cheap test first: no colour can be declared without one of these.
		if ( false === strpos( $style, 'color' ) && false === strpos( $style, 'background' ) && false === strpos( $class, '-color' ) ) {
			return;
		}

		$pair = $this->declared_pair( $class, $style );
		if ( null === $pair ) {
			return;
		}
		$ratio = self::ratio( $pair['color'], $pair['background'] );
		$large = $pair['large'];
		if ( $ratio >= self::NORMAL_RATIO || ( true === $large && $ratio >= self::LARGE_RATIO ) ) {
			return;
		}

		$this->found[] = array(
			$recorder->is_recording() ? $token->signature() : '',
			self::detail( $ratio, $pair['color'], $pair['background'], $large ),
		);
	}

	/**
	 * Reports what was found, in document order.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function finish( Document_Index $index, Recorder $recorder ): void {
		foreach ( $this->found as $found ) {
			$this->report( $recorder, $found[0], $found[1] );
		}
		$this->found = array();
	}

	/**
	 * The text and background colours an element declares itself, with what
	 * is known about its text size; null when either colour is not declared
	 * or cannot be known without a browser.
	 *
	 * @param string $class_attr Lower-case class attribute.
	 * @param string $style      Style attribute.
	 * @return array|null Keys color and background (array( r, g, b )), large (true, false, or null when unknown).
	 */
	public function declared_pair( string $class_attr, string $style ) {
		$declarations = self::declarations( $style );
		$classes      = '' === $class_attr ? array() : (array) preg_split( '/\s+/', $class_attr, -1, PREG_SPLIT_NO_EMPTY );
		$presets      = $this->presets();

		// Anything that blends the element with what is behind it needs a browser.
		if ( isset( $declarations['opacity'] ) && ( ! is_numeric( $declarations['opacity'] ) || (float) $declarations['opacity'] < 1 ) ) {
			return null;
		}
		foreach ( array( 'filter', 'mix-blend-mode', 'background-image', 'background-blend-mode', '-webkit-text-fill-color' ) as $property ) {
			if ( isset( $declarations[ $property ] ) && ! in_array( strtolower( $declarations[ $property ] ), array( 'none', 'normal', 'initial', 'unset' ), true ) ) {
				return null;
			}
		}

		$color      = false;
		$background = false;
		$size       = null;
		$size_class = false;
		foreach ( $classes as $name ) {
			$name = (string) $name;
			if ( '-gradient-background' === substr( $name, -20 ) && 0 === strpos( $name, 'has-' ) ) {
				return null;
			}
			if ( 0 !== strpos( $name, 'has-' ) ) {
				continue;
			}
			if ( '-background-color' === substr( $name, -17 ) ) {
				$slug = substr( $name, 4, -17 );
				if ( array_key_exists( $slug, $presets['colors'] ) ) {
					$background = $presets['colors'][ $slug ];
				}
			} elseif ( '-font-size' === substr( $name, -10 ) ) {
				$slug = substr( $name, 4, -10 );
				if ( array_key_exists( $slug, $presets['sizes'] ) ) {
					$size       = $presets['sizes'][ $slug ];
					$size_class = true;
				}
			} elseif ( '-color' === substr( $name, -6 ) && '-border-color' !== substr( $name, -13 ) ) {
				$slug = substr( $name, 4, -6 );
				if ( array_key_exists( $slug, $presets['colors'] ) ) {
					$color = $presets['colors'][ $slug ];
				}
			}
		}

		// The inline background: the later of background-color and the background shorthand wins.
		$inline_background = false;
		foreach ( $declarations as $property => $value ) {
			if ( 'background-color' === $property ) {
				$inline_background = $this->parse_color( $value );
			} elseif ( 'background' === $property ) {
				$shorthand = $this->background_shorthand( $value );
				if ( null === $shorthand ) {
					return null;
				}
				// A shorthand without a colour resets the background colour to transparent.
				$inline_background = false === $shorthand ? null : $shorthand;
			}
		}

		// Preset classes carry !important in core's stylesheet, so they win over inline colours and sizes.
		if ( false === $color && isset( $declarations['color'] ) ) {
			$color = $this->parse_color( $declarations['color'] );
		}
		if ( false === $background ) {
			$background = $inline_background;
		}
		if ( ! is_array( $color ) || ! is_array( $background ) ) {
			return null;
		}

		if ( ! $size_class && isset( $declarations['font-size'] ) ) {
			$size = self::font_size_px( $declarations['font-size'] );
		}
		$bold  = isset( $declarations['font-weight'] ) && self::is_bold( $declarations['font-weight'] );
		$large = null;
		if ( null !== $size ) {
			$large = $size >= self::LARGE_PX || ( $bold && $size >= self::LARGE_BOLD_PX );
		}

		return array(
			'color'      => $color,
			'background' => $background,
			'large'      => $large,
		);
	}

	/**
	 * WCAG 2 contrast ratio of two opaque sRGB colours (1 to 21).
	 *
	 * @param int[] $first  array( r, g, b ), 0-255.
	 * @param int[] $second array( r, g, b ), 0-255.
	 * @return float
	 */
	public static function ratio( array $first, array $second ): float {
		$a = self::luminance( $first );
		$b = self::luminance( $second );
		return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
	}

	/**
	 * WCAG 2 relative luminance of an sRGB colour.
	 *
	 * @param int[] $rgb array( r, g, b ), 0-255.
	 * @return float
	 */
	public static function luminance( array $rgb ): float {
		$linear = array();
		foreach ( array_slice( array_values( $rgb ), 0, 3 ) as $channel ) {
			$c        = max( 0, min( 255, (int) $channel ) ) / 255;
			$linear[] = $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	}

	/**
	 * An opaque CSS colour as array( r, g, b ), or null when it is not a
	 * plain opaque colour (transparency, currentColor, keywords, functions
	 * other than rgb() and hsl(), variables outside the preset palette).
	 *
	 * @param string $value CSS colour value.
	 * @return int[]|null
	 */
	public function parse_color( string $value ) {
		$value = strtolower( trim( (string) preg_replace( '/\s*!important\s*$/i', '', $value ) ) );
		if ( 1 === preg_match( '/^var\(\s*--wp--preset--color--([a-z0-9-]+)\s*\)$/', $value, $match ) ) {
			$presets = $this->presets();
			return isset( $presets['colors'][ $match[1] ] ) ? $presets['colors'][ $match[1] ] : null;
		}
		return self::parse_plain_color( $value );
	}

	/**
	 * A colour value that needs no palette.
	 *
	 * @param string $value Lower-case, trimmed CSS colour value.
	 * @return int[]|null
	 */
	public static function parse_plain_color( string $value ) {
		if ( isset( self::NAMED[ $value ] ) ) {
			$value = '#' . self::NAMED[ $value ];
		}
		if ( 1 === preg_match( '/^#([0-9a-f]{3,8})$/', $value, $match ) ) {
			$hex = $match[1];
			if ( 3 === strlen( $hex ) || 4 === strlen( $hex ) ) {
				if ( 4 === strlen( $hex ) && 'f' !== $hex[3] ) {
					return null;
				}
				return array( hexdec( $hex[0] . $hex[0] ), hexdec( $hex[1] . $hex[1] ), hexdec( $hex[2] . $hex[2] ) );
			}
			if ( 6 === strlen( $hex ) || ( 8 === strlen( $hex ) && 'ff' === substr( $hex, 6, 2 ) ) ) {
				return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
			}
			return null;
		}
		if ( 1 !== preg_match( '/^(rgba?|hsla?)\(\s*([^()]*)\)$/', $value, $match ) ) {
			return null;
		}
		$parts = preg_split( '/\s*[,\/]\s*|\s+/', trim( $match[2] ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) || count( $parts ) < 3 || count( $parts ) > 4 ) {
			return null;
		}
		if ( 4 === count( $parts ) ) {
			$alpha = self::number( (string) $parts[3], 1.0 );
			if ( null === $alpha || $alpha < 1 ) {
				return null;
			}
		}
		if ( 'r' === $match[1][0] ) {
			$rgb = array();
			foreach ( array_slice( $parts, 0, 3 ) as $part ) {
				$channel = self::number( (string) $part, 255.0 );
				if ( null === $channel ) {
					return null;
				}
				$rgb[] = (int) round( max( 0, min( 255, $channel ) ) );
			}
			return $rgb;
		}
		return self::hsl_to_rgb( (string) $parts[0], (string) $parts[1], (string) $parts[2] );
	}

	/**
	 * Font size in CSS pixels when the unit is known (px, pt, rem), else null.
	 *
	 * @param string $value CSS font-size value.
	 * @return float|null
	 */
	public static function font_size_px( string $value ) {
		$value = strtolower( trim( (string) preg_replace( '/\s*!important\s*$/i', '', $value ) ) );
		if ( 1 !== preg_match( '/^(\d+(?:\.\d+)?|\.\d+)(px|pt|rem)$/', $value, $match ) ) {
			return null;
		}
		return (float) $match[1] * self::UNITS[ $match[2] ];
	}

	/**
	 * Whether a font-weight value is bold (700 or more).
	 *
	 * @param string $value CSS font-weight value.
	 * @return bool
	 */
	public static function is_bold( string $value ): bool {
		$value = strtolower( trim( (string) preg_replace( '/\s*!important\s*$/i', '', $value ) ) );
		return 'bold' === $value || 'bolder' === $value || ( is_numeric( $value ) && (float) $value >= 700 );
	}

	/**
	 * Declarations of a style attribute, lower-case property => value, in the
	 * order they take effect (a repeated property counts once, at its last place).
	 *
	 * @param string $style Style attribute.
	 * @return string[]
	 */
	public static function declarations( string $style ): array {
		$found = array();
		$depth = 0;
		$quote = '';
		$start = 0;
		$len   = strlen( $style );
		for ( $i = 0; $i <= $len; $i++ ) {
			$char = $i < $len ? $style[ $i ] : ';';
			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ';' === $char && 0 === $depth ) {
				$declaration = substr( $style, $start, $i - $start );
				$start       = $i + 1;
				$colon       = strpos( $declaration, ':' );
				if ( false === $colon ) {
					continue;
				}
				$property = strtolower( trim( substr( $declaration, 0, $colon ) ) );
				$value    = trim( substr( $declaration, $colon + 1 ) );
				if ( '' !== $property && '' !== $value ) {
					// The last declaration wins and takes the last place, so callers can follow the order.
					unset( $found[ $property ] );
					$found[ $property ] = $value;
				}
			}
		}
		return $found;
	}

	/**
	 * The colour of a background shorthand without an image, false when it
	 * sets no colour, null when it holds an image, a gradient or a colour
	 * that cannot be known.
	 *
	 * @param string $value Background shorthand value.
	 * @return int[]|false|null
	 */
	private function background_shorthand( string $value ) {
		$value = strtolower( trim( (string) preg_replace( '/\s*!important\s*$/i', '', $value ) ) );
		if ( 1 === preg_match( '/url\(|gradient\(|image-set\(|image\(|element\(|cross-fade\(/', $value ) ) {
			return null;
		}
		if ( 'none' === $value ) {
			return false;
		}
		// A single colour value (a colour function may contain spaces).
		$single = $this->parse_color( $value );
		if ( null !== $single ) {
			return $single;
		}
		$color = false;
		foreach ( (array) preg_split( '/\s+(?![^()]*\))/', $value, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			$parsed = $this->parse_color( (string) $part );
			if ( null !== $parsed ) {
				if ( false !== $color ) {
					return null;
				}
				$color = $parsed;
			} elseif ( 1 === preg_match( '/^(transparent|currentcolor|var\(|color-mix\(|inherit|initial|unset|revert)/', (string) $part ) ) {
				return null;
			}
		}
		return $color;
	}

	/**
	 * A number, a percentage of $full, or null.
	 *
	 * @param string $part Number or percentage.
	 * @param float  $full What a full percentage stands for (255 for a colour channel, 1 for alpha).
	 * @return float|null
	 */
	private static function number( string $part, float $full ) {
		if ( 1 !== preg_match( '/^(-?(?:\d+(?:\.\d+)?|\.\d+))(%?)$/', $part, $match ) ) {
			return null;
		}
		return '%' === $match[2] ? (float) $match[1] / 100 * $full : (float) $match[1];
	}

	/**
	 * Converts hsl() parts to array( r, g, b ).
	 *
	 * @param string $hue        Hue (deg, turn or a number of degrees).
	 * @param string $saturation Saturation percentage.
	 * @param string $lightness  Lightness percentage.
	 * @return int[]|null
	 */
	private static function hsl_to_rgb( string $hue, string $saturation, string $lightness ) {
		if ( 1 !== preg_match( '/^(-?(?:\d+(?:\.\d+)?|\.\d+))(deg|turn)?$/', $hue, $match ) ) {
			return null;
		}
		$h = (float) $match[1] * ( isset( $match[2] ) && 'turn' === $match[2] ? 360 : 1 );
		$h = fmod( fmod( $h, 360 ) + 360, 360 ) / 360;
		$s = self::number( $saturation, 1.0 );
		$l = self::number( $lightness, 1.0 );
		if ( null === $s || null === $l || false === strpos( $saturation, '%' ) || false === strpos( $lightness, '%' ) ) {
			return null;
		}
		$s   = max( 0.0, min( 1.0, $s ) );
		$l   = max( 0.0, min( 1.0, $l ) );
		$q   = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
		$p   = 2 * $l - $q;
		$rgb = array();
		foreach ( array( $h + 1 / 3, $h, $h - 1 / 3 ) as $t ) {
			$t     = $t < 0 ? $t + 1 : ( $t > 1 ? $t - 1 : $t );
			$value = $t < 1 / 6 ? $p + ( $q - $p ) * 6 * $t : ( $t < 0.5 ? $q : ( $t < 2 / 3 ? $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6 : $p ) );
			$rgb[] = (int) round( $value * 255 );
		}
		return $rgb;
	}

	/**
	 * The report line for a failing pair.
	 *
	 * @param float     $ratio      Contrast ratio.
	 * @param int[]     $color      Text colour.
	 * @param int[]     $background Background colour.
	 * @param bool|null $large      Whether the text is large; null when its size is not declared.
	 * @return string
	 */
	private static function detail( float $ratio, array $color, array $background, $large ): string {
		$needed = true === $large ? self::LARGE_RATIO : self::NORMAL_RATIO;
		$shown  = round( $ratio, 2 );
		if ( $shown >= $needed ) {
			// Never show a failing ratio rounded up to the threshold.
			$shown = floor( $ratio * 100 ) / 100;
		}
		$shown = number_format_i18n( $shown, 2 );
		$pair  = self::hex( $color ) . ' / ' . self::hex( $background );
		if ( true === $large ) {
			/* translators: 1: contrast ratio such as 2.85, 2: text colour / background colour, for example #999999 / #ffffff */
			return sprintf( __( 'Contrast %1$s:1 (%2$s) is below 3:1 for large text.', 'tagmend-accessibility-fixes' ), $shown, $pair );
		}
		if ( false === $large ) {
			/* translators: 1: contrast ratio such as 4.48, 2: text colour / background colour, for example #777777 / #ffffff */
			return sprintf( __( 'Contrast %1$s:1 (%2$s) is below 4.5:1 for normal-size text.', 'tagmend-accessibility-fixes' ), $shown, $pair );
		}
		if ( $ratio < self::LARGE_RATIO ) {
			/* translators: 1: contrast ratio such as 2.85, 2: text colour / background colour, for example #999999 / #ffffff */
			return sprintf( __( 'Contrast %1$s:1 (%2$s) is below 4.5:1 for normal text and 3:1 for large text.', 'tagmend-accessibility-fixes' ), $shown, $pair );
		}
		/* translators: 1: contrast ratio such as 3.95, 2: text colour / background colour, for example #777777 / #ffffff */
		return sprintf( __( 'Contrast %1$s:1 (%2$s) is below 4.5:1 for normal text; large text needs 3:1, and no size is set here.', 'tagmend-accessibility-fixes' ), $shown, $pair );
	}

	/**
	 * A colour as #rrggbb.
	 *
	 * @param int[] $rgb array( r, g, b ).
	 * @return string
	 */
	private static function hex( array $rgb ): string {
		return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
	}

	/**
	 * The presets of this request.
	 *
	 * @return array
	 */
	private function presets(): array {
		if ( null === $this->presets ) {
			$this->presets = self::normalise_presets( self::global_presets() );
		}
		return $this->presets;
	}

	/**
	 * Colour and font-size presets from the global settings (theme.json):
	 * custom over theme over default, as core prints their CSS variables.
	 * Fluid font sizes count only when a minimum is given (the smallest size
	 * a visitor can see), otherwise their size is not known.
	 *
	 * @return array array( 'colors' => slug => CSS colour, 'sizes' => slug => px or null ).
	 */
	private static function global_presets(): array {
		$presets = array(
			'colors' => array(),
			'sizes'  => array(),
		);
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return $presets;
		}
		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		$sizes   = wp_get_global_settings( array( 'typography', 'fontSizes' ) );
		$fluid   = wp_get_global_settings( array( 'typography', 'fluid' ) );
		$fluid   = ! empty( $fluid );

		foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
			foreach ( is_array( $palette ) && isset( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ? $palette[ $origin ] : array() as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'], $entry['color'] ) && is_string( $entry['slug'] ) && is_string( $entry['color'] ) ) {
					$presets['colors'][ self::class_slug( $entry['slug'] ) ] = $entry['color'];
				}
			}
			foreach ( is_array( $sizes ) && isset( $sizes[ $origin ] ) && is_array( $sizes[ $origin ] ) ? $sizes[ $origin ] : array() as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['slug'], $entry['size'] ) || ! is_string( $entry['slug'] ) || ! is_string( $entry['size'] ) ) {
					continue;
				}
				$entry_fluid = isset( $entry['fluid'] ) ? $entry['fluid'] : null;
				if ( is_array( $entry_fluid ) && isset( $entry_fluid['min'] ) && is_string( $entry_fluid['min'] ) ) {
					$px = self::font_size_px( $entry_fluid['min'] );
				} elseif ( false === $entry_fluid || ! $fluid ) {
					$px = self::font_size_px( $entry['size'] );
				} else {
					$px = null;
				}
				$presets['sizes'][ self::class_slug( $entry['slug'] ) ] = $px;
			}
		}
		return $presets;
	}

	/**
	 * Parses preset colours once.
	 *
	 * @param array $presets array( 'colors' => slug => CSS colour, 'sizes' => slug => px or null ).
	 * @return array
	 */
	private static function normalise_presets( array $presets ): array {
		$normal = array(
			'colors' => array(),
			'sizes'  => array(),
		);
		foreach ( isset( $presets['colors'] ) && is_array( $presets['colors'] ) ? $presets['colors'] : array() as $slug => $color ) {
			$normal['colors'][ (string) $slug ] = is_string( $color ) ? self::parse_plain_color( strtolower( trim( $color ) ) ) : null;
		}
		foreach ( isset( $presets['sizes'] ) && is_array( $presets['sizes'] ) ? $presets['sizes'] : array() as $slug => $px ) {
			$normal['sizes'][ (string) $slug ] = is_numeric( $px ) ? (float) $px : null;
		}
		return $normal;
	}

	/**
	 * The slug as it appears in the block editor's class names: kebab case,
	 * words split at case changes and between letters and digits, as core
	 * does for preset classes and CSS variables.
	 *
	 * @param string $slug Preset slug.
	 * @return string
	 */
	private static function class_slug( string $slug ): string {
		$slug = (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $slug );
		$slug = (string) preg_replace( '/([A-Za-z])([0-9])/', '$1-$2', $slug );
		$slug = (string) preg_replace( '/([0-9])(?!(?:st|nd|rd|th)(?:[^a-z]|$))([A-Za-z])/', '$1-$2', $slug );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $slug ) ), '-' );
	}
}
