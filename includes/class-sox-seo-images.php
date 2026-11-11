<?php
/**
 * Image SEO: AI alt text on upload and alt-text fallbacks on output.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Images {

	public static function init() {
		add_action( 'add_attachment', array( __CLASS__, 'on_upload' ) );
		add_action( 'sox_seo_generate_alt', array( __CLASS__, 'generate' ) );

		if ( Sox_SEO_Options::get( 'alt_fallback' ) ) {
			add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'attachment_alt' ), 20, 2 );
			add_filter( 'wp_content_img_tag', array( __CLASS__, 'content_img_alt' ), 20, 3 );
		}
	}

	/**
	 * Queue alt-text generation shortly after upload, in the background, so
	 * the upload itself is never slowed down by an API call.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function on_upload( $attachment_id ) {
		if ( ! Sox_SEO_Options::get( 'auto_alt_on_upload' ) || '' === Sox_SEO_Options::api_key() ) {
			return;
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}
		wp_schedule_single_event( time() + 30, 'sox_seo_generate_alt', array( (int) $attachment_id ) );
	}

	/**
	 * Generate and save alt text for one image.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force         Replace existing alt text.
	 * @return string|WP_Error The alt text now saved.
	 */
	public static function generate( $attachment_id, $force = false ) {
		$attachment_id = (int) $attachment_id;
		$current       = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' !== trim( $current ) && ! $force ) {
			return $current;
		}
		$alt = Sox_SEO_AI::alt_text( $attachment_id );
		if ( is_wp_error( $alt ) ) {
			update_post_meta( $attachment_id, '_sox_seo_alt_error', $alt->get_error_message() );
			return $alt;
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		delete_post_meta( $attachment_id, '_sox_seo_alt_error' );
		return $alt;
	}

	/**
	 * Best available alt text for an attachment without calling the API.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function fallback( $attachment_id ) {
		$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		if ( '' !== $alt ) {
			return $alt;
		}
		// On a single post or product, the page title describes its images
		// better than a camera file name does.
		if ( is_singular() ) {
			return wp_strip_all_tags( get_the_title( get_queried_object_id() ) );
		}
		$parent = wp_get_post_parent_id( $attachment_id );
		return $parent ? wp_strip_all_tags( get_the_title( $parent ) ) : '';
	}

	/**
	 * @param array   $attr       Image attributes.
	 * @param WP_Post $attachment Attachment.
	 * @return array
	 */
	public static function attachment_alt( $attr, $attachment ) {
		if ( empty( $attr['alt'] ) ) {
			$alt = self::fallback( $attachment->ID );
			if ( '' !== $alt ) {
				$attr['alt'] = $alt;
			}
		}
		return $attr;
	}

	/**
	 * @param string $image         The img tag.
	 * @param string $context       Filter context.
	 * @param int    $attachment_id Attachment ID, 0 if unknown.
	 * @return string
	 */
	public static function content_img_alt( $image, $context, $attachment_id ) {
		if ( ! $attachment_id || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $image;
		}
		$tags = new WP_HTML_Tag_Processor( $image );
		if ( ! $tags->next_tag( 'img' ) ) {
			return $image;
		}
		$alt = $tags->get_attribute( 'alt' );
		if ( is_string( $alt ) && '' !== trim( $alt ) ) {
			return $image;
		}
		$fallback = self::fallback( (int) $attachment_id );
		if ( '' === $fallback ) {
			return $image;
		}
		$tags->set_attribute( 'alt', $fallback );
		return $tags->get_updated_html();
	}

	/**
	 * IDs of images in the media library with no alt text.
	 *
	 * @param int   $limit   Max IDs.
	 * @param int[] $exclude IDs to skip.
	 * @return int[]
	 */
	public static function missing_alt( $limit = 1, array $exclude = array() ) {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'post__not_in'   => $exclude, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_wp_attachment_image_alt',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_wp_attachment_image_alt',
						'value' => '',
					),
				),
			)
		);
	}
}
