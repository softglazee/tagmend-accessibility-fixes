<?php
/**
 * Hidden focus and duplicate ids (Phase 4B): F15, F16, F17 and the findings
 * R10, R11, R12, R13, on the hand-written edge page
 * tests/fixtures/edge/focus-ids.html (expected output made on the dev site
 * with tools/fixtures/make-expected.php) and on small cases; the engine's
 * dispatch of openers in aria-hidden content.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Checks\Check_Autoplay_Media;
use SoftGlaze\AccessibilityRepair\Checks\Check_Duplicate_Ids;
use SoftGlaze\AccessibilityRepair\Checks\Check_Non_Native_Controls;
use SoftGlaze\AccessibilityRepair\Checks\Check_Tables;
use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Aria_Hidden_Focus;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Duplicate_Field_Ids;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Hidden_Duplicate_Links;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Positive_Tabindex;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * A test fix that lists the openers it is shown (tags: P and the openers in aria-hidden content).
 */
class Softglaze_A11y_Test_Fix_Hidden_Probe extends Fix_Base {

	/**
	 * Openers seen, as "TAG#ordinal".
	 *
	 * @var string[]
	 */
	public $seen = array();

	/**
	 * Tags.
	 *
	 * @var string[]
	 */
	private $tag_list;

	/**
	 * Creates the probe.
	 *
	 * @param string[] $tag_list Tags.
	 */
	public function __construct( array $tag_list ) {
		$this->tag_list = $tag_list;
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'test-hidden-probe';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Probe';
	}

	/**
	 * WCAG.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array();
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array();
	}

	/**
	 * Scopes.
	 *
	 * @return string[]
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
		return false;
	}

	/**
	 * Tags.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return $this->tag_list;
	}

	/**
	 * Clears the list.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->seen = array();
	}

	/**
	 * Notes the opener.
	 *
	 * @param Token          $token    Token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( ! $token->is_closer ) {
			$this->seen[] = $token->name . '#' . $token->ordinal;
		}
	}
}

/**
 * Hidden focus and duplicate ids.
 */
class Test_Fixes_Focus_Ids extends Softglaze_A11y_Engine_TestCase {

	/**
	 * Every attribute change on the edge page with every fix Live, in document order.
	 *
	 * @var string[]
	 */
	const EDGE_DIFF = array(
		"A.tabindex: NULL -> '-1'",
		"A.tabindex: NULL -> '-1'",
		"A.tabindex: NULL -> '-1'",
		"A.tabindex: NULL -> '-1'",
		"BUTTON.tabindex: NULL -> '-1'",
		"INPUT.tabindex: NULL -> '-1'",
		"SPAN.tabindex: '0' -> '-1'",
		"IFRAME.tabindex: NULL -> '-1'",
		"A.tabindex: '2' -> '-1'",
		"A.tabindex: NULL -> '-1'",
		"LABEL.for: 'phone' -> 'phone-softglaze-a11y-2'",
		"INPUT.id: 'phone' -> 'phone-softglaze-a11y-2'",
		"LABEL.for: 'qty' -> 'qty-softglaze-a11y-2'",
		"INPUT.id: 'qty' -> 'qty-softglaze-a11y-2'",
		"BUTTON.aria-labelledby: 'add qty' -> 'add qty-softglaze-a11y-2'",
		"LABEL.for: 'phone' -> 'phone-softglaze-a11y-3'",
		"INPUT.id: 'phone' -> 'phone-softglaze-a11y-3'",
	);

