<?php
/**
 * Link SEO: internal link candidates and insertion, external link attributes.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Links {

	public static function init() {
		if ( Sox_SEO_Options::get( 'external_new_tab' ) || Sox_SEO_Options::get( 'external_nofollow' ) ) {
			add_filter( 'the_content', array( __CLASS__, 'external_links' ), 99 );
		}
	}

	/**
	 * Pages this post could link to: related by taxonomy first, then recent.
	 * Pages it already links to, and noindexed pages, are left out.
	 *
	 * @param WP_Post $post Source post.
	 * @return array<int,array{title:string,url:string,keyword:string}>
	 */
	public static function candidates( $post ) {
		$types = Sox_SEO_Meta::post_types();
		$ids   = array();

		$tax_query = array( 'relation' => 'OR' );
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'terms'    => $terms,
				);
			}
		}
		if ( count( $tax_query ) > 1 ) {
			$ids = get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'posts_per_page' => 30,
					'fields'         => 'ids',
					'post__not_in'   => array( $post->ID ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
					'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				)
			);
		}

		$recent = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => 40,
				'fields'         => 'ids',
				'post__not_in'   => array( $post->ID ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'orderby'        => 'modified',
			)
		);
		// Pages nothing links to yet go first: they gain the most from a link.
		$orphans = array_diff( Sox_SEO_Link_Audit::orphans( 15 ), array( $post->ID ) );
		$ids     = array_slice( array_unique( array_merge( $orphans, $ids, $recent ) ), 0, 60 );

		// Elementor pages keep their links outside post_content.
		$existing  = $post->post_content;
		$elementor = get_post_meta( $post->ID, '_elementor_data', true );
		if ( is_string( $elementor ) ) {
			$existing .= str_replace( '\\/', '/', $elementor );
		}

		// Cart, checkout and account pages are never worth linking to.
		$skip = Sox_SEO_Meta::utility_page_ids();

		$out = array();
		foreach ( $ids as $id ) {
			if ( in_array( (int) $id, $skip, true ) ) {
				continue;
			}
			$url = get_permalink( $id );
			if ( ! $url || Sox_SEO_Meta::get_post( $id, 'noindex' ) ) {
				continue;
			}
			// Already linked from this post.
			if ( false !== strpos( $existing, untrailingslashit( $url ) ) ) {
				continue;
			}
			$out[ $id ] = array(
				'title'   => wp_strip_all_tags( get_the_title( $id ) ),
				'url'     => $url,
				'keyword' => trim( (string) strtok( Sox_SEO_Meta::get_post( $id, 'focus_keyword' ), ',' ) ),
				'orphan'  => in_array( (int) $id, $orphans, true ),
			);
		}
		return $out;
	}

	/**
	 * Whether a post's visible content is stored in post_content. Page
	 * builders such as Elementor keep it elsewhere, so editing post_content
	 * there would change nothing on the page.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_edit_content( $post_id ) {
		return 'builder' !== get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	/**
	 * Insert links into a post's content. Each anchor is linked once, at its
	 * first occurrence outside existing links, headings and buttons. The
	 * change is saved as a normal revision, so it can be rolled back.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $links   List of {target_id, anchor_text}.
	 * @return int|WP_Error Number of links inserted.
	 */
	public static function apply( $post_id, array $links ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'sox_seo_not_found', __( 'Post not found.', 'sox-seo' ) );
		}
		if ( ! self::can_edit_content( $post_id ) ) {
			return self::apply_elementor( $post_id, $links );
		}

		$content  = $post->post_content;
		$inserted = 0;
		foreach ( $links as $link ) {
			$target = isset( $link['target_id'] ) ? (int) $link['target_id'] : 0;
			$anchor = isset( $link['anchor_text'] ) ? trim( (string) $link['anchor_text'] ) : '';
			$url    = $target ? get_permalink( $target ) : '';
			if ( ! $url || '' === $anchor || 'publish' !== get_post_status( $target ) ) {
				continue;
			}
			$updated = self::link_first_occurrence( $content, $anchor, $url );
			if ( null !== $updated ) {
				$content = $updated;
				$inserted++;
			}
		}

		if ( $inserted ) {
			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $content ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return $inserted;
	}

	/**
	 * Insert links into an Elementor page by editing the text of its Text
	 * Editor widgets. The previous layout data is kept in post meta so the
	 * change can be undone.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $links   List of {target_id, anchor_text}.
	 * @return int|WP_Error Number of links inserted.
	 */
	private static function apply_elementor( $post_id, array $links ) {
		// Elementor filters its layout data on save for anyone who may not
		// post unfiltered HTML, removing <style>, <svg> and similar from every
		// widget on the page. Saving as such a user would damage the design.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return new WP_Error( 'sox_seo_builder', __( 'Only an administrator or editor can add links to an Elementor page from here. Add them in the Elementor editor instead.', 'sox-seo' ) );
		}
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'sox_seo_builder', __( 'This Elementor page could not be read. Add the links in the Elementor editor.', 'sox-seo' ) );
		}

		$inserted = 0;
		foreach ( $links as $link ) {
			$target = isset( $link['target_id'] ) ? (int) $link['target_id'] : 0;
			$anchor = isset( $link['anchor_text'] ) ? trim( (string) $link['anchor_text'] ) : '';
			$url    = $target ? get_permalink( $target ) : '';
			if ( ! $url || '' === $anchor || 'publish' !== get_post_status( $target ) ) {
				continue;
			}
			if ( self::link_in_elementor( $data, $anchor, $url ) ) {
				$inserted++;
			}
		}

		if ( $inserted ) {
			update_post_meta( $post_id, '_sox_seo_elementor_backup', wp_slash( $raw ) );
			update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			// Make Elementor rebuild its cached copy of the page.
			delete_post_meta( $post_id, '_elementor_element_cache' );
			delete_post_meta( $post_id, '_elementor_css' );
			clean_post_cache( $post_id );
		}
		return $inserted;
	}

	/**
	 * Walk the Elementor element tree and link the phrase in the first Text
	 * Editor widget that contains it.
	 *
	 * @param array  $elements Elementor elements, edited in place.
	 * @param string $phrase   Anchor text.
	 * @param string $url      Link target.
	 * @return bool
	 */
	private static function link_in_elementor( array &$elements, $phrase, $url ) {
		foreach ( $elements as &$element ) {
			if ( isset( $element['widgetType'] ) && 'text-editor' === $element['widgetType'] && isset( $element['settings']['editor'] ) && is_string( $element['settings']['editor'] ) ) {
				$updated = self::link_first_occurrence( $element['settings']['editor'], $phrase, $url );
				if ( null !== $updated ) {
					$element['settings']['editor'] = $updated;
					return true;
				}
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && self::link_in_elementor( $element['elements'], $phrase, $url ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Wrap the first plain-text occurrence of a phrase in a link.
	 *
	 * @param string $html   Post content.
	 * @param string $phrase Anchor text.
	 * @param string $url    Link target.
	 * @return string|null Updated HTML, or null if the phrase was not found.
	 */
	public static function link_first_occurrence( $html, $phrase, $url ) {
		$tokens  = preg_split( '/(<!--.*?-->|<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$blocked = 0; // Depth inside elements whose text must not be linked.
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu';
		$skip    = '(?:a|h[1-6]|script|style|button|textarea|select|code|pre|figcaption)';

		foreach ( $tokens as $i => $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( '<' === $token[0] ) {
				if ( preg_match( '#^<' . $skip . '\b#i', $token ) && '/>' !== substr( $token, -2 ) ) {
					$blocked++;
				} elseif ( preg_match( '#^</' . $skip . '\s*>#i', $token ) ) {
					$blocked = max( 0, $blocked - 1 );
				}
				continue;
			}
			if ( $blocked ) {
				continue;
			}
			if ( preg_match( $pattern, $token, $match, PREG_OFFSET_CAPTURE ) ) {
				$found        = $match[0][0];
				$offset       = $match[0][1];
				$link         = '<a href="' . esc_url( $url ) . '">' . $found . '</a>';
				$tokens[ $i ] = substr( $token, 0, $offset ) . $link . substr( $token, $offset + strlen( $found ) );
				return implode( '', $tokens );
			}
		}
		return null;
	}

	/**
	 * Add target/rel attributes to links that leave the site.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function external_links( $content ) {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || false === stripos( $content, '<a ' ) ) {
			return $content;
		}
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$new_tab  = Sox_SEO_Options::get( 'external_new_tab' );
		$nofollow = Sox_SEO_Options::get( 'external_nofollow' );
		$tags     = new WP_HTML_Tag_Processor( $content );

		while ( $tags->next_tag( 'a' ) ) {
			$href = $tags->get_attribute( 'href' );
			if ( ! is_string( $href ) || ! preg_match( '#^https?://#i', $href ) ) {
				continue;
			}
			$link_host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $link_host || $link_host === $host ) {
				continue;
			}
			$rel   = $tags->get_attribute( 'rel' );
			$rel   = is_string( $rel ) ? preg_split( '/\s+/', trim( $rel ), -1, PREG_SPLIT_NO_EMPTY ) : array();
			if ( $new_tab ) {
				$tags->set_attribute( 'target', '_blank' );
				$rel[] = 'noopener';
			}
			// Leave sponsored / ugc links as the author marked them.
			if ( $nofollow && ! array_intersect( $rel, array( 'sponsored', 'ugc', 'follow' ) ) ) {
				$rel[] = 'nofollow';
			}
			$rel = array_unique( array_diff( $rel, array( 'follow' ) ) );
			if ( $rel ) {
				$tags->set_attribute( 'rel', implode( ' ', $rel ) );
			}
		}
		return $tags->get_updated_html();
	}
}
