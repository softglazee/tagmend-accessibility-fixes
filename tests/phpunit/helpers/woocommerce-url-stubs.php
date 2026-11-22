<?php
/**
 * Stand-ins for two WooCommerce URL functions, for F05's shop sources. Loaded
 * by the golden and link tests only when WooCommerce is absent; once loaded they stay for the
 * process, so they behave like WooCommerce without set-up pages unless the
 * test fills $GLOBALS['softglaze_a11y_test_wc_urls'].
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

/**
 * Cart URL; the home URL when no cart page is set, as in WooCommerce.
 *
 * @return string
 */
function wc_get_cart_url() {
	$urls = isset( $GLOBALS['softglaze_a11y_test_wc_urls'] ) ? $GLOBALS['softglaze_a11y_test_wc_urls'] : array();
	return isset( $urls['cart'] ) ? $urls['cart'] : get_home_url();
}

/**
 * Page permalink; the fallback (home URL by default) when the page is not set, as in WooCommerce.
 *
 * @param string      $page     Page key.
 * @param string|null $fallback Fallback URL.
 * @return string
 */
function wc_get_page_permalink( $page, $fallback = null ) {
	$urls = isset( $GLOBALS['softglaze_a11y_test_wc_urls'] ) ? $GLOBALS['softglaze_a11y_test_wc_urls'] : array();
	if ( isset( $urls[ $page ] ) ) {
		return $urls[ $page ];
	}
	return null === $fallback ? get_home_url() : $fallback;
}
