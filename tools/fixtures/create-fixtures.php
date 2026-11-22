<?php
/**
 * Creates or refreshes the local fixture pages (docs/05-TEST-PLAN.md section 1.3)
 * on the Docker dev site. Never shipped. Idempotent: attachments are found again by
 * the _a11y_fx_key meta, pages by slug, the Contact Form 7 form by title.
 *
 * Run (see tests/fixtures/staging-pages.md):
 *   docker cp tools/fixtures/create-fixtures.php sgl-p3-cli:/tmp/create-fixtures.php
 *   docker cp <video.webm> sgl-p3-cli:/tmp/bakery-oven.webm
 *   docker exec sgl-p3-cli wp eval-file /tmp/create-fixtures.php /tmp/bakery-oven.webm --user=sgladmin
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
	WP_CLI::error( 'Run with --user=<an administrator>: the Custom HTML blocks need unfiltered_html.' );
}

$a11y_fx_video_source = isset( $args[0] ) ? $args[0] : '';

/**
 * Draws a simple picture with a caption line and returns the temp file path.
 *
 * @param string $key   File key.
 * @param string $ext   jpg or png.
 * @param int    $w     Width.
 * @param int    $h     Height.
 * @param array  $bg    RGB background.
 * @param string $label Text drawn on the image.
 * @return string
 */
function a11y_fx_draw( $key, $ext, $w, $h, $bg, $label ) {
	$im    = imagecreatetruecolor( $w, $h );
	$back  = imagecolorallocate( $im, $bg[0], $bg[1], $bg[2] );
	$light = imagecolorallocate( $im, 255, 248, 231 );
	$dark  = imagecolorallocate( $im, 60, 36, 16 );
	imagefilledrectangle( $im, 0, 0, $w, $h, $back );
	imagefilledellipse( $im, (int) ( $w / 2 ), (int) ( $h / 2 ), (int) ( $w * 0.6 ), (int) ( $h * 0.5 ), $light );
	imagefilledellipse( $im, (int) ( $w / 2 ), (int) ( $h / 2 ), (int) ( $w * 0.45 ), (int) ( $h * 0.32 ), $dark );
	imagestring( $im, 5, 10, $h - 24, $label, $light );
	$file = get_temp_dir() . 'a11y-fx-' . $key . '.' . $ext;
	if ( 'png' === $ext ) {
		imagepng( $im, $file );
	} else {
		imagejpeg( $im, $file, 85 );
	}
	imagedestroy( $im );
	return $file;
}

/**
 * Returns the attachment id for a fixture key, importing the file the first time.
 *
 * @param string $key   Fixture key, stored in _a11y_fx_key.
 * @param string $file  Source file path.
 * @param string $title Attachment title.
 * @param string $alt   Library alt text ('' for none).
 * @return int
 */