	/**
	 * The edge page.
	 *
	 * @return string
	 */
	private static function edge() {
		return self::read( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/edge/focus-ids.html' );
	}

	/**
	 * A small whole document.
	 *
	 * @param string $body Body markup.
	 * @return string
	 */
	private static function page( $body ) {
		return '<!DOCTYPE html><html lang="en"><head><title>Page</title></head><body><main>' . $body . '</main></body></html>';
	}

	/**
	 * Token diff of some fixes, all Live, without checks.
	 *
	 * @param array  $fixes Fix objects.
	 * @param string $html  Document.
	 * @param string $scope Scope.
	 * @return string[]
	 */
	private static function diff( array $fixes, $html, $scope = Engine::SCOPE_PAGE ) {
		$states = array();
		foreach ( $fixes as $fix ) {
			$states[ $fix->id() ] = 'live';
		}
		$engine = new Engine( $fixes, $states );
		$output = $engine->run( $html, Engine::MODE_VISITOR, $scope );
		return self::token_diff( $html, $output );
	}

	/**
	 * Findings of a scan as "check sig sc: detail", limited to some checks.
	 *
	 * @param Engine   $engine Engine after a scan.
	 * @param string[] $checks Check ids to keep.
	 * @return string[]
	 */
	private static function findings( $engine, array $checks ) {
		$list = array();
		foreach ( $engine->recorder()->findings() as $finding ) {
			if ( in_array( $finding['check'], $checks, true ) ) {
				$list[] = $finding['check'] . ' ' . $finding['sig'] . ' ' . $finding['sc'] . ': ' . $finding['detail'];
			}
		}
		return $list;
	}

	/**
	 * Runs only some checks in a scan.
	 *
	 * @param array  $checks Check objects.
	 * @param string $html   Document.
	 * @param string $scope  Scope.
	 * @return Engine
	 */
	private static function scan( array $checks, $html, $scope = Engine::SCOPE_PAGE ) {
		$engine = new Engine( array(), array(), array( 'checks' => $checks ) );
		$engine->run( $html, Engine::MODE_SCAN, $scope );
		return $engine;
	}

	/**
	 * Output equals the file made on the dev site, byte for byte.
	 */
	public function test_edge_output_matches_expected_file() {
		$engine = self::engine( 'live' );
		$output = $engine->run( self::edge() );
		$this->assertNull( $engine->recorder()->get_fallback() );
		$this->assertSame( self::read( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/edge/focus-ids.expected.html' ), $output );
	}

	/**
	 * Only the intended attributes change. F15 takes the croissant, baguette
	 * and far Breton copies out of the tab order (window, window, same
	 * article) and leaves the kouign-amann copy (no article, too far), the
	 * copy with a tabindex, the lonely copy and the copies whose partner is
	 * unreachable or unnamed. F16 takes the promotion banner's link, button,
	 * text field, focusable span and frame, the decorative link (its
	 * tabindex="2" too: F16 beats F14) and the link inside a plain wrapper
	 * out, and leaves the header menu, the dropdown, the slider, the
	 * aria-controls, href and data-toggle-target targets, the hidden and
	 * dialog containers, the nav and the nested menu alone. F17 renames the
	 * second and third phone fields and the second loaves field with their
	 * labels and the reference in the same form.
	 */
	public function test_edge_only_intended_attributes_change() {
		$html   = self::edge();
		$output = self::engine( 'live' )->run( $html );
		$this->assertSame( self::EDGE_DIFF, self::token_diff( $html, $output ) );
		$this->assertSame( self::opener_count( $html ), self::opener_count( $output ) );
		$this->assertSame( substr_count( $html, '<' ), substr_count( $output, '<' ) );
		$this->assertSame( substr_count( $html, '>' ), substr_count( $output, '>' ) );
	}

	/**
	 * Running the engine on its own output changes nothing; both passes count the same openers.
	 */
	public function test_edge_idempotent_and_aligned() {
		$engine = self::engine( 'live' );
		$once   = $engine->run( self::edge() );
		$facts  = $engine->recorder()->engine();
		$this->assertSame( $facts['openers'], $facts['openers_apply'] );
		$this->assertSame( $once, self::engine( 'live' )->run( $once ) );
	}

	/**
	 * Scan of the edge page: every edit listed as "would change" (one per
	 * attribute: F16's tabindex beats F14's), and the exact R10-R13 findings.
	 */
	public function test_edge_scan() {
		$html   = self::edge();
		$engine = self::engine( 'off' );
		$this->assertSame( $html, $engine->run( $html, Engine::MODE_SCAN ) );
		$changes = $engine->recorder()->changes();
		$this->assertCount( count( self::EDGE_DIFF ), $changes );
		$fixes = array();
		foreach ( $changes as $change ) {
			$this->assertFalse( $change['applied'] );
			$fixes[ $change['fix'] ] = isset( $fixes[ $change['fix'] ] ) ? $fixes[ $change['fix'] ] + 1 : 1;
		}
		$this->assertSame(
			array(
				'hidden-duplicate-links' => 3,
				'aria-hidden-focus'      => 7,
				'duplicate-field-ids'    => 7,
			),
			$fixes
		);
		$this->assertSame(
			array(
				'R10 video[src=/media/oven.webm] 1.4.2: This media plays by itself, is not muted and has no controls to pause or stop it.',
				'R10 video[src=/media/oven.webm] 2.2.2: This media plays by itself and has no controls to pause or stop it.',
				'R10 audio[src=/media/bell.mp3] 1.4.2: This media plays by itself and is not muted: start it muted, or let visitors start it.',
				'R11 #phone-hint 4.1.2: The id "phone-hint" is used by 2 elements; labels and ARIA references reach only the first one.',
				'R11 button#add[type=submit] 4.1.2: The id "add" is used by 2 elements; labels and ARIA references reach only the first one.',
				'R11 input#dup[type=text][name=a] 4.1.2: The id "dup" is used by 2 elements; labels and ARIA references reach only the first one.',
				'R11 input#email[type=email][name=email] 4.1.2: The id "email" is used by 2 elements; labels and ARIA references reach only the first one.',
				'R11 input#loose[type=text][name=loose] 4.1.2: The id "loose" is used by 2 elements; labels and ARIA references reach only the first one.',
				'R11 #note 4.1.2: The id "note" is used by 2 elements; labels and ARIA references reach only the first one.',
				'R12 div.allergen-toggle[role=button] 2.1.1: Element with role="button" cannot be reached with the keyboard: use a real button or link, or add tabindex="0" and key handling.',
				'R12 span[role=link] 2.1.1: Element with role="link" cannot be reached with the keyboard: use a real button or link, or add tabindex="0" and key handling.',
				'R12 a[role=button] 2.1.1: Element with role="button" cannot be reached with the keyboard: use a real button or link, or add tabindex="0" and key handling.',
				'R12 div.tile 4.1.2: Element has a click handler but is not a control: keyboard and screen-reader users may not be able to use it.',
				'R13 table.hours 1.3.1: Table with 2 rows has no header cells: if it holds data, mark its header row or column with th elements.',
				'R13 table.inner 1.3.1: Table with 3 rows has no header cells: if it holds data, mark its header row or column with th elements.',
			),
			self::findings( $engine, array( 'R10', 'R11', 'R12', 'R13' ) )
		);
	}

	/**
	 * Admin views apply Preview and list Off as "would change"; visitors get Live only.
	 */
	public function test_modes() {
		$html   = self::page( '<article><a href="/a/" aria-hidden="true"><img src="/a.jpg" alt=""></a><h2><a href="/a/">A</a></h2></article><div class="x" aria-hidden="true"><a href="/b/">B</a></div>' );
		$fixes  = array( new Fix_Hidden_Duplicate_Links(), new Fix_Aria_Hidden_Focus() );
		$engine = new Engine(
			$fixes,
			array(
				'hidden-duplicate-links' => 'preview',
				'aria-hidden-focus'      => 'off',
			)
		);
		$this->assertSame( $html, $engine->run( $html, Engine::MODE_VISITOR ) );
		$output = $engine->run( $html, Engine::MODE_ADMIN_REPAIRED );
		$this->assertSame( array( "A.tabindex: NULL -> '-1'" ), self::token_diff( $html, $output ) );
		$list = array();
		foreach ( $engine->recorder()->changes() as $change ) {
			$list[] = $change['fix'] . ':' . ( $change['applied'] ? 'applied' : $change['state'] );
		}
		$this->assertSame( array( 'hidden-duplicate-links:applied', 'aria-hidden-focus:off' ), $list );
	}

	/**
	 * F15: the window is 10 tag openers either way; a shared article reaches
	 * further; the partner must be visible, named and reachable; a copy
	 * inside an aria-hidden container is F16's; fragments work too.
	 */
	public function test_f15_cases() {
		$copy = '<a href="/x/" aria-hidden="true"><img src="/x.jpg" alt=""></a>';
		$gap  = static function ( $openers ) {
			return str_repeat( '<b>.</b>', $openers );
		};
		$fix  = array( new Fix_Hidden_Duplicate_Links() );
		$hit  = array( "A.tabindex: NULL -> '-1'" );

		// The copy and its img are openers 0 and 1: 8 gap openers put the partner at distance 10.
		$this->assertSame( $hit, self::diff( $fix, self::page( $copy . $gap( 8 ) . '<a href="/x/">X</a>' ) ), 'distance 10' );
		$this->assertSame( array(), self::diff( $fix, self::page( '<div>' . $copy . $gap( 9 ) . '<a href="/x/">X</a></div>' ) ), 'distance 11' );
		$this->assertSame( $hit, self::diff( $fix, self::page( '<a href="/x/">X</a>' . $gap( 9 ) . $copy ) ), 'partner before the copy' );
		$this->assertSame( $hit, self::diff( $fix, self::page( '<article>' . $copy . $gap( 30 ) . '<a href="/x/">X</a></article>' ) ), 'same article' );
		$this->assertSame( array(), self::diff( $fix, self::page( '<article>' . $copy . '</article><article>' . $gap( 30 ) . '<a href="/x/">X</a></article>' ) ), 'another article, too far' );

		$untouched = array(
			'partner hidden'          => $copy . '<div aria-hidden="true"><a href="/x/">X</a></div>',
			'partner with hidden'     => $copy . '<a href="/x/" hidden>X</a>',
			'partner unreachable'     => $copy . '<a href="/x/" tabindex=" -1">X</a>',
			'partner unnamed'         => $copy . '<a href="/x/"><img src="/y.jpg" alt=""></a>',
			'other address'           => $copy . '<a href="/y/">Y</a>',
			'copy has tabindex'       => '<a href="/x/" aria-hidden="true" tabindex="0"><img src="/x.jpg" alt=""></a><a href="/x/">X</a>',
			'copy aria-hidden false'  => '<a href="/x/" aria-hidden="false"><img src="/x.jpg" alt=""></a><a href="/x/">X</a>',
			'copy in hidden container' => '<div aria-hidden="true">' . $copy . '</div><a href="/x/">X</a>',
			'copy with hidden'        => '<a href="/x/" aria-hidden="true" hidden><img src="/x.jpg" alt=""></a><a href="/x/">X</a>',
			'empty href'              => '<a href="" aria-hidden="true">.</a><a href="">X</a>',
		);
		foreach ( $untouched as $name => $body ) {
			$this->assertSame( array(), self::diff( $fix, self::page( $body ) ), $name );
		}

		$this->assertSame( $hit, self::diff( $fix, '<li>' . $copy . '<h3><a href="/x/">X</a></h3></li>', Engine::SCOPE_CONTENT ), 'fragment' );
		$this->assertSame( $hit, self::diff( $fix, self::page( $copy . '<a href="/x/" tabindex="0" aria-label="X">.</a>' ) ), 'partner named by aria-label, tabindex 0' );
	}

	/**
	 * F16 never touches a container that a script may reveal: every class in
	 * the exclusion list, aria-controls (before or after it), an in-page link
	 * or data target, a widget role, hidden or inert, a nav around it, an id
	 * in a fragment; nor a container inside such a container.
	 */
	public function test_f16_exclusions() {
		$fix  = array( new Fix_Aria_Hidden_Focus() );
		$link = '<a href="/x/">X</a>';
		foreach ( array( 'menu', 'nav', 'dropdown', 'modal', 'popup', 'popover', 'offcanvas', 'off-canvas', 'drawer', 'dialog', 'tab', 'slide', 'carousel', 'swiper', 'accordion', 'collapse', 'flyout' ) as $word ) {
			foreach ( array( $word, 'site-' . $word . '-panel', strtoupper( $word ) ) as $class ) {
				$this->assertSame( array(), self::diff( $fix, self::page( '<div class="' . $class . '" aria-hidden="true">' . $link . '</div>' ) ), $class );
			}
		}
		$untouched = array(
			'aria-controls after'   => '<div id="p" aria-hidden="true">' . $link . '</div><button aria-controls="p">Show</button>',
			'aria-controls before'  => '<div role="tab" aria-controls="q p">Show</div><div id="p" aria-hidden="true">' . $link . '</div>',
			'aria-controls on span' => '<span aria-controls="p">Show</span><div id="p" aria-hidden="true">' . $link . '</div>',
			'in-page link'          => '<a href="#p">Show</a><div id="p" aria-hidden="true">' . $link . '</div>',
			'data-target'           => '<button data-target="#p">Show</button><div id="p" aria-hidden="true">' . $link . '</div>',
			'data-bs-target'        => '<a href="/" data-bs-target="#p">Show</a><div id="p" aria-hidden="true">' . $link . '</div>',
			'data-toggle-target'    => '<button data-toggle-target="#p">Show</button><div id="p" aria-hidden="true">' . $link . '</div>',
			'role dialog'           => '<div role="dialog" aria-hidden="true">' . $link . '</div>',
			'role tabpanel'         => '<section role="region tabpanel" aria-hidden="true">' . $link . '</section>',
			'hidden'                => '<div aria-hidden="true" hidden>' . $link . '</div>',
			'inert'                 => '<div aria-hidden="true" inert>' . $link . '</div>',
			'nav element'           => '<nav aria-hidden="true">' . $link . '</nav>',
			'inside nav'            => '<nav><ul aria-hidden="true"><li>' . $link . '</li></ul></nav>',
			'outer container menu'  => '<div class="menu" aria-hidden="true"><div class="inner" aria-hidden="true">' . $link . '</div></div>',
			'inner container menu'  => '<div class="outer" aria-hidden="true"><div class="sub-menu" aria-hidden="true">' . $link . '</div></div>',
		);
		foreach ( $untouched as $name => $body ) {
			$this->assertSame( array(), self::diff( $fix, self::page( $body ) ), $name );
		}

		$this->assertSame( array(), self::diff( $fix, '<div id="p" aria-hidden="true">' . $link . '</div>', Engine::SCOPE_CONTENT ), 'an id in a fragment' );
		$this->assertSame( array( "A.tabindex: NULL -> '-1'" ), self::diff( $fix, '<div class="promo" aria-hidden="true">' . $link . '</div>', Engine::SCOPE_CONTENT ), 'no id in a fragment' );
		$this->assertSame( array( "A.tabindex: NULL -> '-1'" ), self::diff( $fix, self::page( '<div id="p" aria-hidden="true">' . $link . '</div><a href="#other">Other</a>' ) ), 'an id nothing points at' );
	}

	/**
	 * F16 takes out what the Tab key reaches and leaves what it does not.
	 */
	public function test_f16_focusable() {
		$fix     = array( new Fix_Aria_Hidden_Focus() );
		$changed = array(
			'<a href="/x/">X</a>',
			'<area href="/x/" alt="X">',
			'<button>B</button>',
			'<input name="q">',
			'<input type="checkbox" name="c">',
			'<select name="s"><option>1</option></select>',
			'<textarea name="t"></textarea>',
			'<iframe title="F" src="about:blank"></iframe>',
			'<details><summary>More</summary></details>',
			'<video src="/v.webm" controls></video>',
			'<div tabindex="0">D</div>',
			'<span tabindex="3">S</span>',
			'<div contenteditable>E</div>',
			'<div contenteditable="true">E</div>',
		);
		foreach ( $changed as $element ) {
			$diff = self::diff( $fix, self::page( '<div class="promo" aria-hidden="true">' . $element . '</div>' ) );
			$this->assertCount( 1, $diff, $element );
			$this->assertStringEndsWith( "-> '-1'", $diff[0], $element );
		}
		$kept = array(
			'<a>No href</a>',
			'<a href="/x/" tabindex="-1">X</a>',
			'<a href="/x/" hidden>X</a>',
			'<button disabled>B</button>',
			'<input type="hidden" name="h">',
			'<input name="q" disabled>',
			'<video src="/v.webm"></video>',
			'<div>Text</div>',
			'<div tabindex="abc">D</div>',
			'<div contenteditable="false">E</div>',
			'<img src="/x.jpg" alt="">',
		);
		foreach ( $kept as $element ) {
			$this->assertSame( array(), self::diff( $fix, self::page( '<div class="promo" aria-hidden="true">' . $element . '</div>' ) ), $element );
		}
		$this->assertSame( array(), self::diff( $fix, self::page( '<a href="/x/" aria-hidden="true">X</a>' ) ), 'A hidden link that is not inside a hidden container is F15 work.' );
	}

	/**
	 * F16 and F14 on one element: F16's "-1" wins when both apply; F14 alone resets to "0".
	 */
	public function test_f16_beats_f14() {
		$html = self::page( '<div class="promo" aria-hidden="true"><a href="/x/" tabindex="4">X</a></div>' );
		$this->assertSame( array( "A.tabindex: '4' -> '-1'" ), self::diff( array( new Fix_Positive_Tabindex(), new Fix_Aria_Hidden_Focus() ), $html ) );
		$this->assertSame( array( "A.tabindex: '4' -> '0'" ), self::diff( array( new Fix_Positive_Tabindex() ), $html ) );
	}

	/**
	 * F17 rewrites references only inside the form of the renamed field;
	 * labels, aria-describedby and aria-labelledby elsewhere keep the old id.
	 */
	public function test_f17_same_form_only() {
		$form = '<form><label for="f">Phone</label><input id="f" name="f" aria-describedby="h f-note"><span id="h">Hint</span></form>';
		$html = self::page(
			$form . $form
			. '<label for="f">Outside label</label>'
			. '<button aria-describedby="f">Help</button>'
			. '<form><input name="other" aria-labelledby="f"></form>'
		);
		$this->assertSame(
			array(
				"LABEL.for: 'f' -> 'f-softglaze-a11y-2'",
				"INPUT.id: 'f' -> 'f-softglaze-a11y-2'",
			),
			self::diff( array( new Fix_Duplicate_Field_Ids() ), $html )
		);

		$both = '<form><label for="f">F</label><input id="f" name="f"><button aria-labelledby="f b" id="b">Go</button><fieldset aria-describedby="f"><legend>L</legend></fieldset></form>';
		$this->assertSame(
			array(
				"LABEL.for: 'f' -> 'f-softglaze-a11y-2'",
				"INPUT.id: 'f' -> 'f-softglaze-a11y-2'",
				"BUTTON.aria-labelledby: 'f b' -> 'f-softglaze-a11y-2 b'",
				"FIELDSET.aria-describedby: 'f' -> 'f-softglaze-a11y-2'",
			),
			self::diff( array( new Fix_Duplicate_Field_Ids() ), self::page( $both . $both ) ),
			'References inside the same form follow the field; the id b stays duplicated (no label for it).'
		);
	}

	/**
	 * F17 leaves every id outside its safe case alone, and numbers several copies.
	 */
	public function test_f17_cases() {
		$fix       = array( new Fix_Duplicate_Field_Ids() );
		$form      = '<form><label for="f">F</label><input id="f" name="f"></form>';
		$untouched = array(
			'two uses in one form'  => '<form><label for="f">F</label><input id="f" name="a"><input id="f" name="b"></form>',
			'no label in the form'  => $form . '<form><input id="f" name="f"></form>',
			'outside any form'      => '<label for="f">F</label><input id="f" name="a"><label for="f">F</label><input id="f" name="b">',
			'unknown use'           => $form . '<div id="f"></div>' . $form,
			'first use is a div'    => '<div id="f"></div>' . $form,
			'hidden input'          => $form . '<form><label for="f">F</label><input type="hidden" id="f" name="f"></form>',
			'new id already exists' => $form . $form . '<p id="f-softglaze-a11y-2"></p>',
			'id with a space'       => '<form><label for="a b">F</label><input id="a b"></form><form><label for="a b">F</label><input id="a b"></form>',
			'unique id'             => $form,
		);
		foreach ( $untouched as $name => $body ) {
			$this->assertSame( array(), self::diff( $fix, self::page( $body ) ), $name );
		}

		$this->assertSame(
			array(
				"LABEL.for: 'f' -> 'f-softglaze-a11y-2'",
				"INPUT.id: 'f' -> 'f-softglaze-a11y-2'",
				"LABEL.for: 'f' -> 'f-softglaze-a11y-3'",
				"INPUT.id: 'f' -> 'f-softglaze-a11y-3'",
			),
			self::diff( $fix, self::page( $form . $form . $form ) )
		);
		$this->assertSame(
			array( "SELECT.id: 'f' -> 'f-softglaze-a11y-2'", "LABEL.for: 'f' -> 'f-softglaze-a11y-2'" ),
			self::diff( $fix, self::page( $form . '<form><select id="f" name="f"><option>1</option></select><label for="f">After</label></form>' ) ),
			'The label may come after the field.'
		);
		$this->assertSame( array(), self::diff( $fix, $form . $form, Engine::SCOPE_CONTENT ), 'Whole pages only.' );
	}

	/**
	 * R11 lists what stays duplicated, with the number of uses, whatever F17's state.
	 */
	public function test_r11() {
		$form   = '<form><label for="f">F</label><input id="f" name="f"></form>';
		$engine = self::scan( array( new Check_Duplicate_Ids() ), self::page( $form . $form . $form . '<p id="n">a</p><p id="n">b</p><input id="f-x"><input id="f-x">' ) );
		$this->assertSame(
			array(
				'R11 #n 4.1.2: The id "n" is used by 2 elements; labels and ARIA references reach only the first one.',
				'R11 input#f-x 4.1.2: The id "f-x" is used by 2 elements; labels and ARIA references reach only the first one.',
			),
			self::findings( $engine, array( 'R11' ) )
		);
		$engine = self::scan( array( new Check_Duplicate_Ids() ), self::page( $form . '<div id="f"></div>' ) );
		$this->assertSame( array( 'R11 input#f[name=f] 4.1.2: The id "f" is used by 2 elements; labels and ARIA references reach only the first one.' ), self::findings( $engine, array( 'R11' ) ) );
		$engine = self::scan( array( new Check_Duplicate_Ids() ), '<p id="n"></p><p id="n"></p>', Engine::SCOPE_CONTENT );
		$this->assertSame( array(), self::findings( $engine, array( 'R11' ) ), 'Whole pages only.' );
	}

	/**
	 * R10: autoplay without muted, or without controls.
	 */
	public function test_r10() {
		$cases = array(
			'<video src="/v.webm" autoplay></video>'                => array( 'R10 video[src=/v.webm] 1.4.2' ),
			'<video src="/v.webm" autoplay controls></video>'       => array( 'R10 video[src=/v.webm] 1.4.2' ),
			'<audio src="/a.mp3" autoplay muted></audio>'           => array( 'R10 audio[src=/a.mp3] 2.2.2' ),
			'<video src="/v.webm" autoplay muted controls></video>' => array(),
			'<video src="/v.webm" muted loop></video>'              => array(),
			'<video src="/v.webm" controls></video>'                => array(),
		);
		foreach ( $cases as $body => $expected ) {
			$list = array();
			foreach ( self::scan( array( new Check_Autoplay_Media() ), $body, Engine::SCOPE_CONTENT )->recorder()->findings() as $finding ) {
				$list[] = $finding['check'] . ' ' . $finding['sig'] . ' ' . $finding['sc'];
			}
			$this->assertSame( $expected, $list, $body );
		}
	}

	/**
	 * R12: control roles the keyboard cannot reach, click handlers on non-controls.
	 */
	public function test_r12() {
		$reported = array(
			'<div role="button">B</div>',
			'<span role="link">L</span>',
			'<li role="tab">T</li>',
			'<a role="button">B</a>',
			'<div role="button tab">B</div>',
			'<div onclick="go()">C</div>',
			'<img src="/x.jpg" alt="X" onclick="go()">',
			'<a onclick="go()">No href</a>',
			'<tr onclick="go()"><td>Row</td></tr>',
		);
		foreach ( $reported as $body ) {
			$this->assertCount( 1, self::findings( self::scan( array( new Check_Non_Native_Controls() ), $body, Engine::SCOPE_CONTENT ), array( 'R12' ) ), $body );
		}
		$fine = array(
			'<div role="button" tabindex="0">B</div>',
			'<div role="button" tabindex="-1">B</div>',
			'<div role="button" aria-disabled="true">B</div>',
			'<button role="button">B</button>',
			'<a href="/" role="button">B</a>',
			'<input type="submit" role="button" value="Go">',
			'<button onclick="go()">B</button>',
			'<a href="/" onclick="go()">L</a>',
			'<li role="menuitem" onclick="go()">I</li>',
			'<label onclick="go()">L</label>',
			'<div role="dialog">D</div>',
			'<div class="onclick">Not an attribute</div>',
		);
		foreach ( $fine as $body ) {
			$this->assertSame( array(), self::findings( self::scan( array( new Check_Non_Native_Controls() ), $body, Engine::SCOPE_CONTENT ), array( 'R12' ) ), $body );
		}
	}

	/**
	 * R13: two or more rows and no th, per table (nested ones on their own); layout and hidden tables left out.
	 */
	public function test_r13() {
		$cases = array(
			'<table><tr><td>a</td></tr><tr><td>b</td></tr></table>'                                  => 1,
			'<table><thead><tr><td>a</td></tr></thead><tbody><tr><td>b</td></tr></tbody></table>'     => 1,
			'<table><tr><th>a</th></tr><tr><td>b</td></tr></table>'                                  => 0,
			'<table><tr><td>a</td></tr></table>'                                                     => 0,
			'<table role="presentation"><tr><td>a</td></tr><tr><td>b</td></tr></table>'              => 0,
			'<table role="none"><tr><td>a</td></tr><tr><td>b</td></tr></table>'                      => 0,
			'<div aria-hidden="true"><table><tr><td>a</td></tr><tr><td>b</td></tr></table></div>'    => 0,
			'<table><tr><th>H</th></tr><tr><td><table><tr><td>a</td></tr><tr><td>b</td></tr></table></td></tr></table>' => 1,
			'<table><tr><td><table><tr><th>a</th></tr><tr><td>b</td></tr></table></td></tr><tr><td>c</td></tr></table>' => 1,
			'<table><tr><td>a</td></tr><tr><td>b</td>'                                               => 1,
		);
		foreach ( $cases as $body => $expected ) {
			$this->assertCount( $expected, self::findings( self::scan( array( new Check_Tables() ), $body, Engine::SCOPE_CONTENT ), array( 'R13' ) ), $body );
		}
	}

	/**
	 * The engine shows a fix with Fix_Base::IN_HIDDEN every opener that
	 * carries aria-hidden="true" or sits inside such an element, once, plus
	 * the tags it names; nothing else.
	 */
	public function test_engine_dispatches_hidden_content() {
		$html  = '<p>a</p><div aria-hidden="true"><span>b</span><p>c</p><svg><path d=""></path></svg></div><i aria-hidden="true"></i><span aria-hidden="false"><b>d</b></span>';
		$probe = new Softglaze_A11y_Test_Fix_Hidden_Probe( array( Fix_Base::IN_HIDDEN ) );
		( new Engine( array( $probe ), array( 'test-hidden-probe' => 'live' ) ) )->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
		$this->assertSame( array( 'DIV#1', 'SPAN#2', 'P#3', 'SVG#4', 'PATH#5', 'I#6' ), $probe->seen );

		$probe = new Softglaze_A11y_Test_Fix_Hidden_Probe( array( 'P', Fix_Base::IN_HIDDEN ) );
		( new Engine( array( $probe ), array( 'test-hidden-probe' => 'live' ) ) )->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
		$this->assertSame( array( 'P#0', 'DIV#1', 'SPAN#2', 'P#3', 'SVG#4', 'PATH#5', 'I#6' ), $probe->seen, 'A named tag in hidden content is shown once.' );
	}
}
