<?php
/**
 * Test doubles for the engine suites: fixes that throw, fixes that touch
 * every element, a processor that tampers with its output, an engine
 * whose processors can be swapped per pass, a Media Library lookup without
 * a database and WooCommerce-free stand-ins for F04.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Accessible_Name;
use SoftGlaze\AccessibilityRepair\Attachment_Lookup;
use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Product_Img_Alt_Name;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

/**
 * The Media Library of the dev site (tests/fixtures/staging-pages.md),
 * answered from memory: the same alt texts and files as attachments 6 to 11
 * there, so golden files made on the dev site match. Counts its batches.
 */
class Softglaze_A11y_Test_Attachment_Lookup extends Attachment_Lookup {

	/**
	 * The dev site's fixture attachments: id => array( alt, attached file ).
	 */
	const DEV_SITE = array(
		6  => array( 'Butter croissant on a wooden board', '2026/10/a11y-fx-croissant.jpg' ),
		7  => array( 'Three baguettes cooling on a rack', '2026/10/a11y-fx-baguette.jpg' ),
		8  => array( 'Round sourdough loaf with a floured crust', '2026/10/a11y-fx-sourdough.jpg' ),
		9  => array( 'Three-layer chocolate cake', '2026/10/a11y-fx-cake.png' ),
		10 => array( 'Cinnamon rolls in a baking tin', '2026/10/a11y-fx-cinnamon-rolls.jpg' ),
		11 => array( '', '2026/10/bakery-oven.webm' ),
	);

	/**
	 * Id => array( alt, file ).
	 *
	 * @var array
	 */
	public $library;

	/**
	 * Id lists passed to each batch.
	 *
	 * @var array
	 */
	public $batches = array();

	/**
	 * URLs passed to the URL query.
	 *
	 * @var string[]
	 */
	public $urls = array();

	/**
	 * Creates the double. It may answer URL lookups (as in an authenticated
	 * request) and keeps no saved map of its own.
	 *
	 * @param array|null $library Id => array( alt, file ); the dev site's attachments when null.
	 */
	public function __construct( $library = null ) {
		parent::__construct( true );
		$this->library = null === $library ? self::DEV_SITE : $library;
	}

	/**
	 * No saved map: every answer comes from the library above.
	 *
	 * @return int[]
	 */
	protected function map(): array {
		return array();
	}

	/**
	 * Answers a batch from memory.
	 *
	 * @param int[] $ids Ids.
	 * @return array
	 */
	protected function read_attachments( array $ids ): array {
		$this->batches[] = $ids;
		$found           = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->library[ $id ] ) ) {
				$found[ $id ] = array(
					'alt'  => $this->library[ $id ][0],
					'file' => $this->library[ $id ][1],
				);
			}
		}
		return $found;
	}

	/**
	 * Finds an attachment by the file part of a URL.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	protected function query_url( string $url ): int {
		$this->urls[] = $url;
		foreach ( $this->library as $id => $entry ) {
			if ( '/' . $entry[1] === substr( $url, -strlen( $entry[1] ) - 1 ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * The dev site's uploads folder.
	 *
	 * @return string
	 */
	protected function uploads_base(): string {
		return '//localhost:8730/wp-content/uploads';
	}
}

/**
 * F04 without WooCommerce: the product of the page is given, and the loop flag is settable.
 */
class Softglaze_A11y_Test_Product_Fix extends Fix_Product_Img_Alt_Name {

	/**
	 * Product: id, name, image_ids; null for "not a single product page".
	 *
	 * @var array|null
	 */
	public $product;

	/**
	 * Whether the image is printed inside a product loop.
	 *
	 * @var bool
	 */
	public $in_loop = false;

	/**
	 * Creates the double.
	 *
	 * @param array|null $product Product.
	 */
	public function __construct( $product = null ) {
		$this->product = $product;
	}

	/**
	 * The given product.
	 *
	 * @return array|null
	 */
	protected function current_product() {
		return $this->product;
	}

	/**
	 * The given loop flag.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	protected function in_product_loop( int $product_id ): bool {
		return $this->in_loop;
	}
}

/**
 * F04 whose attribute callback throws (FS-14).
 */
class Softglaze_A11y_Test_Throwing_Product_Fix extends Fix_Product_Img_Alt_Name {

	/**
	 * Throws.
	 *
	 * @param array $attr       Attributes.
	 * @param mixed $attachment Attachment.
	 * @return array
	 * @throws RuntimeException Always.
	 */
	public function filter_attributes( array $attr, $attachment ): array {
		throw new RuntimeException( 'product' );
	}
}

/**
 * Common metadata for the test fixes.
 */
abstract class Softglaze_A11y_Test_Fix extends Fix_Base {

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Test fix';
	}

	/**
	 * WCAG.
	 *
	 * @return array
	 */
	public function wcag(): array {
		return array();
	}

	/**
	 * Axe rules.
	 *
	 * @return array
	 */
	public function axe_rules(): array {
		return array();
	}

	/**
	 * Scopes.
	 *
	 * @return array
	 */
	public function scopes(): array {
		return array( 'content', 'page' );
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
	 * Recommended.
	 *
	 * @return bool
	 */
	public function recommended(): bool {
		return true;
	}
}

/**
 * Throws a configurable throwable on the first iframe.
 */
class Softglaze_A11y_Test_Fix_Throws extends Softglaze_A11y_Test_Fix {

