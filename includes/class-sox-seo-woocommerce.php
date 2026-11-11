<?php
/**
 * WooCommerce: enrich the Product structured data WooCommerce already prints.
 *
 * Google's product rich results (price, availability, rating stars) ask for a
 * brand and a product identifier. WooCommerce leaves both out by default.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_WooCommerce {

	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'product_schema' ), 20, 2 );
	}

	/**
	 * @param array      $markup  Product JSON-LD.
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function product_schema( $markup, $product ) {
		if ( ! is_array( $markup ) || ! $product instanceof WC_Product ) {
			return $markup;
		}

		if ( empty( $markup['brand'] ) ) {
			$brand = self::brand( $product );
			if ( $brand ) {
				$markup['brand'] = array(
					'@type' => 'Brand',
					'name'  => $brand,
				);
			}
		}

		// GTIN / UPC / EAN, stored by WooCommerce 9.2+ as the "global unique ID".
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$gtin = preg_replace( '/\D/', '', (string) $product->get_global_unique_id() );
			if ( $gtin && empty( $markup['gtin'] ) ) {
				$markup['gtin'] = $gtin;
			}
		}
		if ( empty( $markup['mpn'] ) && empty( $markup['gtin'] ) && $product->get_sku() ) {
			$markup['mpn'] = $product->get_sku();
		}

		// All product images, not only the featured one.
		$images = array();
		foreach ( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) as $image_id ) {
			$src = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
			if ( $src ) {
				$images[] = $src;
			}
		}
		if ( count( $images ) > 1 ) {
			$markup['image'] = array_values( array_unique( $images ) );
		}

		// Prefer the written meta description over a raw excerpt.
		$description = Sox_SEO_Meta::get_post( $product->get_id(), 'description' );
		if ( $description && false === strpos( $description, '%' ) ) {
			$markup['description'] = $description;
		}

		/**
		 * Filter the Product structured data.
		 *
		 * @param array      $markup  Product JSON-LD.
		 * @param WC_Product $product Product.
		 */
		return apply_filters( 'sox_seo_product_schema', $markup, $product );
	}

	/**
	 * Brand name from the brand taxonomy, a "Brand" attribute, or the default
	 * brand set in the plugin settings.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function brand( $product ) {
		foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$names = wp_get_post_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $names ) && $names ) {
					return (string) $names[0];
				}
			}
		}
		foreach ( array( 'pa_brand', 'brand' ) as $attribute ) {
			$value = $product->get_attribute( $attribute );
			if ( $value ) {
				return trim( (string) strtok( $value, ',' ) );
			}
		}
		return (string) Sox_SEO_Options::get( 'default_brand' );
	}
}
