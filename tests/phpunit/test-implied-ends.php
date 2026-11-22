<?php
/**
 * Phase 8B, review finding C1: frames whose end tag HTML lets authors leave
 * out (li, p, dt/dd, option, tr, td/th) end where the HTML parser ends them,
 * so an aria-hidden list item without </li> does not hide the rest of the
 * page; F16 plans nothing for content whose hidden container was still open
 * when the input ended. Finding C2: an SVG whose text names its link is never
 * hidden by F07.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Aria_Hidden_Focus;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Decorative_Icons;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Link_Name;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * Implied end tags in the analyse pass.
 */
class Test_Implied_Ends extends Softglaze_A11y_Engine_TestCase {

	/**
	 * A whole document around some body markup.
	 *
	 * @param string $body Body markup.
	 * @return string
	 */
	private static function doc( $body ) {
		return '<!DOCTYPE html><html lang="en"><head><title>Page</title></head><body>' . $body . '</body></html>';
	}

	/**
	 * Ordinals-free result of some fixes, all Live: "TAG.attr: before -> after"
	 * plus the href of the element when it is a link, so the tests can say which link changed.
	 *
	 * @param array  $fixes Fix objects.
	 * @param string $html  Document or fragment.
	 * @param string $scope Scope.
	 * @return string[]
	 */
	private static function changed( array $fixes, $html, $scope = Engine::SCOPE_PAGE ) {
		$states = array();
		foreach ( $fixes as $fix ) {
			$states[ $fix->id() ] = 'live';
		}
		$engine = new Engine( $fixes, $states );
		$output = $engine->run( $html, Engine::MODE_VISITOR, $scope );
		$list   = array();
		$before = new WP_HTML_Tag_Processor( $html );
		$after  = new WP_HTML_Tag_Processor( $output );
		while ( $before->next_tag() && $after->next_tag() ) {
			foreach ( (array) $after->get_attribute_names_with_prefix( '' ) as $name ) {
				$old = $before->get_attribute( $name );
				$new = $after->get_attribute( $name );
				if ( $old !== $new ) {
					$href   = $before->get_attribute( 'href' );
					$list[] = $after->get_tag() . ( is_string( $href ) ? '[' . $href . ']' : '' ) . '.' . $name . '=' . ( is_string( $new ) ? $new : '' );
				}
			}
		}
		return $list;
	}

	/**
	 * The review's example: an aria-hidden list item without </li> no longer
	 * hides (and takes out of the tab order) every focusable element after it.
	 */
	public function test_f16_list_item_without_end_tag() {
		$fix = array( new Fix_Aria_Hidden_Focus() );
		$this->assertSame( array(), self::changed( $fix, self::doc( '<ul><li aria-hidden="true">/<li>Page</li></ul><main><a href="/x">x</a></main>' ) ), 'review example' );

		$cases = array(
			'next item'           => '<ul><li aria-hidden="true"><a href="/a">a</a><li><a href="/b">b</a></ul><a href="/c">c</a>',
			'minified'            => '<ul><li aria-hidden=true><a href=/a>a</a><li><a href=/b>b</a></ul><a href=/c>c</a>',
			'last item, list end' => '<ol><li><a href="/b">b</a><li aria-hidden="true"><a href="/a">a</a></ol><a href="/c">c</a>',
			'paragraph, block'    => '<p aria-hidden="true"><a href="/a">a</a><div><a href="/b">b</a></div>',
			'paragraph, block end' => '<div><p aria-hidden="true"><a href="/a">a</a></div><a href="/b">b</a>',
			'paragraph, list'     => '<p aria-hidden="true"><a href="/a">a</a><ul><li><a href="/b">b</a></li></ul>',
			'table row'           => '<table><tr aria-hidden="true"><td><a href="/a">a</a><tr><td><a href="/b">b</a></table><a href="/c">c</a>',
			'table cell'          => '<table><tr><td aria-hidden="true"><a href="/a">a</a><td><a href="/b">b</a></tr></table><a href="/c">c</a>',
			'table cell, row end' => '<table><tr><td aria-hidden="true"><a href="/a">a</a></tr><tr><td><a href="/b">b</a></table>',
			'definition term'     => '<dl><dt aria-hidden="true"><a href="/a">a</a><dd><a href="/b">b</a></dl><a href="/c">c</a>',
			'definition, list end' => '<dl><dt>t<dd aria-hidden="true"><a href="/a">a</a></dl><a href="/b">b</a>',
		);
		foreach ( $cases as $name => $body ) {
			$this->assertSame( array( 'A[/a].tabindex=-1' ), self::changed( $fix, self::doc( $body ) ), $name );
		}
	}

	/**
	 * Options: a hidden option without </option> ends at the next option or the end of the select.
	 */
	public function test_f16_option_without_end_tag() {
		$fix = array( new Fix_Aria_Hidden_Focus() );
		$this->assertSame( array(), self::changed( $fix, self::doc( '<select name="s"><option aria-hidden="true">x<option>y</select><a href="/z">z</a>' ) ) );
		$this->assertSame( array(), self::changed( $fix, self::doc( '<select name="s"><optgroup label="g"><option aria-hidden="true">x</optgroup></select><button>b</button>' ) ) );
	}

