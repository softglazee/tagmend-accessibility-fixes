<?php
/**
 * F04 product-img-alt-name without WooCommerce: a test double supplies the
 * product of the page; the real class stays inert because no WooCommerce
 * function exists. The integration test runs the fix as a callback inside
 * core's wp_get_attachment_image(), so core's own escaping is exercised on
 * 6.5 and 7.1 (ET).
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Fixes\Fix_Product_Img_Alt_Name;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * F04 tests.
 */
class Test_Fix_Product_Image extends Softglaze_A11y_Engine_TestCase {

	/**
	 * An attachment-like object.
	 *
	 * @param int $id Id.
	 * @return stdClass
	 */
	private static function attachment( $id ) {
		$attachment     = new stdClass();
		$attachment->ID = $id;
		return $attachment;
	}

	/**
	 * A product double: product 40 with main image 41 and gallery 42, 43.
	 *
	 * @param string $name Product name.
	 * @return Softglaze_A11y_Test_Product_Fix
	 */
	private static function fix( $name = 'Rye sourdough loaf' ) {
		return new Softglaze_A11y_Test_Product_Fix(
			array(
				'id'        => 40,
				'name'      => $name,
				'image_ids' => array( 41, 42, 43 ),
			)
		);
	}

	/**
	 * Empty, blank or missing alt on the product's own images gets the name; anything else stays.
	 */
	public function test_decisions() {
		$fix = self::fix();
		$this->assertSame( array( 'alt' => 'Rye sourdough loaf' ), $fix->filter_attributes( array( 'alt' => '' ), self::attachment( 41 ) ) );
		$this->assertSame( array( 'alt' => 'Rye sourdough loaf' ), $fix->filter_attributes( array( 'alt' => '  ' ), self::attachment( 43 ) ) );
		$this->assertSame(
			array(
				'class' => 'wp-post-image',
				'alt'   => 'Rye sourdough loaf',
			),
			$fix->filter_attributes( array( 'class' => 'wp-post-image' ), self::attachment( 42 ) )
		);
		$this->assertSame( array( 'alt' => 'Loaf, sliced' ), $fix->filter_attributes( array( 'alt' => 'Loaf, sliced' ), self::attachment( 41 ) ), 'A library alt is kept.' );
		$this->assertSame( array( 'alt' => '' ), $fix->filter_attributes( array( 'alt' => '' ), self::attachment( 99 ) ), 'Another product\'s image.' );
		$this->assertSame( array( 'alt' => '' ), $fix->filter_attributes( array( 'alt' => '' ), null ) );
		$this->assertSame( array( 'alt' => '' ), $fix->filter_attributes( array( 'alt' => '' ), 'not an attachment' ) );

		$fix->in_loop = true;
		$this->assertSame( array( 'alt' => '' ), $fix->filter_attributes( array( 'alt' => '' ), self::attachment( 41 ) ), 'Never in product loops.' );

		$none = new Softglaze_A11y_Test_Product_Fix( null );
		$this->assertSame( array( 'alt' => '' ), $none->filter_attributes( array( 'alt' => '' ), self::attachment( 41 ) ), 'Not a single product page.' );

		$this->assertSame( array( 'alt' => '' ), self::fix( '<b></b>' )->filter_attributes( array( 'alt' => '' ), self::attachment( 41 ) ), 'A name that is empty as text changes nothing.' );
	}

	/**
	 * The real class without WooCommerce: no WooCommerce function exists, so it changes nothing.
	 */
	public function test_inert_without_woocommerce() {
		$this->assertFalse( function_exists( 'is_product' ) );
		$this->assertFalse( function_exists( 'wc_get_product' ) );
		$fix = new Fix_Product_Img_Alt_Name();
		$this->assertSame( array( 'alt' => '' ), $fix->filter_attributes( array( 'alt' => '' ), self::attachment( 41 ) ) );
		$this->assertSame( 'Needs WooCommerce', \SoftGlaze\AccessibilityRepair\Plugin::instance()->registry()->unavailable_reason( $fix ) );
	}

	/**
	 * ET: a hostile product name reaches the page through core's
	 * wp_get_attachment_image() as plain, encoded text and adds no element.
	 */
	public function test_hostile_name_through_core() {
		$id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/10/product-rye.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => 640,
				'height' => 420,
				'file'   => '2026/10/product-rye.jpg',
				'sizes'  => array(),
			)
		);

		$cases = array(
			'"><script>alert(1)</script> Bread & Butter &amp; \'Rye\'' => '"> Bread & Butter & \'Rye\'',
			'Baker&#8217;s <b>choice</b> &amp;amp; more'               => "Baker\u{2019}s choice & more",
			str_repeat( 'Long name ', 30 )                              => rtrim( mb_substr( trim( str_repeat( 'Long name ', 30 ) ), 0, 150 ) ),
		);
		foreach ( $cases as $name => $expected ) {
			$fix = new Softglaze_A11y_Test_Product_Fix(
				array(
					'id'        => 40,
					'name'      => $name,
					'image_ids' => array( $id ),
				)
			);
			add_filter( 'wp_get_attachment_image_attributes', array( $fix, 'filter_attributes' ), 20, 2 );
			$html = wp_get_attachment_image( $id, 'full' );
			remove_filter( 'wp_get_attachment_image_attributes', array( $fix, 'filter_attributes' ), 20 );

			$this->assertSame( 1, substr_count( $html, '<' ), $name );
			$this->assertSame( 1, substr_count( $html, '>' ), $name );
			$this->assertStringNotContainsString( '<script', $html );
			$this->assertSame( $expected, self::browser_value( $html, 'img', 'alt' ), $name );
		}
	}
}
