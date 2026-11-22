<?php
/**
 * Escaping tests (ET, docs/05-TEST-PLAN.md section 4) for every fix that
 * writes text, on WordPress 6.5 and 7.1: hostile values reach the page only
 * as encoded attribute text, read back as the intended plain text, and never
 * add an element.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Engine;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * Escaping tests.
 */
class Test_Engine_Escaping extends Softglaze_A11y_Engine_TestCase {

	/**
	 * Translation overrides for the gettext filter.
	 *
	 * @var array
	 */
	private $translations = array();

	/**
	 * Replaces this plugin's strings with hostile ones.
	 *
	 * @param string $translation Translation.
	 * @param string $text        Original.
	 * @param string $domain      Domain.
	 * @return string
	 */
	public function filter_gettext( $translation, $text, $domain ) {
		if ( 'tagmend-accessibility-fixes' === $domain && isset( $this->translations[ $text ] ) ) {
			return $this->translations[ $text ];
		}
		return $translation;
	}

	/**
	 * Runs one case and checks the invariants.
	 *
	 * @param string $html      Input.
	 * @param string $scope     Scope.
	 * @param string $tag       Tag that receives the value.
	 * @param string $attribute Attribute that receives the value.
	 * @param string $expected  Plain text a browser must read.
	 */
	private function assert_safe( $html, $scope, $tag, $attribute, $expected ) {
		$engine = self::engine( 'live' );
		$output = $engine->run( $html, Engine::MODE_VISITOR, $scope );

		$this->assertNull( $engine->recorder()->get_fallback() );
		$this->assertNotSame( $html, $output );
		$this->assertSame( $expected, self::browser_value( $output, $tag, $attribute ), 'Browser reading of ' . $tag . '.' . $attribute );
		$this->assertSame( self::opener_count( $html ), self::opener_count( $output ), 'No element was added.' );
		$this->assertSame( substr_count( $html, '<' ), substr_count( $output, '<' ) );
		$this->assertSame( substr_count( $html, '>' ), substr_count( $output, '>' ) );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringNotContainsString( '<img', $output );

		if ( self::wp_at_least_69() ) {
			$processor = new WP_HTML_Tag_Processor( $output );
			$processor->next_tag( $tag );
			$this->assertSame( $expected, $processor->get_attribute( $attribute ), 'get_attribute() on 6.9+' );
		}
	}

	/**
	 * F09: hostile translations of the label strings.
	 */
	public function test_f09_hostile_translation() {
		$this->translations = array(
			/* Not real translations: hostile input for the test. */
			'Embedded content from %s' => '"><img src=x onerror=alert(1)> Tom & Jerry &amp; \'quotes\' %s',
			'YouTube video'            => 'Bakery&#8217;s <b>tour</b> &amp;amp; more',
		);
		add_filter( 'gettext', array( $this, 'filter_gettext' ), 10, 3 );

		$this->assert_safe(
			'<p>x</p><iframe src="https://media.example.org/player"></iframe>',
			Engine::SCOPE_CONTENT,
			'iframe',
			'title',
			'"> Tom & Jerry & \'quotes\' media.example.org'
		);
		$this->assert_safe(
			'<p>x</p><iframe src="https://www.youtube.com/embed/x"></iframe>',
			Engine::SCOPE_CONTENT,
			'iframe',
			'title',
			"Bakery\u{2019}s tour & more"
		);

		remove_filter( 'gettext', array( $this, 'filter_gettext' ), 10 );
	}

	/**
	 * F09: a hostile src never reaches the label.
	 */
	public function test_f09_hostile_src() {
		$this->assert_safe(
			'<iframe src="https://&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;.example/x"></iframe>',
			Engine::SCOPE_CONTENT,
			'iframe',
			'title',
			'Embedded content'
		);
	}

