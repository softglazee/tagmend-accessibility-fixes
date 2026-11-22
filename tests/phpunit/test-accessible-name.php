<?php
/**
 * The accessible-name computation on its own (Phase 3 task 1): one table of
 * cases for links, buttons, role="button" elements and fields, read through
 * a probe fix from Document_Index::names() after the analyse pass.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Iframe_Title;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * Accessible names.
 */
class Test_Accessible_Name extends Softglaze_A11y_Engine_TestCase {

	/**
	 * Runs the probe over a fragment in scan mode.
	 *
	 * @param string $html Fragment.
	 * @return Softglaze_A11y_Test_Name_Probe
	 */
	private static function probe( $html ) {
		$probe  = new Softglaze_A11y_Test_Name_Probe();
		$engine = new Engine( array( $probe ), array( 'test-name-probe' => 'live' ) );
		$output = $engine->run( $html, Engine::MODE_SCAN, Engine::SCOPE_CONTENT );
		self::assertSame( $html, $output, 'Scan mode returns the input.' );
		self::assertNull( $engine->recorder()->get_fallback() );
		return $probe;
	}

	/**
	 * The table: fragment, record position, expected kind, name, source, and
	 * optionally exactness and hidden.
	 *
	 * @return array
	 */
	public function data_names() {
		return array(
			'link text'                          => array( '<a href="/x">Shop</a>', 0, 'link', 'Shop', 'content' ),
			'empty link'                         => array( '<a href="/x"></a>', 0, 'link', '', '' ),
			'whitespace and nbsp only'           => array( '<a href="/x"> &nbsp; </a>', 0, 'link', '', '' ),
			'image alt names a link'             => array( '<a href="/x"><img src="a.jpg" alt="Logo"></a>', 0, 'link', 'Logo', 'content' ),
			'image without alt'                  => array( '<a href="/x"><img src="a.jpg"></a>', 0, 'link', '', '' ),
			'image with empty alt'               => array( '<a href="/x"><img src="a.jpg" alt=""></a>', 0, 'link', '', '' ),
			'svg title names a link'             => array( '<a href="/x"><svg><title>Facebook</title><path/></svg></a>', 0, 'link', 'Facebook', 'content' ),
			'svg title inside aria-hidden svg'   => array( '<a href="/x"><svg aria-hidden="true"><title>Facebook</title></svg></a>', 0, 'link', '', '' ),
			'icon font only'                     => array( '<a href="/x"><i class="fa fa-instagram"></i></a>', 0, 'link', '', '' ),
			'private use glyph only'             => array( '<a href="/x"><span class="et-pb-icon">&#xe076;</span></a>', 0, 'link', '', '' ),
			'glyph and text'                     => array( '<a href="/x"><span>&#xe900;</span> Menu</a>', 0, 'link', 'Menu', 'content' ),
			'aria-label wins over text'          => array( '<a href="/x" aria-label="Cart">X</a>', 0, 'link', 'Cart', 'aria-label' ),
			'blank aria-label is ignored'        => array( '<a href="/x" aria-label="  ">Shop</a>', 0, 'link', 'Shop', 'content' ),
			'title names an empty link'          => array( '<a href="/x" title="Opening hours"><svg aria-hidden="true"></svg></a>', 0, 'link', 'Opening hours', 'title' ),
			'title is only a description'        => array( '<a href="/x" title="Tip">Shop</a>', 0, 'link', 'Shop', 'content' ),
			'labelledby an earlier span'         => array( '<span id="l1">Order now</span><a href="/x" aria-labelledby="l1"></a>', 0, 'link', 'Order now', 'aria-labelledby' ),
			'labelledby a missing id'            => array( '<a href="/x" aria-labelledby="missing" aria-label="Fallback"></a>', 0, 'link', 'Fallback', 'aria-label' ),
			'labelledby later elements'          => array( '<a href="/x" aria-labelledby="h1 l2"></a><h2 id="h1">News</h2><span id="l2">today</span>', 0, 'link', 'News today', 'aria-labelledby' ),
			'labelledby text not collected'      => array( '<p id="p1">Para</p><a href="/x" aria-labelledby="p1"></a>', 0, 'link', '', 'aria-labelledby', false ),
			'labelledby an empty element'        => array( '<span id="e1"></span><a href="/x" aria-labelledby="e1">Shop</a>', 0, 'link', 'Shop', 'content' ),
			'aria-hidden content'                => array( '<a href="/x"><span aria-hidden="true">&#9733;</span></a>', 0, 'link', '', '' ),
			'aria-hidden content and text'       => array( '<a href="/x"><span aria-hidden="true">&#9733;</span> Reviews</a>', 0, 'link', 'Reviews', 'content' ),
			'descendant aria-label replaces it'  => array( '<a href="/x"><span aria-label="Gift">&#127873;</span> cards</a>', 0, 'link', 'Gift cards', 'content' ),
			'screen-reader text'                 => array( '<a href="/x"><span class="screen-reader-text">Facebook</span><i class="fab fa-facebook"></i></a>', 0, 'link', 'Facebook', 'content' ),
			'hidden attribute content'           => array( '<a href="/x"><span hidden>Secret</span></a>', 0, 'link', '', '' ),
			'image aria-label over alt'          => array( '<a href="/x"><img src="a.jpg" alt="Cart" aria-label="Basket"></a>', 0, 'link', 'Basket', 'content' ),
			'anchor without href'                => array( '<a name="top">Top</a>', 0, 'other', 'Top', 'content' ),
			'empty button'                       => array( '<button type="submit"></button>', 0, 'button', '', '' ),
			'button aria-label'                  => array( '<button aria-label="Open menu"><svg aria-hidden="true"></svg></button>', 0, 'button', 'Open menu', 'aria-label' ),
			'button text'                        => array( '<button>Subscribe</button>', 0, 'button', 'Subscribe', 'content' ),
			'submit with empty value'            => array( '<input type="submit" value="">', 0, 'button', '', '' ),
			'submit without value'               => array( '<input type="submit">', 0, 'button', 'Submit', 'default' ),
			'submit with value'                  => array( '<input type="submit" value="Send">', 0, 'button', 'Send', 'value' ),
			'input button named by title'        => array( '<input type="button" value="" title="Help">', 0, 'button', 'Help', 'title' ),
			'reset without value'                => array( '<input type="reset">', 0, 'button', 'Reset', 'default' ),
			'image input alt'                    => array( '<input type="image" src="go.png" alt="Go">', 0, 'button', 'Go', 'alt' ),
			'role button with text'              => array( '<div role="button">Show allergens</div>', 0, 'button', 'Show allergens', 'content' ),
			'empty role button'                  => array( '<span role="button"></span>', 0, 'button', '', '' ),
			'link with role button'              => array( '<a href="/x" role="button"></a>', 0, 'button', '', '' ),
			'link inside aria-hidden container'  => array( '<div aria-hidden="true"><a href="/x">Hidden link</a></div>', 0, 'link', 'Hidden link', 'content', true, true ),
			'label for an earlier field label'   => array( '<label for="f1">Email</label><input id="f1" type="email">', 0, 'field', 'Email', 'label' ),
			'label for a later label'            => array( '<input id="f2" type="email"><label for="f2">Email</label>', 0, 'field', 'Email', 'label' ),
			'wrapping label around a select'     => array( '<label>Cake size <select name="size"><option>6 inch</option></select></label>', 0, 'field', 'Cake size', 'label' ),
			'wrapping label around a checkbox'   => array( '<label><input type="checkbox" name="agree"> I agree</label>', 0, 'field', 'I agree', 'label' ),
			'label for another element'          => array( '<label for="other">Other <input id="me" type="text"></label>', 0, 'field', '', '' ),
			'empty label'                        => array( '<label for="x1"></label><input id="x1" type="text">', 0, 'field', '', '' ),
			'placeholder only'                   => array( '<input type="text" placeholder="Your name">', 0, 'field', 'Your name', 'placeholder' ),
			'no name at all'                     => array( '<input type="tel" name="phone">', 0, 'field', '', '' ),
			'title before placeholder'           => array( '<input type="text" title="Phone" placeholder="0123">', 0, 'field', 'Phone', 'title' ),
			'field labelledby a span'            => array( '<span id="a1">Allergies</span><input type="text" aria-labelledby="a1">', 0, 'field', 'Allergies', 'aria-labelledby' ),
			'textarea placeholder'               => array( '<textarea placeholder="Message"></textarea>', 0, 'field', 'Message', 'placeholder' ),
			'select without label'               => array( '<select name="day"><option>Friday</option></select>', 0, 'field', '', '' ),
			'checkbox placeholder is no name'    => array( '<input type="checkbox" placeholder="Agree">', 0, 'field', '', '' ),
			'second record'                      => array( '<a href="/a">One</a><button></button>', 1, 'button', '', '' ),
		);
	}