	/**
	 * 'runtime', 'type' or 'error'.
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * Creates the fix.
	 *
	 * @param string $kind What to throw.
	 */
	public function __construct( $kind = 'runtime' ) {
		$this->kind = $kind;
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'test-throws';
	}

	/**
	 * Tags.
	 *
	 * @return array
	 */
	public function tags(): array {
		return array( 'IFRAME' );
	}

	/**
	 * Throws.
	 *
	 * @param Token          $token    Token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @throws RuntimeException Always, for kind 'runtime'.
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( 'type' === $this->kind ) {
			$typed = static function ( int $number ) {
				return $number;
			};
			$typed( 'not a number' );
		}
		if ( 'error' === $this->kind ) {
			$missing = 'softglaze_a11y_function_that_does_not_exist';
			$missing();
		}
		throw new RuntimeException( 'test' );
	}
}

/**
 * Plans title="t" on every opener (or only the first), to force the apply pass.
 */
class Softglaze_A11y_Test_Fix_Title_All extends Softglaze_A11y_Test_Fix {

	/**
	 * Only the first opener.
	 *
	 * @var bool
	 */
	public $first_only;

	/**
	 * Value to set.
	 *
	 * @var string
	 */
	public $value;

	/**
	 * Creates the fix.
	 *
	 * @param bool   $first_only Only the first opener.
	 * @param string $value      Title value.
	 */
	public function __construct( $first_only = false, $value = 't' ) {
		$this->first_only = $first_only;
		$this->value      = $value;
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'test-title-all';
	}

	/**
	 * Runs after the bundled fixes.
	 *
	 * @return int
	 */
	public function priority(): int {
		return 50;
	}

	/**
	 * Plans the title.
	 *
	 * @param Token          $token    Token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( $token->is_closer || ( $this->first_only && 0 !== $token->ordinal ) ) {
			return;
		}
		$this->plan_edit( $token, $plan, $recorder, 'title', $this->value );
	}
}

/**
 * Records what the analyse pass hands to fixes for links: collected text and context.
 */
class Softglaze_A11y_Test_Fix_Spy extends Softglaze_A11y_Test_Fix {

	/**
	 * Observations.
	 *
	 * @var array
	 */
	public $seen = array();

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'test-spy';
	}

	/**
	 * Tags.
	 *
	 * @return array
	 */
	public function tags(): array {
		return array( 'A', 'H2', 'FORM' );
	}

	/**
	 * Clears observations.
	 */
	public function reset(): void {
		$this->seen = array();
	}

	/**
	 * Records closers.
	 *
	 * @param Token          $token    Token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( ! $token->is_closer ) {
			return;
		}
		$this->seen[] = array(
			'tag'     => $token->name,
			'ordinal' => $token->ordinal,
			'text'    => $token->element->normalized_text(),
			'hidden'  => $token->inside_aria_hidden() || $token->element->aria_hidden,
			'in_form' => null !== $token->closest( 'FORM' ),
		);
	}
}

/**
 * A processor whose updated HTML gains a suffix.
 */
class Softglaze_A11y_Test_Tampering_Processor extends WP_HTML_Tag_Processor {

	/**
	 * Appended to the output.
	 *
	 * @var string
	 */
	public $suffix = '<';

	/**
	 * Output with the suffix.
	 *
	 * @return string
	 */
	public function get_updated_html(): string {
		return parent::get_updated_html() . $this->suffix;
	}
}

/**
 * An engine whose apply-pass processor can be replaced, and which logs passes.
 */
class Softglaze_A11y_Test_Engine extends Engine {

	/**
	 * Passes in the order processors were created.
	 *
	 * @var string[]
	 */
	public $passes = array();

	/**
	 * Builds the apply-pass processor from the input, or null for the normal one.
	 *
	 * @var callable|null
	 */
	public $apply_factory = null;

	/**
	 * Creates a processor, optionally a doctored one for the apply pass.
	 *
	 * @param string $html HTML.
	 * @param string $pass Pass.
	 * @return WP_HTML_Tag_Processor
	 */
	protected function create_processor( string $html, string $pass ): WP_HTML_Tag_Processor {
		$this->passes[] = $pass;
		if ( 'apply' === $pass && null !== $this->apply_factory ) {
			return call_user_func( $this->apply_factory, $html );
		}
		return parent::create_processor( $html, $pass );
	}
}

/**
 * Collects the resolved naming facts of a run, and what icon children see.
 */
class Softglaze_A11y_Test_Name_Probe extends Softglaze_A11y_Test_Fix {

	/**
	 * Naming facts of the last run.
	 *
	 * @var Accessible_Name[]
	 */
	public $names = array();

	/**
	 * Controls seen by i, span and svg openers through Token::closest_control().
	 *
	 * @var array
	 */
	public $controls = array();

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'test-name-probe';
	}

	/**
	 * Icon elements only.
	 *
	 * @return array
	 */
	public function tags(): array {
		return array( 'I', 'SPAN', 'SVG' );
	}

	/**
	 * Uses names.
	 *
	 * @return bool
	 */
	public function uses_names(): bool {
		return true;
	}

	/**
	 * Clears the previous run.
	 */
	public function reset(): void {
		$this->names    = array();
		$this->controls = array();
	}

	/**
	 * Records the control around icon openers.
	 *
	 * @param Token          $token    Token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( ! $token->is_closer ) {
			$this->controls[] = $token->closest_control();
		}
	}

	/**
	 * Keeps the resolved facts.
	 *
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		$this->names = $index->names();
	}
}
