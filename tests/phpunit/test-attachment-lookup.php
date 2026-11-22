<?php
/**
 * The Media Library lookup against the real test database (blueprint section
 * 3.6): ids from markup, one batched load for posts and meta, URL lookups
 * with the size suffix stripped, the -scaled retry, the cap of 20
 * attachment_url_to_postid() calls, and (Phase 8B, review C3) URL lookups
 * only in authenticated requests, whose answers visitors read from one saved
 * option; ids from the markup checked against the file name and attachments
 * of non-public posts skipped (review C4, S5).
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Attachment_Lookup;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Img_Alt_Library;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * Attachment lookup tests.
 */
class Test_Attachment_Lookup extends Softglaze_A11y_Engine_TestCase {

	/**
	 * Attachment ids by key.
	 *
	 * @var int[]
	 */
	private $ids = array();

	/**
	 * attachment_url_to_postid() calls seen through its filter.
	 *
	 * @var string[]
	 */
	private $url_calls = array();

	/**
	 * Creates the attachments and counts URL queries.
	 */
	public function set_up() {
		parent::set_up();
		$attachments = array(
			'one'    => array( '2026/10/lookup-one.jpg', 'Loaf on a board' ),
			'scaled' => array( '2026/10/lookup-two-scaled.jpg', 'Bread basket' ),
			'empty'  => array( '2026/10/lookup-three.png', '' ),
		);
		foreach ( $attachments as $key => $attachment ) {
			$id = self::factory()->attachment->create_object(
				array(
					'file'           => $attachment[0],
					'post_mime_type' => 'image/jpeg',
					'post_title'     => 'Title ' . $key,
				)
			);
			update_post_meta( $id, '_wp_attachment_image_alt', $attachment[1] );
			$this->ids[ $key ] = $id;
		}
		$this->ids['post']    = self::factory()->post->create();
		$this->ids['trashed'] = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/10/lookup-trashed.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $this->ids['trashed'], '_wp_attachment_image_alt', 'Gone' );
		wp_trash_post( $this->ids['trashed'] );
		update_post_meta( $this->ids['post'], '_wp_attachment_image_alt', 'Not an attachment' );