	/**
	 * Each case of the table.
	 *
	 * @dataProvider data_names
	 *
	 * @param string $html     Fragment.
	 * @param int    $position Record position.
	 * @param string $kind     Expected kind.
	 * @param string $name     Expected name.
	 * @param string $source   Expected source.
	 * @param bool   $exact    Expected exactness.
	 * @param bool   $hidden   Expected hidden flag.
	 */
	public function test_name( $html, $position, $kind, $name, $source, $exact = true, $hidden = false ) {
		$names = self::probe( $html )->names;
		$this->assertArrayHasKey( $position, $names );
		$record = $names[ $position ];
		$this->assertSame(
			array( $kind, $name, $source, $exact, $hidden, '' !== $source ),
			array( $record->kind, $record->name(), $record->source(), $record->is_exact(), $record->hidden, $record->is_named() )
		);
	}

	/**
	 * Hidden inputs get no record; every other control gets exactly one, in document order.
	 */
	public function test_one_record_per_element() {
		$names = self::probe( '<form><input type="hidden" name="t"><a href="/a">A</a><button>B</button><input name="c"><select name="d"></select><textarea name="e"></textarea><div role="link">F</div><p>not a control</p></form>' )->names;
		$this->assertSame(
			array( 'A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA', 'DIV' ),
			array_map(
				static function ( $record ) {
					return $record->tag;
				},
				$names
			)
		);
	}

