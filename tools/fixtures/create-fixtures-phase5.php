<?php
/**
 * Creates or refreshes the Phase 5 fixtures (docs/05-TEST-PLAN.md section 1.3)
 * on the Docker dev site: the WooCommerce products, a classic cart page, the
 * Elementor page, the header settings of Astra and Kadence (search, cart,
 * account, social icons), and one item in the local admin's persistent cart.
 * Never shipped. Idempotent: attachments are found again by the _a11y_fx_key
 * meta, products by SKU, pages by slug, the menu and the Contact Form 7 form
 * by name.
 *
 * Run (see tests/fixtures/staging-pages.md), with WooCommerce, Elementor and
 * Contact Form 7 active:
 *   docker cp tools/fixtures/create-fixtures-phase5.php sgl-p3-cli:/tmp/create-fixtures-phase5.php
 *   docker exec sgl-p3-cli wp eval-file /tmp/create-fixtures-phase5.php --user=sgladmin
 *
 * Invented content for the "Example Bakery" site; images are drawn with GD.
 *
 * @package SoftGlaze\AccessibilityRepair\Fixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

if ( ! current_user_can( 'unfiltered_html' ) ) {
	WP_CLI::error( 'Run with --user=<an administrator>: the Elementor and HTML content needs unfiltered_html.' );
}
foreach ( array( 'WooCommerce' => 'WooCommerce', 'Elementor\\Plugin' => 'Elementor', 'WPCF7_ContactForm' => 'Contact Form 7' ) as $a11y_fx5_class => $a11y_fx5_name ) {
	if ( ! class_exists( $a11y_fx5_class ) ) {
		WP_CLI::error( $a11y_fx5_name . ' must be active.' );
	}
}

/**
 * Draws a square-ish product picture and returns the temp file path.
 *
 * @param string $key   File key.
 * @param int    $w     Width.
 * @param int    $h     Height.
 * @param array  $bg    RGB background.
 * @param string $label Text drawn on the image.
 * @return string
 */
function a11y_fx5_draw( $key, $w, $h, $bg, $label ) {
	$im    = imagecreatetruecolor( $w, $h );
	$back  = imagecolorallocate( $im, $bg[0], $bg[1], $bg[2] );
	$light = imagecolorallocate( $im, 255, 248, 231 );
	$dark  = imagecolorallocate( $im, 70, 40, 18 );
	imagefilledrectangle( $im, 0, 0, $w, $h, $back );
	imagefilledellipse( $im, (int) ( $w / 2 ), (int) ( $h / 2 ), (int) ( $w * 0.7 ), (int) ( $h * 0.45 ), $dark );
	imagefilledellipse( $im, (int) ( $w / 2 ), (int) ( $h / 2 - $h * 0.04 ), (int) ( $w * 0.62 ), (int) ( $h * 0.34 ), $light );
	imagestring( $im, 5, 12, $h - 26, $label, $light );
	$file = get_temp_dir() . 'a11y-fx-' . $key . '.jpg';
	imagejpeg( $im, $file, 85 );
	imagedestroy( $im );
	return $file;
}

/**
 * Returns the attachment id for a fixture key, importing the file the first
 * time; the library alt text is always left empty (the Phase 5 failures).
 *
 * @param string $key   Fixture key, stored in _a11y_fx_key.
 * @param string $file  Source file path.
 * @param string $title Attachment title.
 * @return int
 */
function a11y_fx5_attachment( $key, $file, $title ) {
	$found = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_key'    => '_a11y_fx_key',
			'meta_value'  => $key,
			'fields'      => 'ids',
			'numberposts' => 1,
		)
	);
	if ( $found ) {
		$id = (int) $found[0];
	} else {
		$tmp = wp_tempnam( basename( $file ) );
		copy( $file, $tmp );
		$id = media_handle_sideload(
			array(
				'name'     => basename( $file ),
				'tmp_name' => $tmp,
			),
			0,
			$title
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $key . ': ' . $id->get_error_message() );
		}
		update_post_meta( $id, '_a11y_fx_key', $key );
	}
	delete_post_meta( $id, '_wp_attachment_image_alt' );
	return $id;
}

/**
 * Creates or updates a published page by slug and returns its id.
 *
 * @param string $slug    Page slug.
 * @param string $title   Page title.
 * @param string $content Block markup.
 * @return int
 */
