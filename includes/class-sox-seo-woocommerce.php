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
		if ( Sox_SEO_Meta::is_native() ) {
			// Just before WooCommerce prints its structured data in the footer.
			add_action( 'wp_footer', array( __CLASS__, 'ensure_product_schema' ), 9 );
		}
	}

	/**
	 * Make sure a product page carries Product structured data.
	 *
	 * WooCommerce builds it from a hook inside its own product template.
	 * Product pages designed in Elementor or another builder never run that
	 * hook, so they would have no price or stock data for Google's product
	 * results. When WooCommerce has produced none, build it here.
	 */
	public static function ensure_product_schema() {
		if ( ! is_singular( 'product' ) || ! function_exists( 'WC' ) || empty( WC()->structured_data ) ) {
			return;
		}
		$structured = WC()->structured_data;
		foreach ( (array) $structured->get_data() as $item ) {
			$type = isset( $item['@type'] ) ? $item['@type'] : '';
			if ( 'Product' === $type || ( is_array( $type ) && in_array( 'Product', $type, true ) ) ) {
				return; // WooCommerce already has it.
			}
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( $product ) {
			$structured->generate_product_data( $product );
		}
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

		$markup = self::add_policies( $markup );

		/**
		 * Filter the Product structured data.
		 *
		 * @param array      $markup  Product JSON-LD.
		 * @param WC_Product $product Product.
		 */
		return apply_filters( 'sox_seo_product_schema', $markup, $product );
	}

	/**
	 * Add the shop's delivery and returns terms to each offer. Google shows
	 * them in product results and asks for both in Merchant Center.
	 *
	 * @param array $markup Product JSON-LD.
	 * @return array
	 */
	private static function add_policies( array $markup ) {
		$o = Sox_SEO_Options::all();
		if ( ( empty( $o['returns_enabled'] ) && empty( $o['shipping_enabled'] ) ) || empty( $markup['offers'] ) ) {
			return $markup;
		}
		$country  = $o['shipping_country'] ? $o['shipping_country'] : ( function_exists( 'WC' ) ? WC()->countries->get_base_country() : '' );
		$currency = get_woocommerce_currency();
		$single   = isset( $markup['offers']['@type'] );
		$offers   = $single ? array( $markup['offers'] ) : $markup['offers'];

		foreach ( $offers as &$offer ) {
			if ( ! is_array( $offer ) ) {
				continue;
			}
			if ( ! empty( $o['returns_enabled'] ) && $country ) {
				$offer['hasMerchantReturnPolicy'] = (int) $o['return_days'] > 0
					? array(
						'@type'                => 'MerchantReturnPolicy',
						'applicableCountry'    => $country,
						'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
						'merchantReturnDays'   => (int) $o['return_days'],
						'returnMethod'         => 'https://schema.org/ReturnByMail',
						'returnFees'           => 'free' === $o['return_fees'] ? 'https://schema.org/FreeReturn' : 'https://schema.org/ReturnFeesCustomerResponsibility',
					)
					: array(
						'@type'                => 'MerchantReturnPolicy',
						'applicableCountry'    => $country,
						'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
					);
			}
			if ( ! empty( $o['shipping_enabled'] ) && $country && '' !== $o['shipping_rate'] ) {
				$price = isset( $offer['price'] ) ? (float) $offer['price'] : null;
				$rate  = (float) $o['shipping_rate'];
				if ( '' !== $o['shipping_free_over'] && null !== $price && $price >= (float) $o['shipping_free_over'] ) {
					$rate = 0.0;
				}
				$offer['shippingDetails'] = array(
					'@type'               => 'OfferShippingDetails',
					'shippingRate'        => array(
						'@type'    => 'MonetaryAmount',
						'value'    => wc_format_decimal( $rate, wc_get_price_decimals() ),
						'currency' => $currency,
					),
					'shippingDestination' => array(
						'@type'          => 'DefinedRegion',
						'addressCountry' => $country,
					),
					'deliveryTime'        => array(
						'@type'        => 'ShippingDeliveryTime',
						'handlingTime' => array(
							'@type'    => 'QuantitativeValue',
							'minValue' => 0,
							'maxValue' => 1,
							'unitCode' => 'DAY',
						),
						'transitTime'  => array(
							'@type'    => 'QuantitativeValue',
							'minValue' => (int) $o['shipping_days_min'],
							'maxValue' => (int) $o['shipping_days_max'],
							'unitCode' => 'DAY',
						),
					),
				);
			}
		}
		unset( $offer );
		$markup['offers'] = $single ? $offers[0] : $offers;
		return $markup;
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