	/**
	 * Images, SVGs and icon classes inside a control are counted on the innermost control.
	 */
	public function test_control_parts() {
		$names = self::probe( '<a href="/"><img src="logo.png"><img src="b.png" alt=""><svg class="icon icon-home"></svg><i class="fa fa-cart-shopping"></i><button><span class="dashicons dashicons-search"></span></button></a>' )->names;
		$this->assertSame( array( 2, 1, 1 ), array( $names[0]->images, $names[0]->images_without_alt, $names[0]->svgs ) );
		$this->assertSame( array( 'icon', 'icon-home', 'fa', 'fa-cart-shopping' ), $names[0]->icon_classes );
		$this->assertSame( array( 'dashicons', 'dashicons-search' ), $names[1]->icon_classes );
	}

	/**
	 * Search forms, articles and wrapping labels are linked to the controls inside.
	 */
	public function test_context_frames() {
		$names = self::probe( '<article><h3>Autumn menu</h3><a href="/a">Read more</a></article><form role="search"><button></button></form><form><button></button><input type="search" name="q"></form><form><button></button></form>' )->names;
		$this->assertSame( 'Autumn menu', $names[0]->article->heading );
		$this->assertTrue( $names[1]->form->search, 'role="search"' );
		$this->assertTrue( $names[2]->form->search, 'type="search" later in the form' );
		$this->assertFalse( $names[4]->form->search );
	}

	/**
	 * Link records keep aria-hidden and tabindex (F15) and tell an aria-hidden
	 * ancestor apart from the element's own attribute.
	 */
	public function test_hidden_facts() {
		$names = self::probe( '<a href="/a" aria-hidden="true" tabindex="-1">A</a><div aria-hidden="true"><a href="/b">B</a></div><a href="/c">C</a>' )->names;
		$this->assertSame( array( 'true', '-1', true, false ), array( $names[0]->attr( 'aria-hidden' ), $names[0]->attr( 'tabindex' ), $names[0]->hidden, $names[0]->in_hidden ) );
		$this->assertSame( array( true, true ), array( $names[1]->hidden, $names[1]->in_hidden ) );
		$this->assertSame( array( false, false, false ), array( $names[2]->hidden, $names[2]->in_hidden, $names[2]->has( 'tabindex' ) ) );
	}

	/**
	 * Every fix shares one record per element: the control a child sees is the record in the index.
	 */
	public function test_records_are_shared() {
		$probe = self::probe( '<a href="/x"><i class="fa fa-instagram"></i></a><div role="button"><span class="icon"></span></div>' );
		$this->assertCount( 2, $probe->controls );
		$this->assertSame( $probe->names[0], $probe->controls[0]->accessible );
		$this->assertSame( $probe->names[1], $probe->controls[1]->accessible );
		$this->assertFalse( $probe->controls[0]->is_named_control() );
	}

	/**
	 * Names are not computed at all when no active fix or check uses them.
	 */
	public function test_names_only_when_used() {
		// This can be the first engine run of the process, which pays for class
		// loading inside the time guard; a generous limit keeps a slow host from
		// turning it into a time fallback (the failure seen twice in Phases 4B and 5).
		$engine = new Engine( array( new Fix_Iframe_Title() ), array( 'iframe-title' => 'live' ), array( 'max_ms' => 1000 ) );
		$html   = '<a href="/x"></a><iframe src="https://www.youtube.com/embed/x"></iframe>';
		$this->assertSame( '<a href="/x"></a><iframe title="YouTube video" src="https://www.youtube.com/embed/x"></iframe>', $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ) );
		$this->assertNull( $engine->recorder()->get_fallback() );

		$probe = new Softglaze_A11y_Test_Name_Probe();
		$spy   = new Engine( array( $probe ), array( 'test-name-probe' => 'off' ) );
		$spy->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
		$this->assertSame( array(), $probe->names, 'An Off fix is not analysed in visitor mode, so nothing is computed.' );
	}
}