		$this->url_calls = array();
		add_filter( 'attachment_url_to_postid', array( $this, 'count_url_call' ), 10, 2 );
		delete_option( Attachment_Lookup::MAP_OPTION );
	}

	/**
	 * Removes the counter.
	 */
	public function tear_down() {
		remove_filter( 'attachment_url_to_postid', array( $this, 'count_url_call' ), 10 );
		parent::tear_down();
	}

	/**
	 * Filter callback: records each attachment_url_to_postid() call.
	 *
	 * @param int|null $post_id Found id.
	 * @param string   $url     URL.
	 * @return int|null
	 */
	public function count_url_call( $post_id, $url ) {
		$this->url_calls[] = $url;
		return $post_id;
	}

	/**
	 * Uploads URL of a path.
	 *
	 * @param string $path Path below the uploads folder.
	 * @return string
	 */
	private static function upload_url( $path ) {
		return wp_get_upload_dir()['baseurl'] . '/' . $path;
	}

	/**
	 * Ids come from wp-image-{id}, then data-attachment-id, then data-id; nothing else.
	 */
	public function test_id_from_attributes() {
		$this->assertSame( 12, Attachment_Lookup::id_from_attributes( 'aligncenter wp-image-12 size-full' ) );
		$this->assertSame( 12, Attachment_Lookup::id_from_attributes( 'wp-image-12' ) );
		$this->assertSame( 0, Attachment_Lookup::id_from_attributes( 'wp-image-12x' ) );
		$this->assertSame( 0, Attachment_Lookup::id_from_attributes( 'not-wp-image-12' ) );
		$this->assertSame( 0, Attachment_Lookup::id_from_attributes( 'wp-image-0' ) );
		$this->assertSame( 7, Attachment_Lookup::id_from_attributes( '', '9', '7' ) );
		$this->assertSame( 9, Attachment_Lookup::id_from_attributes( '', ' 9 ' ) );
		$this->assertSame( 0, Attachment_Lookup::id_from_attributes( '', 'gallery-9', '-1' ) );
		$this->assertSame( 0, Attachment_Lookup::id_from_attributes( 'size-full', '' ) );
	}

	/**
	 * One load reads every requested post and its meta with at most two
	 * queries; posts that are not attachments, and trashed ones, give no alt.
	 */
	public function test_batch_load() {
		global $wpdb;
		foreach ( $this->ids as $id ) {
			clean_post_cache( $id );
		}
		$lookup = new Attachment_Lookup();
		foreach ( $this->ids as $id ) {
			$lookup->request_id( $id );
		}
		$lookup->request_id( 999999 );

		$before = $wpdb->num_queries;
		$lookup->load();
		$this->assertLessThanOrEqual( 2, $wpdb->num_queries - $before, 'Posts and meta in one batch.' );

		$this->assertSame( 'Loaf on a board', $lookup->alt( $this->ids['one'] ) );
		$this->assertSame( 'Bread basket', $lookup->alt( $this->ids['scaled'] ) );
		$this->assertSame( '', $lookup->alt( $this->ids['empty'] ) );
		$this->assertSame( '', $lookup->alt( $this->ids['post'] ) );
		$this->assertSame( '', $lookup->alt( $this->ids['trashed'] ) );
		$this->assertSame( '', $lookup->alt( 999999 ) );

		$before = $wpdb->num_queries;
		$lookup->load();
		$this->assertSame( 0, $wpdb->num_queries - $before, 'Nothing new to load.' );
	}

	/**
	 * URLs: query string and size suffix removed, scheme and host forms accepted,
	 * other hosts and folders never looked up.
	 */
	public function test_url_forms() {
		$base   = wp_get_upload_dir()['baseurl'];
		$lookup = new Attachment_Lookup( true );
		$cases  = array(
			$base . '/2026/10/lookup-one-300x200.jpg?ver=2#top'                           => '2026/10/lookup-one.jpg',
			str_replace( 'http:', 'https:', $base ) . '/2026/10/lookup-one.jpg'           => '2026/10/lookup-one.jpg',
			preg_replace( '#^https?:#', '', $base ) . '/2026/10/lookup-one-1024x683.jpg' => '2026/10/lookup-one.jpg',
			wp_parse_url( $base, PHP_URL_PATH ) . '/2026/10/lookup-one.jpg'               => '2026/10/lookup-one.jpg',
			'https://cdn.example.net/wp-content/uploads/2026/10/lookup-one.jpg'           => '',
			home_url( '/wp-content/themes/x/lookup-one.jpg' )                             => '',
			'data:image/gif;base64,R0lGODlhAQABAAAAACw='                                  => '',
			'images/lookup-one.jpg'                                                       => '',
			$base . '/../wp-config.php'                                                   => '',
			''                                                                            => '',
		);
		foreach ( $cases as $src => $path ) {
			$this->assertSame( $path, $lookup->request_url( $src ), $src );
		}
		$lookup->load();
		$this->assertSame( $this->ids['one'], $lookup->id_for_path( '2026/10/lookup-one.jpg' ) );
		$this->assertSame( array( $base . '/2026/10/lookup-one.jpg' ), $this->url_calls, 'One query for all forms of the same file.' );
	}

	/**
	 * A size of an image over the big-image threshold is found through name-scaled.ext.
	 */
	public function test_scaled_retry() {
		$lookup = new Attachment_Lookup( true );
		$path   = $lookup->request_url( self::upload_url( '2026/10/lookup-two-1024x768.jpg' ) );
		$this->assertSame( '2026/10/lookup-two.jpg', $path );
		$lookup->load();
		$this->assertSame( $this->ids['scaled'], $lookup->id_for_path( $path ) );
		$this->assertSame( 'Bread basket', $lookup->alt( $this->ids['scaled'] ) );
		$this->assertSame( 2, $lookup->url_lookups() );
		$this->assertCount( 2, $this->url_calls );
	}

	/**
	 * At most 20 attachment_url_to_postid() calls per lookup; the rest are
	 * marked as skipped and not saved.
	 */
	public function test_cap_of_twenty() {
		$lookup = new Attachment_Lookup( true );
		$paths  = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$paths[] = $lookup->request_url( self::upload_url( '2026/10/missing-' . $i . '-scaled.jpg' ) );
		}
		$lookup->load();
		$this->assertSame( Attachment_Lookup::URL_LOOKUPS_MAX, $lookup->url_lookups() );
		$this->assertCount( 20, $this->url_calls );
		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertSame( $i >= 20, $lookup->was_skipped( $paths[ $i ] ), $paths[ $i ] );
		}
		$saved = get_option( Attachment_Lookup::MAP_OPTION );
		$this->assertCount( 20, $saved, 'Twenty paths answered (one query each: the names already end in -scaled).' );
		$this->assertSame( 0, $saved[ $paths[0] ] );
		$this->assertArrayNotHasKey( $paths[24], $saved, 'A skipped URL is not saved.' );
	}

	/**
	 * C3: an authenticated lookup saves its answers, found or not, in one
	 * option that is not autoloaded; a visitor lookup reads them with no URL
	 * query and no write, and leaves a path nobody looked up unresolved.
	 */
	public function test_saved_map_for_visitors() {
		global $wpdb;
		$first = new Attachment_Lookup( true );
		$first->request_url( self::upload_url( '2026/10/lookup-one-150x150.jpg' ) );
		$first->request_url( self::upload_url( '2026/10/nowhere-scaled.jpg' ) );
		$first->load();
		$this->assertCount( 2, $this->url_calls );
		$this->assertSame(
			array(
				'2026/10/lookup-one.jpg'     => $this->ids['one'],
				'2026/10/nowhere-scaled.jpg' => 0,
			),
			get_option( Attachment_Lookup::MAP_OPTION )
		);
		$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, Attachment_Lookup::MAP_OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ), 'Not autoloaded.' );

		wp_cache_delete( Attachment_Lookup::MAP_OPTION, 'options' );
		$queries = $wpdb->num_queries;
		$visitor = new Attachment_Lookup();
		$this->assertFalse( $visitor->queries_urls() );
		$visitor->request_url( self::upload_url( '2026/10/lookup-one.jpg' ) );
		$visitor->request_url( self::upload_url( '2026/10/nowhere-scaled.jpg' ) );
		$unseen = $visitor->request_url( self::upload_url( '2026/10/lookup-three.png' ) );
		$visitor->load();
		$this->assertSame( 0, $visitor->url_lookups() );
		$this->assertCount( 2, $this->url_calls, 'No URL query in a visitor request.' );
		$this->assertSame( $this->ids['one'], $visitor->id_for_path( '2026/10/lookup-one.jpg' ) );
		$this->assertSame( 'Loaf on a board', $visitor->alt( $this->ids['one'] ) );
		$this->assertSame( 0, $visitor->id_for_path( '2026/10/nowhere-scaled.jpg' ) );
		$this->assertSame( 0, $visitor->id_for_path( $unseen ), 'Not looked up by any scan yet: not repaired.' );
		$this->assertTrue( $visitor->was_skipped( $unseen ) );
		$this->assertCount( 2, get_option( Attachment_Lookup::MAP_OPTION ), 'A visitor request writes nothing.' );
		$this->assertLessThanOrEqual( 3, $wpdb->num_queries - $queries, 'One option read, then the batch for posts and meta.' );

		$again = new Attachment_Lookup( true );
		$again->request_url( self::upload_url( '2026/10/lookup-one.jpg' ) );
		$again->load();
		$this->assertSame( 1, $again->url_lookups(), 'An authenticated request asks again and refreshes the entry.' );
		$this->assertSame( '2026/10/lookup-one.jpg', array_key_last( get_option( Attachment_Lookup::MAP_OPTION ) ) );
	}

	/**
	 * The saved map keeps at most 500 entries; the oldest go first.
	 */
	public function test_saved_map_cap() {
		$map = array();
		for ( $i = 1; $i <= Attachment_Lookup::MAP_MAX; $i++ ) {
			$map[ '2020/01/old-' . $i . '.jpg' ] = 0;
		}
		update_option( Attachment_Lookup::MAP_OPTION, $map, false );
		$lookup = new Attachment_Lookup( true );
		$lookup->request_url( self::upload_url( '2026/10/lookup-one.jpg' ) );
		$lookup->load();
		$saved = get_option( Attachment_Lookup::MAP_OPTION );
		$this->assertCount( Attachment_Lookup::MAP_MAX, $saved );
		$this->assertArrayNotHasKey( '2020/01/old-1.jpg', $saved, 'The oldest entry was dropped.' );
		$this->assertArrayHasKey( '2020/01/old-2.jpg', $saved );
		$this->assertSame( '2026/10/lookup-one.jpg', array_key_last( $saved ) );
	}

	/**
	 * S5: attachments that are not public, or whose parent post is not
	 * publicly viewable, give no alt text; unattached ones and those of a
	 * published post do.
	 */
	public function test_non_public_attachments_give_nothing() {
		$cases  = array(
			'published parent' => array( 'publish', 'inherit', 'Shown' ),
			'unattached'       => array( null, 'inherit', 'Shown' ),
			'private parent'   => array( 'private', 'inherit', '' ),
			'draft parent'     => array( 'draft', 'inherit', '' ),
			'private file'     => array( null, 'private', '' ),
		);
		$lookup = new Attachment_Lookup();
		$ids    = array();
		foreach ( $cases as $name => $case ) {
			$parent = null === $case[0] ? 0 : self::factory()->post->create( array( 'post_status' => $case[0] ) );
			$id     = self::factory()->attachment->create_object(
				array(
					'file'           => '2026/10/s5-' . sanitize_title( $name ) . '.jpg',
					'post_mime_type' => 'image/jpeg',
					'post_parent'    => $parent,
					'post_status'    => $case[1],
				)
			);
			update_post_meta( $id, '_wp_attachment_image_alt', 'Shown' );
			clean_post_cache( $id );
			if ( $parent > 0 ) {
				clean_post_cache( $parent );
			}
			$ids[ $name ] = $id;
			$lookup->request_id( $id );
		}
		$lookup->load();
		foreach ( $cases as $name => $case ) {
			$this->assertSame( $case[2], $lookup->alt( $ids[ $name ] ), $name );
		}
	}

	/**
	 * Through the engine: F01 fills from the real library by class, by URL and
	 * by a data-id whose file matches; a data-id pointing at another file is
	 * not trusted; one engine shares its lookups between runs.
	 */
	public function test_engine_with_real_library() {
		global $wpdb;
		$one    = $this->ids['one'];
		$scaled = $this->ids['scaled'];
		$html   = '<p><img class="wp-image-' . $one . '" src="/wp-content/uploads/2026/10/lookup-one-1024x683.jpg"></p>'
			. '<p><img src="' . esc_url( self::upload_url( '2026/10/lookup-two-300x200.jpg' ) ) . '"></p>'
			. '<p><img data-id="' . $one . '" src="' . esc_url( self::upload_url( '2026/10/lookup-one-300x200.jpg' ) ) . '"></p>'
			. '<p><img data-id="' . $one . '" src="' . esc_url( self::upload_url( '2026/10/other.jpg' ) ) . '"></p>'
			. '<p><img class="wp-image-' . $one . '" src="/x.jpg"></p>'
			. '<p><img class="wp-image-' . $this->ids['empty'] . '" src="/wp-content/uploads/2026/10/lookup-three.png"></p>';
		$engine = new Engine( array( new Fix_Img_Alt_Library() ), array( 'img-alt-library' => 'live' ), array( 'attachments' => new Attachment_Lookup( true ) ) );
		$output = $engine->run( $html, Engine::MODE_ADMIN_REPAIRED, Engine::SCOPE_CONTENT );

		$this->assertSame(
			array(
				"IMG.alt: NULL -> 'Loaf on a board'",
				"IMG.alt: NULL -> 'Bread basket'",
				"IMG.alt: NULL -> 'Loaf on a board'",
			),
			self::token_diff( $html, $output )
		);
		$this->assertStringContainsString( '<img data-id="' . $one . '" src="' . esc_url( self::upload_url( '2026/10/other.jpg' ) ) . '">', $output );
		$this->assertStringContainsString( '<img class="wp-image-' . $one . '" src="/x.jpg">', $output, 'C4: a wp-image id whose file is not in the URL is not trusted.' );
		$this->assertSame( $scaled, $engine->attachments()->id_for_path( '2026/10/lookup-two.jpg' ) );

		$before = $wpdb->num_queries;
		$this->assertSame( $output, $engine->run( $html, Engine::MODE_ADMIN_REPAIRED, Engine::SCOPE_CONTENT ) );
		$this->assertSame( 0, $wpdb->num_queries - $before, 'The second run of the same engine makes no query.' );

		// A visitor engine fills the URL-only image from what the authenticated run saved, with no URL query.
		$calls   = count( $this->url_calls );
		$visitor = new Engine( array( new Fix_Img_Alt_Library() ), array( 'img-alt-library' => 'live' ) );
		$this->assertSame( $output, $visitor->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ) );
		$this->assertCount( $calls, $this->url_calls );
	}
}
