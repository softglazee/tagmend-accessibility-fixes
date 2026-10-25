<?php
/**
 * Media Library alt text for the images on a page.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects the attachment ids and upload URLs the analyse pass finds, then
 * loads them in one batch (blueprint section 3.6): one _prime_post_caches()
 * call for the posts and their meta (and one for their parent posts).
 *
 * An image without an id in its markup is found by its URL. That lookup
 * (attachment_url_to_postid()) compares an unindexed meta value, so it runs
 * only in authenticated requests: scans, "View repaired" and WP-CLI, at most
 * URL_LOOKUPS_MAX per instance. They save every answer, found or not, in one
 * option that is not autoloaded (MAP_OPTION, at most MAP_MAX entries, the
 * oldest dropped). Visitor requests read that option once and never query a
 * URL nor write anything; an image no scan has seen yet is left alone.
 *
 * One instance lives as long as its engine, which is one request on the
 * front end, so every fragment of a page shares it.
 */
class Attachment_Lookup {

	const URL_LOOKUPS_MAX = 20;

	/**
	 * Option holding upload path => attachment id (0: no attachment), as found by authenticated requests.
	 */
	const MAP_OPTION = 'softglaze_a11y_attachment_urls';

	/**
	 * Entries kept in the option; the oldest are dropped first.
	 */
	const MAP_MAX = 500;

	/**
	 * Whether this instance may query URLs and save the answers (authenticated requests only).
	 *
	 * @var bool
	 */
	private $query_urls = false;

	/**
	 * The saved map, read once on first need.
	 *
	 * @var int[]|null
	 */
	private $map = null;

	/**
	 * Answers of this instance's URL queries waiting to be saved: path => id.
	 *
	 * @var int[]
	 */
	private $learned = array();

	/**
	 * Attachment id => raw Media Library alt text ('' when none or not an attachment).
	 *
	 * @var string[]
	 */
	private $alts = array();

	/**
	 * Attachment id => attached file path relative to the uploads folder.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Attachment ids waiting for load().
	 *
	 * @var bool[]
	 */
	private $pending_ids = array();

	/**
	 * Upload path => attachment id (0 when not found).
	 *
	 * @var int[]
	 */
	private $paths = array();

	/**
	 * Upload paths waiting for load().
	 *
	 * @var bool[]
	 */
	private $pending_paths = array();

	/**
	 * Upload paths left unresolved: the lookup limit was reached, or (in a
	 * visitor request) no authenticated request has looked them up yet.
	 *
	 * @var bool[]
	 */
	private $skipped_paths = array();

	/**
	 * Number of attachment_url_to_postid() calls made.
	 *
	 * @var int
	 */
	private $url_lookups = 0;

	/**
	 * Uploads base URL without its scheme, for example //example.org/wp-content/uploads.
	 *
	 * @var string|null
	 */
	private $base = null;

	/**
	 * Uploads base URL as WordPress reports it.
	 *
	 * @var string
	 */
	private $baseurl = '';

	/**
	 * Creates a lookup.
	 *
	 * @param bool $query_urls True only for authenticated requests (scan, admin view, WP-CLI): may run URL queries and save their answers.
	 */
	public function __construct( bool $query_urls = false ) {
		$this->query_urls = $query_urls;
	}

	/**
	 * Whether this instance may query URLs.
	 *
	 * @return bool
	 */
	public function queries_urls(): bool {
		return $this->query_urls;
	}

	/**
	 * The attachment id an img carries in its markup: the wp-image-{id} class
	 * the editors write, or a data-id / data-attachment-id attribute.
	 *
	 * @param string $classes            Class attribute.
	 * @param string $data_id            data-id attribute.
	 * @param string $data_attachment_id data-attachment-id attribute.
	 * @return int 0 when none is found.
	 */
	public static function id_from_attributes( string $classes, string $data_id = '', string $data_attachment_id = '' ): int {
		if ( '' !== $classes && preg_match( '/(?:^|\s)wp-image-([1-9][0-9]{0,18})(?:\s|$)/', $classes, $match ) ) {
			return (int) $match[1];
		}
		foreach ( array( $data_attachment_id, $data_id ) as $value ) {
			$value = trim( $value );
			if ( '' !== $value && preg_match( '/^[1-9][0-9]{0,18}$/', $value ) ) {
				return (int) $value;
			}
		}
		return 0;
	}

