<?php
/**
 * F04: WooCommerce product images with no alt text get the product name.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layer A only (wp_get_attachment_image_attributes): on a single product
 * page, the product's own main and gallery images get the product name as
 * alt when theirs is empty. Never in product loops (related products,
 * up-sells), where the link text already carries the name. Calls no
 * WooCommerce function unless it exists.
 */
class Fix_Product_Img_Alt_Name extends Fix_Base {

	/**
	 * The product of the queried page, keyed by queried object id; false until looked up.
	 *
	 * @var array|false
	 */
	private $product = false;

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'product-img-alt-name';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Product name as alt text for product images', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '1.1.1' );
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'image-alt' );
	}

	/**
	 * Attribute hook only; the engine never runs it.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'attribute' );
	}

	/**
	 * Risk.
	 *
	 * @return string
	 */
	public function risk(): string {
		return 'medium';
	}

	/**
	 * Starts Off.
	 *
	 * @return bool
	 */
	public function recommended(): bool {
		return false;
	}

	/**
	 * Needs WooCommerce.
	 *
	 * @return string[]
	 */
	public function requires(): array {
		return array( 'woocommerce' );
	}

	/**
	 * Not used: the fix works on the attachment image attributes, not on parsed markup.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
	}

	/**
	 * Callback body for wp_get_attachment_image_attributes: sets alt to the
	 * product name (plain text; core escapes the attributes afterwards) when
	 * the image is the current product's own and its alt is empty.
	 *
	 * @param array $attr       Attribute values keyed by name.
	 * @param mixed $attachment The attachment post.
	 * @return array
	 */
	public function filter_attributes( array $attr, $attachment ): array {
		$id = is_object( $attachment ) && isset( $attachment->ID ) ? (int) $attachment->ID : 0;
		if ( $id <= 0 || ( isset( $attr['alt'] ) && is_scalar( $attr['alt'] ) && '' !== trim( (string) $attr['alt'] ) ) ) {
			return $attr;
		}

		$product = $this->current_product();
		if ( null === $product || ! in_array( $id, $product['image_ids'], true ) || $this->in_product_loop( $product['id'] ) ) {
			return $attr;
		}

		$name = self::plain_text( $product['name'] );
		if ( '' !== $name ) {
			$attr['alt'] = $name;
		}
		return $attr;
	}

	/**
	 * The product of the single product page being shown: id, name and the
	 * ids of its main and gallery images. Null anywhere else, or without WooCommerce.
	 *
	 * @return array|null
	 */
	protected function current_product() {
		if ( ! function_exists( 'is_product' ) || ! function_exists( 'wc_get_product' ) || ! is_product() ) {
			return null;
		}
		$queried = (int) get_queried_object_id();
		if ( false !== $this->product && $this->product['queried'] === $queried ) {
			return $this->product['product'];
		}

		$found   = null;
		$product = wc_get_product( $queried );
		if ( is_object( $product ) && method_exists( $product, 'get_image_id' ) && method_exists( $product, 'get_gallery_image_ids' ) && method_exists( $product, 'get_name' ) && method_exists( $product, 'get_id' ) ) {
			$ids   = array_merge( array( $product->get_image_id() ), (array) $product->get_gallery_image_ids() );
			$found = array(
				'id'        => (int) $product->get_id(),
				'name'      => (string) $product->get_name(),
				'image_ids' => array_values( array_filter( array_map( 'intval', $ids ) ) ),
			);
		}
		$this->product = array(
			'queried' => $queried,
			'product' => $found,
		);
		return $found;
	}

	/**
	 * Whether the image is being printed inside a product loop or for another
	 * post than the product: a named WooCommerce loop (related, up-sells,
	 * cross-sells), read without calling WooCommerce, or a query loop whose
	 * current post is not the product.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	protected function in_product_loop( int $product_id ): bool {
		if ( isset( $GLOBALS['woocommerce_loop']['name'] ) && '' !== $GLOBALS['woocommerce_loop']['name'] ) {
			return true;
		}
		$current = get_the_ID();
		return false !== $current && (int) $current !== $product_id;
	}
}