function a11y_fx5_page( $slug, $title, $content ) {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	$data     = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => $slug,
		'post_title'   => $title,
		'post_content' => $content,
	);
	if ( $existing ) {
		$data['ID'] = $existing->ID;
		$id         = wp_update_post( wp_slash( $data ), true );
	} else {
		$id = wp_insert_post( wp_slash( $data ), true );
	}
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $slug . ': ' . $id->get_error_message() );
	}
	return (int) $id;
}

/**
 * The product with this SKU, or a new object of the given class.
 *
 * @param string $sku   SKU.
 * @param string $class Product class for a new product.
 * @return WC_Product
 */
function a11y_fx5_product( $sku, $class ) {
	$id = wc_get_product_id_by_sku( $sku );
	if ( $id ) {
		$product = wc_get_product( $id );
		if ( $product instanceof $class ) {
			return $product;
		}
	}
	$product = new $class();
	$product->set_sku( $sku );
	return $product;
}

/**
 * Creates or updates a Contact Form 7 form by title and returns its shortcode.
 *
 * @param string $title Form title.
 * @param string $form  Form template.
 * @return string
 */
function a11y_fx5_cf7( $title, $form ) {
	$found = get_posts(
		array(
			'post_type'   => 'wpcf7_contact_form',
			'title'       => $title,
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	$cf7   = $found ? WPCF7_ContactForm::get_instance( $found[0] ) : WPCF7_ContactForm::get_template( array( 'title' => $title ) );
	$props = $cf7->get_properties();

	$props['form'] = $form;
	$cf7->set_properties( $props );
	$cf7->save();
	return WPCF7_ContactForm::get_instance( $cf7->id() )->shortcode();
}

/**
 * One Elementor element.
 *
 * @param string $id       Seven-character element id.
 * @param string $type     container or widget.
 * @param array  $settings Settings.
 * @param array  $children Child elements (containers) or the widget type (widgets).
 * @return array
 */
function a11y_fx5_el( $id, $type, $settings, $children = array() ) {
	if ( 'container' === $type ) {
		return array(
			'id'       => $id,
			'elType'   => 'container',
			'settings' => (object) $settings,
			'elements' => $children,
			'isInner'  => false,
		);
	}
	return array(
		'id'         => $id,
		'elType'     => 'widget',
		'widgetType' => $type,
		'settings'   => (object) $settings,
		'elements'   => array(),
	);
}

/**
 * An Elementor link setting.
 *
 * @param string $url URL.
 * @return array
 */
function a11y_fx5_link( $url ) {
	return array(
		'url'               => $url,
		'is_external'       => '',
		'nofollow'          => '',
		'custom_attributes' => '',
	);
}

$a11y_fx5_home = untrailingslashit( home_url() );
$a11y_fx5_ids  = array();

// WooCommerce's site visibility: Live. A new store is switched to "Coming soon"
// (store pages only) the first time its admin screens load, which hides the shop,
// product, cart and account pages from anonymous visitors and therefore from axe.
update_option( 'woocommerce_coming_soon', 'no' );

// ---- Media (library alt text left empty on purpose) -------------------------------
$a11y_fx5_media = array(
	'loaf-product'   => array( 640, 640, array( 150, 96, 44 ), 'Loaf', 'Country sourdough loaf' ),
	'loaf-gallery-1' => array( 640, 640, array( 120, 80, 40 ), 'Crumb', 'Sourdough crumb' ),
	'loaf-gallery-2' => array( 640, 640, array( 176, 120, 66 ), 'Crust', 'Sourdough crust' ),
	'cake-product'   => array( 640, 640, array( 84, 46, 30 ), 'Cake', 'Celebration cake' ),
	'counter'        => array( 800, 500, array( 196, 150, 98 ), 'Counter', 'Bakery counter' ),
);
foreach ( $a11y_fx5_media as $a11y_fx5_key => $a11y_fx5_m ) {
	$a11y_fx5_file                 = a11y_fx5_draw( $a11y_fx5_key, $a11y_fx5_m[0], $a11y_fx5_m[1], $a11y_fx5_m[2], $a11y_fx5_m[3] );
	$a11y_fx5_ids[ $a11y_fx5_key ] = a11y_fx5_attachment( $a11y_fx5_key, $a11y_fx5_file, $a11y_fx5_m[4] );
}

// ---- Products ------------------------------------------------------------------
$a11y_fx5_cat = get_term_by( 'slug', 'a11y-fx-bakery', 'product_cat' );
if ( ! $a11y_fx5_cat ) {
	$a11y_fx5_new = wp_insert_term( 'A11Y FX Bakery', 'product_cat', array( 'slug' => 'a11y-fx-bakery' ) );
	if ( is_wp_error( $a11y_fx5_new ) ) {
		WP_CLI::error( 'Category: ' . $a11y_fx5_new->get_error_message() );
	}
	$a11y_fx5_cat_id = (int) $a11y_fx5_new['term_id'];
} else {
	$a11y_fx5_cat_id = (int) $a11y_fx5_cat->term_id;
}
$a11y_fx5_ids['product_cat'] = $a11y_fx5_cat_id;

$a11y_fx5_loaf = a11y_fx5_product( 'A11YFX-LOAF', 'WC_Product_Simple' );
$a11y_fx5_loaf->set_name( 'Country sourdough loaf' );
$a11y_fx5_loaf->set_slug( 'a11y-fx-product' );
$a11y_fx5_loaf->set_status( 'publish' );
$a11y_fx5_loaf->set_catalog_visibility( 'visible' );
$a11y_fx5_loaf->set_regular_price( '6.50' );
$a11y_fx5_loaf->set_short_description( 'A slow-proved loaf with a dark, crackling crust. Baked every morning.' );
$a11y_fx5_loaf->set_description( 'Our country sourdough rises for 36 hours before it goes into the wood-fired oven. Flour, water, salt and our own starter, nothing else.' );
$a11y_fx5_loaf->set_image_id( $a11y_fx5_ids['loaf-product'] );
$a11y_fx5_loaf->set_gallery_image_ids( array( $a11y_fx5_ids['loaf-gallery-1'], $a11y_fx5_ids['loaf-gallery-2'] ) );
$a11y_fx5_loaf->set_category_ids( array( $a11y_fx5_cat_id ) );
$a11y_fx5_loaf->set_manage_stock( false );
$a11y_fx5_loaf->set_stock_status( 'instock' );
$a11y_fx5_ids['product_simple'] = $a11y_fx5_loaf->save();

$a11y_fx5_size = new WC_Product_Attribute();
$a11y_fx5_size->set_name( 'Size' );
$a11y_fx5_size->set_options( array( '6 inch', '8 inch' ) );
$a11y_fx5_size->set_position( 0 );
$a11y_fx5_size->set_visible( true );
$a11y_fx5_size->set_variation( true );

$a11y_fx5_cake = a11y_fx5_product( 'A11YFX-CAKE', 'WC_Product_Variable' );
$a11y_fx5_cake->set_name( 'Celebration cake' );
$a11y_fx5_cake->set_slug( 'a11y-fx-product-variable' );
$a11y_fx5_cake->set_status( 'publish' );
$a11y_fx5_cake->set_catalog_visibility( 'visible' );
$a11y_fx5_cake->set_short_description( 'Three layers of chocolate sponge with salted caramel. Choose a size.' );
$a11y_fx5_cake->set_description( 'Order at least two days ahead. Collect from the shop on Friday or Saturday.' );
$a11y_fx5_cake->set_image_id( $a11y_fx5_ids['cake-product'] );
$a11y_fx5_cake->set_category_ids( array( $a11y_fx5_cat_id ) );
$a11y_fx5_cake->set_attributes( array( $a11y_fx5_size ) );
$a11y_fx5_ids['product_variable'] = $a11y_fx5_cake->save();

$a11y_fx5_prices = array(
	'6 inch' => '28.00',
	'8 inch' => '36.00',
);
$a11y_fx5_existing = array();
foreach ( $a11y_fx5_cake->get_children() as $a11y_fx5_child_id ) {
	$a11y_fx5_child = wc_get_product( $a11y_fx5_child_id );
	if ( $a11y_fx5_child ) {
		$a11y_fx5_existing[ (string) $a11y_fx5_child->get_attribute( 'size' ) ] = $a11y_fx5_child;
	}
}
foreach ( $a11y_fx5_prices as $a11y_fx5_option => $a11y_fx5_price ) {
	$a11y_fx5_variation = isset( $a11y_fx5_existing[ $a11y_fx5_option ] ) ? $a11y_fx5_existing[ $a11y_fx5_option ] : new WC_Product_Variation();
	$a11y_fx5_variation->set_parent_id( $a11y_fx5_ids['product_variable'] );
	$a11y_fx5_variation->set_attributes( array( 'size' => $a11y_fx5_option ) );
	$a11y_fx5_variation->set_regular_price( $a11y_fx5_price );
	$a11y_fx5_variation->set_status( 'publish' );
	$a11y_fx5_variation->set_stock_status( 'instock' );
	$a11y_fx5_ids[ 'variation_' . sanitize_key( $a11y_fx5_option ) ] = $a11y_fx5_variation->save();
}
WC_Product_Variable::sync( $a11y_fx5_ids['product_variable'] );
wc_delete_product_transients( $a11y_fx5_ids['product_variable'] );

// ---- Classic cart page (the WooCommerce Cart page itself keeps the Cart block) ----
$a11y_fx5_ids['page_cart_classic'] = a11y_fx5_page(
	'a11y-fx-cart-classic',
	'A11Y FX Classic cart',
	'<!-- wp:shortcode -->' . "\n" . '[woocommerce_cart]' . "\n" . '<!-- /wp:shortcode -->'
);

// ---- Menu for the WordPress "Navigation Menu" widget inside Elementor ------------
$a11y_fx5_menu = wp_get_nav_menu_object( 'A11Y FX Find us' );
$a11y_fx5_menu_id = $a11y_fx5_menu ? (int) $a11y_fx5_menu->term_id : (int) wp_create_nav_menu( 'A11Y FX Find us' );
if ( ! $a11y_fx5_menu ) {
	foreach ( array( 'Opening hours' => '/opening-hours/', 'Delivery' => '/delivery/', 'Allergens' => '/allergens/' ) as $a11y_fx5_title => $a11y_fx5_path ) {
		wp_update_nav_menu_item(
			$a11y_fx5_menu_id,
			0,
			array(
				'menu-item-title'  => $a11y_fx5_title,
				'menu-item-url'    => $a11y_fx5_home . $a11y_fx5_path,
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			)
		);
	}
}
$a11y_fx5_ids['nav_menu'] = $a11y_fx5_menu_id;

// ---- Contact Form 7 newsletter form with a fixed field id (placed twice below) ----
// The field keeps CF7's default name (your-email); the id: option and the label's
// for attribute are what a copied template carries into both places.
$a11y_fx5_newsletter = a11y_fx5_cf7(
	'A11Y FX newsletter',
	implode(
		"\n",
		array(
			'<label for="newsletter-email">Email for the weekend offers</label>',
			'[email* your-email id:newsletter-email]',
			'',
			'[submit "Sign up"]',
		)
	)
);

// ---- a11y-fx-elementor -----------------------------------------------------------
$a11y_fx5_counter_url = wp_get_attachment_url( $a11y_fx5_ids['counter'] );
$a11y_fx5_video       = get_posts(
	array(
		'post_type'   => 'attachment',
		'post_status' => 'inherit',
		'meta_key'    => '_a11y_fx_key',
		'meta_value'  => 'video',
		'fields'      => 'ids',
		'numberposts' => 1,
	)
);
$a11y_fx5_video_url   = $a11y_fx5_video ? wp_get_attachment_url( $a11y_fx5_video[0] ) : '';
if ( '' === $a11y_fx5_video_url ) {
	WP_CLI::warning( 'The Phase 0 video attachment is missing; the background-video container is left out.' );
}

$a11y_fx5_newsletter_block = static function ( $prefix ) use ( $a11y_fx5_newsletter ) {
	return a11y_fx5_el(
		$prefix . '00',
		'container',
		array( '_element_id' => 'bakery-newsletter' ),
		array(
			a11y_fx5_el(
				$prefix . '01',
				'heading',
				array(
					'title'       => 'Weekend offers by email',
					'header_size' => 'h2',
				)
			),
			a11y_fx5_el( $prefix . '02', 'shortcode', array( 'shortcode' => $a11y_fx5_newsletter ) ),
		)
	);
};

$a11y_fx5_elements = array(
	a11y_fx5_el(
		'a11e100',
		'container',
		array(),
		array(
			a11y_fx5_el(
				'a11e101',
				'heading',
				array(
					'title'       => 'Cakes and bread from Example Bakery',
					'header_size' => 'h2',
				)
			),
			a11y_fx5_el(
				'a11e102',
				'button',
				array(
					'text' => 'Order a cake',
					'link' => a11y_fx5_link( $a11y_fx5_home . '/order/' ),
				)
			),
		)
	),
	a11y_fx5_el(
		'a11e200',
		'container',
		array( 'flex_direction' => 'row' ),
		array(
			a11y_fx5_el(
				'a11e201',
				'icon',
				array(
					'selected_icon' => array(
						'value'   => 'fas fa-phone-alt',
						'library' => 'fa-solid',
					),
					'link'          => a11y_fx5_link( 'tel:+441234567890' ),
				)
			),
			a11y_fx5_el(
				'a11e202',
				'icon',
				array(
					'selected_icon' => array(
						'value'   => 'fab fa-whatsapp',
						'library' => 'fa-brands',
					),
					'link'          => a11y_fx5_link( 'https://wa.me/441234567890' ),
				)
			),
			a11y_fx5_el(
				'a11e203',
				'icon',
				array(
					'selected_icon' => array(
						'value'   => 'fas fa-shopping-cart',
						'library' => 'fa-solid',
					),
					'link'          => a11y_fx5_link( wc_get_cart_url() ),
				)
			),
			a11y_fx5_el(
				'a11e204',
				'icon',
				array(
					'selected_icon' => array(
						'value'   => 'far fa-clock',
						'library' => 'fa-regular',
					),
					'link'          => a11y_fx5_link( $a11y_fx5_home . '/opening-hours/' ),
				)
			),
			a11y_fx5_el(
				'a11e205',
				'icon-box',
				array(
					'selected_icon'    => array(
						'value'   => 'fas fa-envelope',
						'library' => 'fa-solid',
					),
					'title_text'       => '',
					'description_text' => '',
					'link'             => a11y_fx5_link( 'mailto:orders@example.com' ),
				)
			),
			a11y_fx5_el(
				'a11e206',
				'icon-box',
				array(
					'selected_icon'    => array(
						'value'   => 'fas fa-map-marker-alt',
						'library' => 'fa-solid',
					),
					'title_text'       => 'Find the shop',
					'description_text' => '12 Example Street, open from 7:00.',
					'link'             => a11y_fx5_link( $a11y_fx5_home . '/visit/' ),
				)
			),
		)
	),
	a11y_fx5_el(
		'a11e300',
		'container',
		array(),
		array(
			a11y_fx5_el(
				'a11e301',
				'social-icons',
				array(
					'social_icon_list' => array(
						array(
							'_id'         => 'a11e3a1',
							'social_icon' => array(
								'value'   => 'fab fa-facebook',
								'library' => 'fa-brands',
							),
							'link'        => a11y_fx5_link( 'https://www.facebook.com/examplebakery' ),
						),
						array(
							'_id'         => 'a11e3a2',
							'social_icon' => array(
								'value'   => 'fab fa-instagram',
								'library' => 'fa-brands',
							),
							'link'        => a11y_fx5_link( 'https://www.instagram.com/examplebakery' ),
						),
						array(
							'_id'         => 'a11e3a3',
							'social_icon' => array(
								'value'   => 'fab fa-x-twitter',
								'library' => 'fa-brands',
							),
							'link'        => a11y_fx5_link( 'https://x.com/examplebakery' ),
						),
					),
				)
			),
			a11y_fx5_el(
				'a11e302',
				'image',
				array(
					'image'         => array(
						'id'  => $a11y_fx5_ids['counter'],
						'url' => $a11y_fx5_counter_url,
					),
					'image_size'    => 'large',
					'link_to'       => 'file',
					'open_lightbox' => 'default',
				)
			),
			a11y_fx5_el(
				'a11e303',
				'wp-widget-nav_menu',
				array(
					'wp' => array(
						'title'    => '',
						'nav_menu' => (string) $a11y_fx5_menu_id,
					),
				)
			),
		)
	),
);
if ( '' !== $a11y_fx5_video_url ) {
	$a11y_fx5_elements[] = a11y_fx5_el(
		'a11e400',
		'container',
		array(
			'background_background' => 'video',
			'background_video_link' => $a11y_fx5_video_url,
			'min_height'            => array(
				'unit' => 'px',
				'size' => 220,
			),
		),
		array(
			a11y_fx5_el(
				'a11e401',
				'heading',
				array(
					'title'       => 'Inside the bakehouse',
					'header_size' => 'h2',
				)
			),
		)
	);
}
$a11y_fx5_elements[] = $a11y_fx5_newsletter_block( 'a11e5' );
$a11y_fx5_elements[] = $a11y_fx5_newsletter_block( 'a11e6' );

// Elementor's own setting (Settings > Advanced > Google Fonts: Disable), so axe runs
// on this private site never wait for fonts.googleapis.com.
update_option( 'elementor_google_font', '0' );

$a11y_fx5_ids['page_elementor'] = a11y_fx5_page( 'a11y-fx-elementor', 'A11Y FX Elementor', '' );
$a11y_fx5_document              = \Elementor\Plugin::$instance->documents->get( $a11y_fx5_ids['page_elementor'], false );
if ( ! $a11y_fx5_document ) {
	WP_CLI::error( 'Elementor did not return a document for the page.' );
}
$a11y_fx5_document->set_is_built_with_elementor( true );
$a11y_fx5_saved = $a11y_fx5_document->save(
	array(
		'elements' => json_decode( wp_json_encode( $a11y_fx5_elements ), true ),
		'settings' => array( 'post_status' => 'publish' ),
	)
);
if ( ! $a11y_fx5_saved ) {
	WP_CLI::error( 'Elementor refused to save the page.' );
}
\Elementor\Plugin::$instance->files_manager->clear_cache();

// ---- Astra and Kadence headers: search, account, cart and social icons -----------
$a11y_fx5_astra = get_option( 'astra-settings', array() );
$a11y_fx5_astra = is_array( $a11y_fx5_astra ) ? $a11y_fx5_astra : array();

$a11y_fx5_astra['header-desktop-items'] = array(
	'popup'   => array( 'popup_content' => array( 'mobile-menu' ) ),
	'above'   => array(
		'above_left'         => array(),
		'above_left_center'  => array(),
		'above_center'       => array(),
		'above_right_center' => array(),
		'above_right'        => array(),
	),
	'primary' => array(
		'primary_left'         => array( 'logo' ),
		'primary_left_center'  => array(),
		'primary_center'       => array(),
		'primary_right_center' => array(),
		'primary_right'        => array( 'menu-1', 'search', 'account', 'woo-cart', 'social-icons-1' ),
	),
	'below'   => array(
		'below_left'         => array(),
		'below_left_center'  => array(),
		'below_center'       => array(),
		'below_right_center' => array(),
		'below_right'        => array(),
	),
);
$a11y_fx5_astra['header-mobile-items']  = array(
	'popup'   => array( 'popup_content' => array( 'mobile-menu' ) ),
	'above'   => array(
		'above_left'   => array(),
		'above_center' => array(),
		'above_right'  => array(),
	),
	'primary' => array(
		'primary_left'   => array( 'logo' ),
		'primary_center' => array(),
		'primary_right'  => array( 'search', 'woo-cart', 'mobile-trigger' ),
	),
	'below'   => array(
		'below_left'   => array(),
		'below_center' => array(),
		'below_right'  => array(),
	),
);
$a11y_fx5_astra['header-social-icons-1'] = array(
	'items' => array(
		array(
			'id'         => 'facebook',
			'enabled'    => true,
			'source'     => 'icon',
			'url'        => 'https://www.facebook.com/examplebakery',
			'color'      => '#557dbc',
			'background' => 'transparent',
			'icon'       => 'facebook',
			'label'      => 'Facebook',
		),
		array(
			'id'         => 'instagram',
			'enabled'    => true,
			'source'     => 'icon',
			'url'        => 'https://www.instagram.com/examplebakery',
			'color'      => '#8a3ab9',
			'background' => 'transparent',
			'icon'       => 'instagram',
			'label'      => 'Instagram',
		),
	),
);
update_option( 'astra-settings', $a11y_fx5_astra );

$a11y_fx5_kadence = get_option( 'theme_mods_kadence', array() );
$a11y_fx5_kadence = is_array( $a11y_fx5_kadence ) ? $a11y_fx5_kadence : array();

$a11y_fx5_kadence['header_desktop_items'] = array(
	'top'    => array(
		'top_left'         => array(),
		'top_left_center'  => array(),
		'top_center'       => array(),
		'top_right_center' => array(),
		'top_right'        => array(),
	),
	'main'   => array(
		'main_left'         => array( 'logo' ),
		'main_left_center'  => array(),
		'main_center'       => array(),
		'main_right_center' => array(),
		'main_right'        => array( 'navigation', 'search', 'cart', 'social' ),
	),
	'bottom' => array(
		'bottom_left'         => array(),
		'bottom_left_center'  => array(),
		'bottom_center'       => array(),
		'bottom_right_center' => array(),
		'bottom_right'        => array(),
	),
);
$a11y_fx5_kadence['header_mobile_items']  = array(
	'popup'  => array( 'popup_content' => array( 'mobile-navigation' ) ),
	'top'    => array(
		'top_left'   => array(),
		'top_center' => array(),
		'top_right'  => array(),
	),
	'main'   => array(
		'main_left'   => array( 'mobile-logo' ),
		'main_center' => array(),
		'main_right'  => array( 'search', 'mobile-cart', 'popup-toggle' ),
	),
	'bottom' => array(
		'bottom_left'   => array(),
		'bottom_center' => array(),
		'bottom_right'  => array(),
	),
);
$a11y_fx5_kadence['header_cart_style']    = 'slide';
$a11y_fx5_kadence['facebook_link']        = 'https://www.facebook.com/examplebakery';
$a11y_fx5_kadence['instagram_link']       = 'https://www.instagram.com/examplebakery';
$a11y_fx5_kadence['twitter_link']         = 'https://x.com/examplebakery';
update_option( 'theme_mods_kadence', $a11y_fx5_kadence );

// ---- One loaf in the local admin's persistent cart (admin scans of the cart pages) ----
$a11y_fx5_admin = wp_get_current_user();
$a11y_fx5_key   = md5( (string) $a11y_fx5_ids['product_simple'] );
update_user_meta(
	$a11y_fx5_admin->ID,
	'_woocommerce_persistent_cart_' . get_current_blog_id(),
	array(
		'cart' => array(
			$a11y_fx5_key => array(
				'key'          => $a11y_fx5_key,
				'product_id'   => $a11y_fx5_ids['product_simple'],
				'variation_id' => 0,
				'variation'    => array(),
				'quantity'     => 1,
				'data_hash'    => wc_get_cart_item_data_hash( wc_get_product( $a11y_fx5_ids['product_simple'] ) ),
			),
		),
	)
);

foreach ( array( 'page_elementor', 'page_cart_classic', 'product_simple', 'product_variable' ) as $a11y_fx5_key ) {
	WP_CLI::log( sprintf( 'page        %-22s ID %d  %s', $a11y_fx5_key, $a11y_fx5_ids[ $a11y_fx5_key ], get_permalink( $a11y_fx5_ids[ $a11y_fx5_key ] ) ) );
}
foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $a11y_fx5_page_key ) {
	$a11y_fx5_page_id = wc_get_page_id( $a11y_fx5_page_key );
	WP_CLI::log( sprintf( 'woocommerce %-22s ID %d  %s', $a11y_fx5_page_key, $a11y_fx5_page_id, get_permalink( $a11y_fx5_page_id ) ) );
}
foreach ( $a11y_fx5_ids as $a11y_fx5_key => $a11y_fx5_id ) {
	if ( 0 === strpos( $a11y_fx5_key, 'page_' ) || 0 === strpos( $a11y_fx5_key, 'product_s' ) || 'product_variable' === $a11y_fx5_key ) {
		continue;
	}
	WP_CLI::log( sprintf( 'object      %-22s ID %d', $a11y_fx5_key, $a11y_fx5_id ) );
}
WP_CLI::log( 'newsletter  ' . $a11y_fx5_newsletter );
WP_CLI::success( 'Phase 5 fixtures created or refreshed.' );