	/**
	 * Asks for the attachment behind the current img opener: the id from its
	 * markup, otherwise its URL (src, or data-src / data-lazy-src when src is
	 * a placeholder). An id from the markup (wp-image-{id}, data-id or
	 * data-attachment-id) is only trusted when the attachment's file name
	 * appears in the image URL: imported content and switch_to_blog() can
	 * carry ids of other attachments.
	 *
	 * @param Token $token     An img opener.
	 * @param bool  $allow_url Whether a URL lookup may be spent when the markup carries no id.
	 * @return array Reference for attachment_id(): 'id', 'path', 'src', 'verify'.
	 */
	public function request_image( Token $token, bool $allow_url = true ): array {
		$src = $token->attr_text( 'src' );
		if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
			foreach ( array( 'data-src', 'data-lazy-src' ) as $attribute ) {
				if ( '' !== $token->attr_text( $attribute ) ) {
					$src = $token->attr_text( $attribute );
					break;
				}
			}
		}

		$class = $token->attr_text( 'class' );
		$id    = self::id_from_attributes( $class );
		if ( $id > 0 ) {
			$this->request_id( $id );
			return array(
				'id'     => $id,
				'path'   => '',
				'src'    => $src,
				'verify' => true,
			);
		}

		$id = self::id_from_attributes( '', $token->attr_text( 'data-id' ), $token->attr_text( 'data-attachment-id' ) );
		if ( $id > 0 ) {
			$this->request_id( $id );
		}
		return array(
			'id'     => $id,
			'path'   => $id > 0 || ! $allow_url ? '' : $this->request_url( $src ),
			'src'    => $src,
			'verify' => $id > 0,
		);
	}

	/**
	 * The attachment id for a reference from request_image() (after load()).
	 *
	 * @param array $image Reference.
	 * @return int 0 when unknown.
	 */
	public function attachment_id( array $image ): int {
		if ( $image['id'] > 0 ) {
			if ( $image['verify'] && ! $this->file_matches( (int) $image['id'], (string) $image['src'] ) ) {
				return 0;
			}
			return (int) $image['id'];
		}
		return '' === $image['path'] ? 0 : $this->id_for_path( (string) $image['path'] );
	}

	/**
	 * Asks for the alt text of an attachment id.
	 *
	 * @param int $id Attachment id.
	 * @return void
	 */
	public function request_id( int $id ): void {
		if ( $id > 0 && ! isset( $this->alts[ $id ] ) ) {
			$this->pending_ids[ $id ] = true;
		}
	}

	/**
	 * Asks for the attachment behind an image URL in this site's uploads.
	 * Query strings and the -{width}x{height} size suffix are removed first.
	 *
	 * @param string $src Decoded src attribute.
	 * @return string The upload path used as key, or '' when the URL is not in the uploads folder.
	 */
	public function request_url( string $src ): string {
		$path = $this->upload_path( $src );
		if ( '' !== $path && ! isset( $this->paths[ $path ] ) ) {
			$this->pending_paths[ $path ] = true;
		}
		return $path;
	}

	/**
	 * Resolves pending URLs (queries up to the limit in authenticated
	 * requests, the saved map otherwise), saves new answers, then loads every
	 * pending id in one batch.
	 *
	 * @return void
	 */
	public function load(): void {
		foreach ( array_keys( $this->pending_paths ) as $path ) {
			$id = $this->resolve_path( (string) $path );
			if ( null === $id ) {
				$this->skipped_paths[ $path ] = true;
				continue;
			}
			$this->paths[ $path ] = $id;
			if ( $id > 0 ) {
				$this->request_id( $id );
			}
		}
		$this->pending_paths = array();
		$this->save_map();

		if ( empty( $this->pending_ids ) ) {
			return;
		}
		$ids               = array_map( 'intval', array_keys( $this->pending_ids ) );
		$this->pending_ids = array();
		$found             = $this->read_attachments( $ids );
		foreach ( $ids as $id ) {
			$this->alts[ $id ]  = isset( $found[ $id ]['alt'] ) && is_string( $found[ $id ]['alt'] ) ? $found[ $id ]['alt'] : '';
			$this->files[ $id ] = isset( $found[ $id ]['file'] ) && is_string( $found[ $id ]['file'] ) ? $found[ $id ]['file'] : '';
		}
	}

	/**
	 * The attachment id found for an upload path (after load()).
	 *
	 * @param string $path Key returned by request_url().
	 * @return int 0 when not found or not looked up.
	 */
	public function id_for_path( string $path ): int {
		return isset( $this->paths[ $path ] ) ? $this->paths[ $path ] : 0;
	}

	/**
	 * Whether an upload path was left unresolved: the lookup limit was
	 * reached, or no authenticated request has looked it up yet (visitors).
	 *
	 * @param string $path Key returned by request_url().
	 * @return bool
	 */
	public function was_skipped( string $path ): bool {
		return isset( $this->skipped_paths[ $path ] );
	}

	/**
	 * Raw Media Library alt text of an attachment (after load()); '' when unknown.
	 *
	 * @param int $id Attachment id.
	 * @return string
	 */
	public function alt( int $id ): string {
		return isset( $this->alts[ $id ] ) ? $this->alts[ $id ] : '';
	}

	/**
	 * Number of attachment_url_to_postid() calls made by this instance.
	 *
	 * @return int
	 */
	public function url_lookups(): int {
		return $this->url_lookups;
	}

	/**
	 * Whether an attachment's file name (without size or -scaled suffix) appears in an image URL.
	 *
	 * @param int    $id  Attachment id (loaded).
	 * @param string $src Image URL.
	 * @return bool
	 */
	public function file_matches( int $id, string $src ): bool {
		$file = isset( $this->files[ $id ] ) ? $this->files[ $id ] : '';
		$stem = (string) preg_replace( '/(-scaled)?\.[A-Za-z0-9]{2,5}$/', '', wp_basename( $file ) );
		return '' !== $stem && false !== stripos( rawurldecode( $src ), $stem );
	}

	/**
	 * Loads attachment posts and their meta in one batch (one
	 * _prime_post_caches() call, and one more for their parent posts).
	 * Attachments that are not public themselves (trashed, private, draft)
	 * and attachments of a post visitors cannot see (a draft, a private post
	 * or post type) give nothing; unattached ones do.
	 *
	 * @param int[] $ids Attachment ids.
	 * @return array Id => array( 'alt' => raw alt text, 'file' => attached file path ), attachments only.
	 */
	protected function read_attachments( array $ids ): array {
		_prime_post_caches( $ids, false, true );
		$posts   = array();
		$parents = array();
		foreach ( $ids as $id ) {
			// Ids that are not posts at all are not cached by the batch; skip them instead of querying each one.
			if ( false === wp_cache_get( $id, 'posts' ) ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type || ! in_array( $post->post_status, array( 'inherit', 'publish' ), true ) ) {
				continue;
			}
			$posts[ $id ] = $post;
			if ( $post->post_parent > 0 ) {
				$parents[ (int) $post->post_parent ] = true;
			}
		}
		if ( ! empty( $parents ) ) {
			_prime_post_caches( array_keys( $parents ), false, false );
		}

		$found = array();
		foreach ( $posts as $id => $post ) {
			if ( $post->post_parent > 0 ) {
				$parent = false === wp_cache_get( (int) $post->post_parent, 'posts' ) ? null : get_post( (int) $post->post_parent );
				if ( ! $parent || ! is_post_publicly_viewable( $parent ) ) {
					continue;
				}
			}
			$alt          = get_post_meta( $id, '_wp_attachment_image_alt', true );
			$file         = get_post_meta( $id, '_wp_attached_file', true );
			$found[ $id ] = array(
				'alt'  => is_string( $alt ) ? $alt : '',
				'file' => is_string( $file ) ? $file : '',
			);
		}
		return $found;
	}

	/**
	 * One attachment_url_to_postid() query.
	 *
	 * @param string $url Full upload URL.
	 * @return int
	 */
	protected function query_url( string $url ): int {
		return (int) attachment_url_to_postid( $url );
	}

	/**
	 * The uploads base URL without its scheme.
	 *
	 * @return string
	 */
	protected function uploads_base(): string {
		if ( null === $this->base ) {
			$dir           = wp_get_upload_dir();
			$this->baseurl = untrailingslashit( isset( $dir['baseurl'] ) && is_string( $dir['baseurl'] ) ? $dir['baseurl'] : '' );
			$this->base    = (string) preg_replace( '#^[a-z][a-z0-9+.-]*:#i', '', $this->baseurl );
		}
		return $this->base;
	}

	/**
	 * Id for an upload path: in authenticated requests a capped query (its
	 * answer is kept for the map); otherwise, or once the limit is reached,
	 * the saved map. Null when neither knows the path.
	 *
	 * @param string $path Upload path relative to the uploads folder.
	 * @return int|null
	 */
	private function resolve_path( string $path ) {
		if ( $this->query_urls ) {
			$id = $this->query_path( $path );
			if ( null !== $id ) {
				$this->learned[ $path ] = $id;
				return $id;
			}
		}
		$map = $this->map();
		return isset( $map[ $path ] ) ? $map[ $path ] : null;
	}

	/**
	 * Up to two attachment_url_to_postid() queries for an upload path; null when the limit is reached.
	 *
	 * @param string $path Upload path relative to the uploads folder.
	 * @return int|null
	 */
	private function query_path( string $path ) {
		$candidates = array( $path );
		// Images over the big-image threshold are stored as name-scaled.ext while their sizes keep the original name.
		if ( ! preg_match( '/-scaled\.[A-Za-z0-9]{2,5}$/', $path ) ) {
			$candidates[] = (string) preg_replace( '/(\.[A-Za-z0-9]{2,5})$/', '-scaled$1', $path );
		}

		$id = 0;
		foreach ( $candidates as $candidate ) {
			if ( $this->url_lookups >= self::URL_LOOKUPS_MAX ) {
				return null;
			}
			++$this->url_lookups;
			$id = max( 0, $this->query_url( $this->baseurl . '/' . $candidate ) );
			if ( $id > 0 ) {
				break;
			}
		}
		return $id;
	}

	/**
	 * The saved upload path => attachment id map, read once (one option, not autoloaded).
	 *
	 * @return int[]
	 */
	protected function map(): array {
		if ( null !== $this->map ) {
			return $this->map;
		}
		$this->map = array();
		$saved     = get_option( self::MAP_OPTION, array() );
		if ( is_array( $saved ) ) {
			foreach ( $saved as $path => $id ) {
				if ( is_string( $path ) && '' !== $path && strlen( $path ) <= 255 && is_int( $id ) && $id >= 0 ) {
					$this->map[ $path ] = $id;
				}
			}
		}
		return $this->map;
	}

	/**
	 * Saves the answers of this instance's URL queries (authenticated
	 * requests only): a new or refreshed entry goes last, at most MAP_MAX
	 * entries are kept, the oldest are dropped.
	 *
	 * @return void
	 */
	private function save_map(): void {
		if ( ! $this->query_urls || empty( $this->learned ) ) {
			return;
		}
		$map = $this->map();
		foreach ( $this->learned as $path => $id ) {
			$path = (string) $path;
			unset( $map[ $path ] );
			$map[ $path ] = $id;
		}
		$this->learned = array();
		if ( count( $map ) > self::MAP_MAX ) {
			$map = array_slice( $map, -self::MAP_MAX, null, true );
		}
		$this->map = $map;
		update_option( self::MAP_OPTION, $map, false );
	}

	/**
	 * The path of an image URL relative to the uploads folder, with the size
	 * suffix removed; '' for data URIs, other hosts and other folders.
	 *
	 * @param string $src Decoded src attribute.
	 * @return string
	 */
	private function upload_path( string $src ): string {
		$src = trim( $src );
		if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
			return '';
		}
		$src  = (string) preg_replace( '/[?#].*$/s', '', $src );
		$base = $this->uploads_base();
		if ( '' === $base || 0 !== strpos( $base, '//' ) ) {
			return '';
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*:(//.*)$#i', $src, $match ) ) {
			$src = $match[1];
		} elseif ( 0 === strpos( $src, '/' ) && 0 !== strpos( $src, '//' ) ) {
			$host = (string) preg_replace( '#^(//[^/]*).*$#', '$1', $base );
			$src  = $host . $src;
		}
		if ( 0 !== stripos( $src, $base . '/' ) ) {
			return '';
		}

		$path = substr( $src, strlen( $base ) + 1 );
		$path = (string) preg_replace( '/-[0-9]{1,5}x[0-9]{1,5}(\.[A-Za-z0-9]{2,5})$/', '$1', rawurldecode( $path ) );
		if ( '' === $path || false !== strpos( $path, '..' ) || strlen( $path ) > 255 ) {
			return '';
		}
		return $path;
	}
}