function a11y_fx_attachment( $key, $file, $title, $alt ) {
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
	if ( '' === $alt ) {
		delete_post_meta( $id, '_wp_attachment_image_alt' );
	} else {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}
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
function a11y_fx_page( $slug, $title, $content ) {
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

$a11y_fx_home = untrailingslashit( home_url() );

// ---- Media ----------------------------------------------------------------
$a11y_fx_media = array(
	'croissant'      => array( 'jpg', 640, 420, array( 201, 138, 60 ), 'Croissant', 'Butter croissant', 'Butter croissant on a wooden board' ),
	'baguette'       => array( 'jpg', 640, 420, array( 168, 100, 42 ), 'Baguette', 'Baguettes', 'Three baguettes cooling on a rack' ),
	'sourdough'      => array( 'jpg', 120, 80, array( 122, 74, 30 ), 'Loaf', 'Sourdough loaf', 'Round sourdough loaf with a floured crust' ),
	'cake'           => array( 'png', 640, 420, array( 90, 50, 30 ), 'Cake', 'Chocolate cake', 'Three-layer chocolate cake' ),
	'cinnamon-rolls' => array( 'jpg', 300, 200, array( 190, 120, 70 ), 'Rolls', 'Cinnamon rolls', 'Cinnamon rolls in a baking tin' ),
);
$a11y_fx_ids  = array();
$a11y_fx_urls = array();
foreach ( $a11y_fx_media as $a11y_fx_key => $a11y_fx_m ) {
	$a11y_fx_file                = a11y_fx_draw( $a11y_fx_key, $a11y_fx_m[0], $a11y_fx_m[1], $a11y_fx_m[2], $a11y_fx_m[3], $a11y_fx_m[4] );
	$a11y_fx_ids[ $a11y_fx_key ] = a11y_fx_attachment( $a11y_fx_key, $a11y_fx_file, $a11y_fx_m[5], $a11y_fx_m[6] );
	$a11y_fx_urls[ $a11y_fx_key ] = wp_get_attachment_url( $a11y_fx_ids[ $a11y_fx_key ] );
}

$a11y_fx_video_url = '';
if ( '' !== $a11y_fx_video_source && is_readable( $a11y_fx_video_source ) ) {
	$a11y_fx_ids['video']  = a11y_fx_attachment( 'video', $a11y_fx_video_source, 'Bakery oven', '' );
	$a11y_fx_video_url     = wp_get_attachment_url( $a11y_fx_ids['video'] );
	$a11y_fx_urls['video'] = $a11y_fx_video_url;
} else {
	WP_CLI::warning( 'No readable video passed; the structure fixture will have no autoplay video.' );
}

// ---- a11y-fx-block-editor ----------------------------------------------------
$h = $a11y_fx_home;
$u = $a11y_fx_urls;
$i = $a11y_fx_ids;

$a11y_fx_block_editor = implode(
	"\n\n",
	array(
		'<!-- wp:paragraph -->' . "\n" . '<p>Fresh bread and pastries from Example Bakery, baked every morning in our wood-fired oven.</p>' . "\n" . '<!-- /wp:paragraph -->',
		'<!-- wp:image {"id":' . $i['croissant'] . ',"sizeSlug":"full","linkDestination":"none"} -->' . "\n" . '<figure class="wp-block-image size-full"><img src="' . $u['croissant'] . '" class="wp-image-' . $i['croissant'] . '"/></figure>' . "\n" . '<!-- /wp:image -->',
		'<!-- wp:image {"id":' . $i['baguette'] . ',"sizeSlug":"full","linkDestination":"none"} -->' . "\n" . '<figure class="wp-block-image size-full"><img src="' . $u['baguette'] . '" class="wp-image-' . $i['baguette'] . '"/><figcaption class="wp-element-caption">Our baguettes come out of the oven at seven.</figcaption></figure>' . "\n" . '<!-- /wp:image -->',
		'<!-- wp:paragraph -->' . "\n" . '<p><a href="' . $h . '/order/">Order a sourdough loaf <img class="wp-image-' . $i['sourdough'] . '" style="width: 60px;" src="' . $u['sourdough'] . '"></a></p>' . "\n" . '<!-- /wp:paragraph -->',
		'<!-- wp:image {"id":' . $i['cake'] . ',"sizeSlug":"full","linkDestination":"none"} -->' . "\n" . '<figure class="wp-block-image size-full"><img src="' . $u['cake'] . '" alt="" class="wp-image-' . $i['cake'] . '"/></figure>' . "\n" . '<!-- /wp:image -->',
		'<!-- wp:heading -->' . "\n" . '<h2 class="wp-block-heading"></h2>' . "\n" . '<!-- /wp:heading -->',
		'<!-- wp:paragraph -->' . "\n" . '<p>Read what customers say on <a href="https://www.example.com/reviews/" target="_blank" rel="noreferrer noopener">our reviews page</a>, or <a href="' . $h . '/visit/">visit the shop</a>.</p>' . "\n" . '<!-- /wp:paragraph -->',
		'<!-- wp:html -->' . "\n" . implode(
			"\n",
			array(
				'<ul class="bakery-social">',
				'<li><a href="https://www.facebook.com/examplebakery"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M14 8h3V4h-3c-2.8 0-4 1.7-4 4v2H8v4h2v8h4v-8h3l1-4h-4V8z"/></svg></a></li>',
				'<li><a href="https://www.instagram.com/examplebakery"><i class="fa fa-instagram"></i></a></li>',
				'<li><a href="mailto:hello@example.com"><span class="dashicons dashicons-email"></span></a></li>',
				'<li><a href="https://www.youtube.com/@examplebakery"><svg role="img" width="24" height="24" viewBox="0 0 24 24"><title>YouTube</title><path d="M2 7c0-2 1-3 3-3h14c2 0 3 1 3 3v10c0 2-1 3-3 3H5c-2 0-3-1-3-3z"/></svg></a></li>',
				'<li><a href="' . $h . '/opening-hours/" title="Opening hours"><svg aria-hidden="true" focusable="false" width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 6v6l4 2" fill="none" stroke="currentColor" stroke-width="2"/></svg></a></li>',
				'</ul>',
				'<p><a href="' . $h . '/order/" tabindex="3">Order online</a></p>',
				'<iframe src="https://www.youtube-nocookie.com/embed/a11yfxTour" srcdoc="&lt;p&gt;Bakery tour video placeholder&lt;/p&gt;" width="560" height="315"></iframe>',
				'<div class="bakery-posts">',
				'<article class="bakery-post"><h3>Autumn menu</h3><p>Pumpkin loaf and apple turnovers are back on the counter.</p><a href="' . $h . '/autumn-menu/">Read more</a></article>',
				'<article class="bakery-post"><h3>Christmas orders</h3><p>Stollen and mince pies can be ordered from November.</p><a href="' . $h . '/christmas-orders/">Read more</a></article>',
				'</div>',
				'<div class="bakery-card"><a href="' . $h . '/menu/cinnamon-rolls/" aria-hidden="true"><img src="' . $u['cinnamon-rolls'] . '" alt="" class="wp-image-' . $i['cinnamon-rolls'] . '" width="300" height="200"></a><h3><a href="' . $h . '/menu/cinnamon-rolls/">Cinnamon rolls</a></h3><p>Soft rolls with brown sugar and cinnamon.</p></div>',
				'<form class="bakery-newsletter" action="' . $h . '/" method="get"><label for="bakery-newsletter-email">Email for our newsletter</label> <input type="email" id="bakery-newsletter-email" name="newsletter_email"> <button type="submit">Subscribe</button></form>',
			)
		) . "\n" . '<!-- /wp:html -->',
	)
);

// ---- a11y-fx-forms ------------------------------------------------------------
$a11y_fx_cf7_shortcode = '';
if ( class_exists( 'WPCF7_ContactForm' ) ) {
	$a11y_fx_cf7_title = 'A11Y FX cake order';
	$a11y_fx_cf7_found = get_posts(
		array(
			'post_type'   => 'wpcf7_contact_form',
			'title'       => $a11y_fx_cf7_title,
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	$a11y_fx_cf7       = $a11y_fx_cf7_found ? WPCF7_ContactForm::get_instance( $a11y_fx_cf7_found[0] ) : WPCF7_ContactForm::get_template( array( 'title' => $a11y_fx_cf7_title ) );
	$a11y_fx_props     = $a11y_fx_cf7->get_properties();
	$a11y_fx_props['form'] = implode(
		"\n",
		array(
			'[text* your-name placeholder "Your name"]',
			'',
			'[email* your-email placeholder "Email address"]',
			'',
			'<label> Cake size',
			'    [select cake-size "6 inch" "8 inch" "10 inch"] </label>',
			'',
			'[textarea your-message placeholder "Tell us about the occasion"]',
			'',
			'[submit "Send order request"]',
		)
	);
	$a11y_fx_cf7->set_properties( $a11y_fx_props );
	$a11y_fx_cf7->save();
	$a11y_fx_cf7           = WPCF7_ContactForm::get_instance( $a11y_fx_cf7->id() );
	$a11y_fx_cf7_shortcode = $a11y_fx_cf7->shortcode();
	$a11y_fx_ids['cf7']    = $a11y_fx_cf7->id();
} else {
	WP_CLI::warning( 'Contact Form 7 is not active; the forms fixture has no CF7 form.' );
}

$a11y_fx_forms = implode(
	"\n\n",
	array(
		'<!-- wp:paragraph -->' . "\n" . '<p>Order a celebration cake or ask us a question. We reply within one working day.</p>' . "\n" . '<!-- /wp:paragraph -->',
		'<!-- wp:html -->' . "\n" . implode(
			"\n",
			array(
				'<form class="bakery-enquiry" action="' . $h . '/" method="post">',
				'<p><input type="text" name="your-name" placeholder="Your name"></p>',
				'<p><input type="email" name="your-email" placeholder="Email address"></p>',
				'<p><input type="tel" name="phone"></p>',
				'<p><select name="pickup-day"><option value="fri">Friday</option><option value="sat">Saturday</option></select></p>',
				'<p><textarea name="your-message" placeholder="Your message"></textarea></p>',
				'<p><label for="bakery-gift-note">Gift note</label><br><textarea id="bakery-gift-note" name="gift-note"></textarea></p>',
				'<p><span id="bakery-allergy-label">Allergies we should know about</span><br><input type="text" name="allergies" aria-labelledby="bakery-allergy-label"></p>',
				'<p><input type="submit" value=""></p>',
				'</form>',
				'<form role="search" class="bakery-search" action="' . $h . '/" method="get">',
				'<input type="search" name="s" placeholder="Search the menu">',
				'<button type="submit"><svg width="20" height="20" viewBox="0 0 20 20"><circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="2"/><path d="M13 13l5 5" stroke="currentColor" stroke-width="2"/></svg></button>',
				'</form>',
				'<h2>Call me back</h2>',
				'<form class="bakery-callback" action="' . $h . '/" method="post"><label for="callback-phone">Phone number</label> <input type="tel" id="callback-phone" name="callback_phone"> <button type="submit">Call me back</button></form>',
				'<form class="bakery-callback" action="' . $h . '/" method="post"><label for="callback-phone">Phone number</label> <input type="tel" id="callback-phone" name="callback_phone"> <button type="submit">Call me back</button></form>',
			)
		) . "\n" . '<!-- /wp:html -->',
		'<!-- wp:heading -->' . "\n" . '<h2 class="wp-block-heading">Cake order form</h2>' . "\n" . '<!-- /wp:heading -->',
		'' !== $a11y_fx_cf7_shortcode ? '<!-- wp:shortcode -->' . "\n" . $a11y_fx_cf7_shortcode . "\n" . '<!-- /wp:shortcode -->' : '<!-- wp:paragraph -->' . "\n" . '<p>Contact Form 7 was not active when this fixture was built.</p>' . "\n" . '<!-- /wp:paragraph -->',
	)
);

// ---- a11y-fx-structure -------------------------------------------------------
$a11y_fx_video = '' !== $a11y_fx_video_url ? '<video src="' . $a11y_fx_video_url . '" autoplay loop width="320" height="180"></video>' : '';

$a11y_fx_structure = implode(
	"\n\n",
	array(
		'<!-- wp:paragraph -->' . "\n" . '<p>Opening hours, delivery areas and a look inside the bakehouse.</p>' . "\n" . '<!-- /wp:paragraph -->',
		'<!-- wp:html -->' . "\n" . implode(
			"\n",
			array_filter(
				array(
					'<a class="skip-link screen-reader-text" href="#bakery-main-content">Skip to main content</a>',
					'<nav><ul><li><a href="' . $h . '/menu/">Menu</a></li><li><a href="' . $h . '/visit/">Visit</a></li><li><a href="' . $h . '/order/">Order</a></li></ul></nav>',
					'<nav><ul><li><a href="' . $h . '/delivery/">Delivery</a></li><li><a href="' . $h . '/allergens/">Allergens</a></li><li><a href="' . $h . '/careers/">Careers</a></li></ul></nav>',
					$a11y_fx_video,
					'<table class="bakery-hours"><tr><td>Day</td><td>Opens</td><td>Closes</td></tr><tr><td>Monday to Friday</td><td>7:00</td><td>18:00</td></tr><tr><td>Saturday</td><td>8:00</td><td>16:00</td></tr><tr><td>Sunday</td><td>Closed</td><td>Closed</td></tr></table>',
					'<div role="button" class="bakery-allergen-toggle">Show allergens</div>',
				)
			)
		) . "\n" . '<!-- /wp:html -->',
	)
);

$a11y_fx_pages = array(
	'a11y-fx-block-editor' => a11y_fx_page( 'a11y-fx-block-editor', 'A11Y FX Block editor', $a11y_fx_block_editor ),
	'a11y-fx-forms'        => a11y_fx_page( 'a11y-fx-forms', 'A11Y FX Forms', $a11y_fx_forms ),
	'a11y-fx-structure'    => a11y_fx_page( 'a11y-fx-structure', 'A11Y FX Structure', $a11y_fx_structure ),
);

foreach ( $a11y_fx_pages as $a11y_fx_slug => $a11y_fx_id ) {
	WP_CLI::log( sprintf( 'page        %-22s ID %d  %s', $a11y_fx_slug, $a11y_fx_id, get_permalink( $a11y_fx_id ) ) );
}
foreach ( $a11y_fx_ids as $a11y_fx_key => $a11y_fx_id ) {
	$a11y_fx_alt = get_post_meta( $a11y_fx_id, '_wp_attachment_image_alt', true );
	WP_CLI::log( sprintf( 'object      %-22s ID %d  %s', $a11y_fx_key, $a11y_fx_id, 'cf7' === $a11y_fx_key ? $a11y_fx_cf7_shortcode : 'alt: ' . ( '' === $a11y_fx_alt ? '(none)' : $a11y_fx_alt ) ) );
}
WP_CLI::success( 'Fixtures created or refreshed.' );