	/**
	 * F09: a label longer than 150 characters is capped.
	 */
	public function test_f09_label_is_capped() {
		$this->translations = array( 'YouTube video' => str_repeat( 'Long label ', 30 ) );
		add_filter( 'gettext', array( $this, 'filter_gettext' ), 10, 3 );
		$engine = self::engine( 'live' );
		$output = $engine->run( '<iframe src="https://youtube.com/embed/x"></iframe>', Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
		remove_filter( 'gettext', array( $this, 'filter_gettext' ), 10 );

		$title = self::browser_value( $output, 'iframe', 'title' );
		$this->assertLessThanOrEqual( 150, mb_strlen( $title ) );
		$this->assertStringStartsWith( 'Long label Long label', $title );
	}

	/**
	 * F10: a hostile locale is refused (no edit, one finding), an underscore value is corrected.
	 */
	public function test_f10_hostile_locale_is_refused() {
		$hostile = static function () {
			return 'en"><script>alert(1)</script>';
		};
		add_filter( 'determine_locale', $hostile );
		$this->assertSame( 'en"><script>alert(1)</script>', get_bloginfo( 'language' ) );

		$html   = '<!DOCTYPE html><html><head></head><body></body></html>';
		$engine = self::engine( 'live', array(), Engine::class, array( 'checks' => array() ) );
		$this->assertSame( $html, $engine->run( $html, Engine::MODE_ADMIN_REPAIRED ) );
		$this->assertNull( $engine->recorder()->get_fallback() );
		$findings = $engine->recorder()->findings();
		$this->assertCount( 1, $findings );
		$this->assertSame( 'html-lang', $findings[0]['check'] );
		remove_filter( 'determine_locale', $hostile );

		$this->assert_safe( '<html lang="pt_BR"><body></body></html>', Engine::SCOPE_PAGE, 'html', 'lang', 'pt-BR' );
	}

	/**
	 * F01 and F02: hostile Media Library alt text reaches the page only as
	 * plain, encoded attribute text and never adds an element.
	 */
	public function test_f01_f02_hostile_library_alt() {
		$cases = array(
			'"><img src=x onerror=alert(1)> Tom & Jerry &amp; \'quotes\'' => '"> Tom & Jerry & \'quotes\'',
			'Bakery&#8217;s <b>tour</b> &amp;amp; more'                   => "Bakery\u{2019}s tour & more",
			'<script>alert(1)</script>'                                   => null,
			// Phase 8B C6: an encoded "<" is text, not the start of a tag to strip.
			'Kids 3&lt;6 years'                                           => 'Kids 3<6 years',
			'Kids 3&amp;lt;6 years &gt; 2'                                => 'Kids 3<6 years > 2',
			str_repeat( 'Crusty bread ', 20 )                             => rtrim( mb_substr( trim( str_repeat( 'Crusty bread ', 20 ) ), 0, 150 ) ),
		);
		$pages = array(
			'img-alt-library'       => '<p>x</p><img class="wp-image-6" src="/a.jpg">',
			'img-alt-empty-library' => '<p>x</p><img alt="" class="wp-image-6" src="/a.jpg">',
		);
		foreach ( $pages as $fix_id => $html ) {
			foreach ( $cases as $alt => $expected ) {
				$engine = self::engine( 'live', array(), \SoftGlaze\AccessibilityRepair\Engine::class, array( 'attachments' => new Softglaze_A11y_Test_Attachment_Lookup( array( 6 => array( $alt, '2026/10/a.jpg' ) ) ) ) );
				$output = $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
				$this->assertNull( $engine->recorder()->get_fallback() );
				if ( null === $expected ) {
					$this->assertSame( $html, $output, $fix_id . ': an alt that is empty as text changes nothing.' );
					continue;
				}
				$this->assertSame( $expected, self::browser_value( $output, 'img', 'alt' ), $fix_id . ': ' . $alt );
				$this->assertSame( 1, substr_count( $output, '<img' ) );
				$this->assertSame( substr_count( $html, '<' ), substr_count( $output, '<' ) );
				$this->assertSame( substr_count( $html, '>' ), substr_count( $output, '>' ) );
				$this->assertStringNotContainsString( '<script', $output );
				if ( self::wp_at_least_69() ) {
					$processor = new WP_HTML_Tag_Processor( $output );
					$processor->next_tag( 'img' );
					$this->assertSame( $expected, $processor->get_attribute( 'alt' ), 'get_attribute() on 6.9+' );
				}
			}
		}
	}

	/**
	 * F13: hostile text inside an existing content value stays inert.
	 */
	public function test_f13_hostile_content() {
		$this->assert_safe(
			'<html lang="en"><head><meta name="viewport" content="width=device-width, user-scalable=no, x=&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"></head></html>',
			Engine::SCOPE_PAGE,
			'meta',
			'content',
			'width=device-width, x=">'
		);
		$this->assert_safe(
			'<html lang="en"><head><meta name="viewport" content="width=device-width,maximum-scale=1.0,note=Tom &amp; Jerry \'s"></head></html>',
			Engine::SCOPE_PAGE,
			'meta',
			'content',
			"width=device-width, note=Tom & Jerry 's"
		);
	}

	/**
	 * F05: a hostile site name, email address, map label and translation reach the page only as plain text.
	 */
	public function test_f05_hostile_values() {
		$name = static function () {
			return '"><script>alert(1)</script> Tom &amp; Jerry\'s <b>Bakery</b>';
		};
		add_filter( 'pre_option_blogname', $name, 20 );
		$this->assert_safe( '<a href="/"><svg viewBox="0 0 1 1"></svg></a>', Engine::SCOPE_CONTENT, 'a', 'aria-label', '"> Tom & Jerry\'s Bakery home page' );
		remove_filter( 'pre_option_blogname', $name, 20 );

		$this->assert_safe( '<a href="mailto:%22%3E%3Cscript%3Ealert(1)%3C/script%3Ea&amp;b@example.com"><i class="x"></i></a>', Engine::SCOPE_CONTENT, 'a', 'aria-label', 'Email ">a&b@example.com' );

		$map = static function ( $hosts ) {
			$hosts['bakery.example'] = '"><img src=x onerror=alert(1)> Tom &amp;amp; Jerry';
			return $hosts;
		};
		add_filter( 'softglaze_a11y_host_label_map', $map );
		$this->assert_safe( '<a href="https://shop.bakery.example/"></a>', Engine::SCOPE_CONTENT, 'a', 'aria-label', '"> Tom & Jerry' );
		remove_filter( 'softglaze_a11y_host_label_map', $map );

		/* Not a real translation: hostile input for the test. */
		$this->translations = array( 'Email %s' => '"><script>alert(1)</script>&amp; %s' );
		add_filter( 'gettext', array( $this, 'filter_gettext' ), 10, 3 );
		$this->assert_safe( '<a href="mailto:hi@example.com"></a>', Engine::SCOPE_CONTENT, 'a', 'aria-label', '">& hi@example.com' );
		remove_filter( 'gettext', array( $this, 'filter_gettext' ), 10 );
	}

	/**
	 * F06: a hostile translation and icon-map label become plain text; the value repair too.
	 */
	public function test_f06_hostile_values() {
		/* Not real translations: hostile input for the test. */
		$this->translations = array(
			'Search' => '"><img src=x onerror=alert(1)> Find &amp;amp; go',
			'Submit' => '<b>Send</b> &amp; "go"',
		);
		add_filter( 'gettext', array( $this, 'filter_gettext' ), 10, 3 );
		$this->assert_safe( '<form role="search"><button></button></form>', Engine::SCOPE_CONTENT, 'button', 'aria-label', '"> Find & go' );
		$this->assert_safe( '<form><input type="submit" value=""></form>', Engine::SCOPE_CONTENT, 'input', 'value', 'Send & "go"' );
		remove_filter( 'gettext', array( $this, 'filter_gettext' ), 10 );

		$icons = static function ( $map ) {
			$map['icon-x'] = '"><script>alert(1)</script>Close &amp; leave';
			return $map;
		};
		add_filter( 'softglaze_a11y_icon_label_map', $icons );
		$this->assert_safe( '<button><span class="icon-x"></span></button>', Engine::SCOPE_CONTENT, 'button', 'aria-label', '">Close & leave' );
		remove_filter( 'softglaze_a11y_icon_label_map', $icons );
	}

	/**
	 * F08: a hostile placeholder and field-map label become plain text.
	 */
	public function test_f08_hostile_values() {
		$this->assert_safe( '<input type="text" name="n" placeholder="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt; Tom &amp;amp; Jerry">', Engine::SCOPE_CONTENT, 'input', 'aria-label', '"> Tom & Jerry' );

		$fields = static function ( $map ) {
			$map['order'] = '"><img src=x onerror=alert(1)> Order &amp; date';
			return $map;
		};
		add_filter( 'softglaze_a11y_field_label_map', $fields );
		$this->assert_safe( '<input type="text" name="order">', Engine::SCOPE_CONTENT, 'input', 'aria-label', '"> Order & date' );
		remove_filter( 'softglaze_a11y_field_label_map', $fields );
	}

	/**
	 * F19: a hostile heading in the page and a hostile post title in the more-link filter become plain text.
	 */
	public function test_f19_hostile_titles() {
		$this->assert_safe( '<article><h2>&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt; Tom &amp;amp; Jerry</h2><a href="/x">Read more</a></article>', Engine::SCOPE_CONTENT, 'a', 'aria-label', 'Read more: "> Tom & Jerry' );

		$fix    = new \SoftGlaze\AccessibilityRepair\Fixes\Fix_Read_More_Context();
		$html   = ' <a href="/p/#more-1" class="more-link">Read more</a>';
		$output = $fix->label_more_links( $html, '"><script>alert(1)</script> Tom &amp;amp; Jerry&#8217;s' );
		$this->assertSame( "Read more: \"> Tom & Jerry\u{2019}s", self::browser_value( $output, 'a', 'aria-label' ) );
		$this->assertSame( self::opener_count( $html ), self::opener_count( $output ) );
		$this->assertSame( substr_count( $html, '<' ), substr_count( $output, '<' ) );
		$this->assertStringNotContainsString( '<script', $output );
	}

	/**
	 * F21: a hostile existing aria-describedby value is kept as text and appended to.
	 */
	public function test_f21_hostile_existing_description() {
		$this->assert_safe( '<a href="https://x.example/" target="_blank" aria-describedby="&quot;&gt;&lt;script&gt;">X</a>', Engine::SCOPE_CONTENT, 'a', 'aria-describedby', '"><script> softglaze-a11y-newtab-note' );
	}

	/**
	 * F11 and F12: a hostile main id is never written into an href; our link
	 * is hidden instead and the broken skip link is left alone. A safe id is
	 * written as it is.
	 */
	public function test_f11_f12_hostile_ids() {
		$own    = '<a class="softglaze-a11y-skip-link screen-reader-text" href="#softglaze-a11y-main">Skip to content</a>';
		$broken = '<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a><a class="skip-link" href="#gone">Skip to content</a>';
		$html   = '<!DOCTYPE html><html lang="en"><head><title>T</title></head><body>' . $own . $broken . '<main id="x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"></main></body></html>';
		$engine = self::engine( 'live' );
		$output = $engine->run( $html );
		$this->assertNull( $engine->recorder()->get_fallback() );
		$this->assertSame( array( "A.hidden: NULL -> ''" ), self::token_diff( $html, $output ) );
		$this->assertStringNotContainsString( '<script', $output );

		$this->assert_safe( str_replace( 'x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', 'site-main.v2', $html ), Engine::SCOPE_PAGE, 'a', 'href', '#site-main.v2' );
	}

	/**
	 * F17: a hostile repeated id reaches the renamed field and its label only
	 * as encoded attribute text; label and field still match as a browser
	 * reads them, and no element is added. F15 and F16 write only "-1".
	 */
	public function test_f17_hostile_ids() {
		$id     = 'x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;&amp;amp;y';
		$form   = '<form><label for="' . $id . '">Phone</label><input id="' . $id . '" name="p"></form>';
		$html   = '<!DOCTYPE html><html lang="en"><head><title>T</title></head><body><main>' . $form . $form . '</main></body></html>';
		$engine = self::engine( 'live' );
		$output = $engine->run( $html );

		$this->assertNull( $engine->recorder()->get_fallback() );
		$this->assertNotSame( $html, $output );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertSame( self::opener_count( $html ), self::opener_count( $output ) );
		$this->assertSame( substr_count( $html, '<' ), substr_count( $output, '<' ) );
		$this->assertSame( substr_count( $html, '>' ), substr_count( $output, '>' ) );

		preg_match_all( '/<input\b[^>]*?\sid="([^"]*)"/', $output, $ids );
		preg_match_all( '/<label\b[^>]*?\sfor="([^"]*)"/', $output, $fors );
		$decode = static function ( $value ) {
			return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		};
		$this->assertSame( $decode( $id ), $decode( $ids[1][0] ), 'The first field keeps its id.' );
		$this->assertSame( $decode( $ids[1][1] ), $decode( $fors[1][1] ), 'The second label points at the second field.' );
		$this->assertStringStartsWith( 'x"><script>alert(1)</script>&', $decode( $ids[1][1] ) );
		$this->assertStringEndsWith( 'y-softglaze-a11y-2', $decode( $ids[1][1] ) );
		if ( self::wp_at_least_69() ) {
			$this->assertSame( $decode( $id ) . '-softglaze-a11y-2', $decode( $ids[1][1] ) );
		}
	}

	/**
	 * F18: hostile menu names and location descriptions reach the nav
	 * element only as text that core's esc_attr() encodes; no element is added.
	 */
	public function test_f18_hostile_labels() {
		$fix = new SoftGlaze\AccessibilityRepair\Fixes\Fix_Nav_Labels();
		register_nav_menus( array( 'evil' => '"><script>alert(1)</script>Tom &amp; Jerry' ) );
		$menu_id = wp_create_nav_menu( 'Menu "><img src=x onerror=alert(2)>' );
		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Visit',
				'menu-item-url'    => 'https://example.org/visit/',
				'menu-item-status' => 'publish',
			)
		);
		set_theme_mod( 'nav_menu_locations', array( 'evil' => $menu_id ) );
		add_filter( 'wp_nav_menu_args', array( $fix, 'filter_args' ) );

		$by_location = wp_nav_menu(
			array(
				'theme_location' => 'evil',
				'container'      => 'nav',
				'echo'           => false,
			)
		);
		$by_name     = wp_nav_menu(
			array(
				'menu'      => $menu_id,
				'container' => 'nav',
				'echo'      => false,
			)
		);
		remove_filter( 'wp_nav_menu_args', array( $fix, 'filter_args' ) );
		unregister_nav_menu( 'evil' );

		foreach ( array( $by_location, $by_name ) as $html ) {
			$this->assertStringNotContainsString( '<script', $html );
			$this->assertStringNotContainsString( '<img', $html );
			$this->assertSame( 1, substr_count( $html, '<nav ' ) );
		}
		$this->assertSame( '">Tom & Jerry', self::browser_value( $by_location, 'nav', 'aria-label' ) );
		$this->assertStringStartsWith( 'Menu "', (string) self::browser_value( $by_name, 'nav', 'aria-label' ) );
		$this->assertStringNotContainsString( '<', (string) self::browser_value( $by_name, 'nav', 'aria-label' ) );
	}
}