	/**
	 * Nested lists and tables inside a hidden item keep it open until their
	 * own end; a button inside a hidden paragraph keeps a block inside it
	 * from ending the paragraph.
	 */
	public function test_f16_nested_containers() {
		$fix = array( new Fix_Aria_Hidden_Focus() );
		$this->assertSame(
			array( 'A[/a].tabindex=-1', 'A[/b].tabindex=-1', 'A[/c].tabindex=-1', 'A[/d].tabindex=-1' ),
			self::changed( $fix, self::doc( '<ul><li aria-hidden="true"><a href="/a">a</a><ul><li><a href="/b">b</a><li><a href="/c">c</a></ul><a href="/d">d</a><li><a href="/e">e</a></ul><a href="/f">f</a>' ) ),
			'nested list'
		);
		$this->assertSame(
			array( 'A[/a].tabindex=-1', 'A[/b].tabindex=-1', 'A[/c].tabindex=-1' ),
			self::changed( $fix, self::doc( '<table><tr><td aria-hidden="true"><table><tr><td><a href="/a">a</a><td><a href="/b">b</a></table><a href="/c">c</a><td><a href="/d">d</a></table>' ) ),
			'nested table'
		);
		$this->assertSame(
			array( 'BUTTON.tabindex=-1', 'A[/a].tabindex=-1' ),
			self::changed( $fix, self::doc( '<p aria-hidden="true"><button><div>x</div></button><a href="/a">a</a></p><a href="/b">b</a>' ) ),
			'button inside a paragraph'
		);
	}

	/**
	 * A hidden container still open when the input ends may have lost its end
	 * tag: nothing inside it is taken out of the tab order. One that ended
	 * inside it still counts.
	 */
	public function test_f16_container_open_at_end() {
		$fix = array( new Fix_Aria_Hidden_Focus() );
		$this->assertSame( array(), self::changed( $fix, '<div class="promo" aria-hidden="true"><a href="/a">a</a>', Engine::SCOPE_CONTENT ), 'unclosed div' );
		$this->assertSame( array(), self::changed( $fix, '<p>x</p><section class="promo" aria-hidden="true"><p><a href="/a">a</a></p><a href="/b">b</a>', Engine::SCOPE_CONTENT ), 'unclosed section' );
		$this->assertSame(
			array( 'A[/a].tabindex=-1' ),
			self::changed( $fix, '<div class="promo" aria-hidden="true"><div class="inner" aria-hidden="true"><a href="/a">a</a></div><a href="/b">b</a>', Engine::SCOPE_CONTENT ),
			'inner container closed, outer open at the end'
		);
		$this->assertSame( array( 'A[/a].tabindex=-1' ), self::changed( $fix, '<div class="promo" aria-hidden="true"><a href="/a">a</a></div>', Engine::SCOPE_CONTENT ), 'control: closed container' );
	}

	/**
	 * Naming facts after an implied end are no longer marked hidden, so F05
	 * names an icon link that follows an aria-hidden item without </li>.
	 */
	public function test_names_after_implied_end() {
		$html   = self::doc( '<ul><li aria-hidden="true">x<li><a href="https://www.facebook.com/acme"><i class="fa fa-facebook"></i></a></ul>' );
		$change = self::changed( array( new Fix_Link_Name() ), $html );
		$this->assertCount( 1, $change );
		$this->assertStringStartsWith( 'A[https://www.facebook.com/acme].aria-label=', $change[0] );

		$probe  = new Softglaze_A11y_Test_Name_Probe();
		$engine = new Engine( array( $probe ), array( $probe->id() => 'live' ) );
		$engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_PAGE );
		$this->assertCount( 1, $probe->names );
		$this->assertFalse( $probe->names[0]->hidden, 'the link is not inside the hidden item' );
	}

	/**
	 * C2: an SVG whose text (text or foreignObject) is the name of its link is
	 * never hidden; decorative SVGs next to link text still are.
	 */
	public function test_f07_keeps_svg_that_names_its_control() {
		$fixes = array( new Fix_Link_Name(), new Fix_Decorative_Icons() );
		$kept  = array(
			'svg text'          => '<a href="/"><svg viewBox="0 0 10 10"><text x="0" y="8">Acme</text></svg></a>',
			'foreignObject'     => '<a href="/shop/"><svg viewBox="0 0 10 10"><foreignObject width="10" height="10"><span>Shop</span></foreignObject></svg></a>',
			'button, svg text'  => '<button type="button"><svg viewBox="0 0 10 10"><text>Next</text></svg></button>',
			'text after a path' => '<a href="/x/"><svg viewBox="0 0 10 10"><path d="M0 0h1"></path><g><text>Offers</text></g></svg></a>',
		);
		foreach ( $kept as $name => $body ) {
			$this->assertSame( array(), self::changed( $fixes, self::doc( $body ) ), $name );
		}

		$hidden = array(
			'text beside the svg'   => '<a href="/x/">Shop <svg viewBox="0 0 10 10"><path d="M0 0h1"></path></svg></a>',
			'whitespace in the svg' => "<a href=\"/x/\">Shop <svg viewBox=\"0 0 10 10\">\n  <path d=\"M0 0h1\"></path>\n</svg></a>",
		);
		foreach ( $hidden as $name => $body ) {
			$this->assertSame( array( 'SVG.aria-hidden=true', 'SVG.focusable=false' ), self::changed( $fixes, self::doc( $body ) ), $name );
		}
	}
}
