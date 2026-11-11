<?php
/**
 * REST API used by the admin screens.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Rest {

	const NS = 'sox-seo/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$post_arg = array(
			'post_id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		register_rest_route(
			self::NS,
			'/meta',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'meta' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
				'args'                => $post_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/analyze',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'analyze' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
				'args'                => $post_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/links',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'links' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
				'args'                => $post_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/links/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'links_apply' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
				'args'                => $post_arg,
			)
		);
		foreach ( array( 'audit', 'faq', 'product-copy' ) as $route ) {
			register_rest_route(
				self::NS,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, str_replace( '-', '_', $route ) ),
					'permission_callback' => array( __CLASS__, 'can_edit_post' ),
					'args'                => $post_arg,
				)
			);
		}
		register_rest_route(
			self::NS,
			'/product-copy/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'product_copy_apply' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
				'args'                => $post_arg,
			)
		);
		$manage = array(
			'lang-meta'       => 'lang_meta',
			'term-copy'       => 'term_copy',
			'redirects/suggest' => 'redirects_suggest',
			'redirects/apply' => 'redirects_apply',
			'speed'           => 'speed',
			'bulk-edit'       => 'bulk_edit',
		);
		foreach ( $manage as $route => $callback ) {
			register_rest_route(
				self::NS,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => array( __CLASS__, in_array( $route, array( 'lang-meta', 'term-copy', 'bulk-edit' ), true ) ? 'can_edit_content' : 'can_manage' ),
				)
			);
		}
		register_rest_route(
			self::NS,
			'/link-audit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'link_audit' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/term-meta',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'term_meta' ),
				'permission_callback' => array( __CLASS__, 'can_edit_term' ),
				'args'                => array(
					'term_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'bulk' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_edit_post( $request ) {
		return current_user_can( 'edit_post', (int) $request['post_id'] );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_edit_term( $request ) {
		return current_user_can( 'edit_term', (int) $request['term_id'] );
	}

	/**
	 * Routes that take a post or a term: each handler checks the specific
	 * object before changing it.
	 *
	 * @return bool
	 */
	public static function can_edit_content() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Generate meta for the editor. Nothing is saved: the editor fills its
	 * fields and the author decides whether to keep them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function meta( $request ) {
		$post_id = (int) $request['post_id'];
		$meta    = Sox_SEO_AI::post_meta( $post_id );
		// When another SEO plugin owns the editor fields, there is nothing
		// for the browser to fill in, so the values are saved directly.
		if ( ! is_wp_error( $meta ) && $request['apply'] ) {
			if ( ! Sox_SEO_Meta::is_supported( 'post' ) ) {
				/* translators: %s: SEO plugin name */
				return new WP_Error( 'sox_seo_unsupported', sprintf( __( 'Writing meta into %s is not supported.', 'sox-seo' ), Sox_SEO_Meta::provider_label() ), array( 'status' => 400 ) );
			}
			Sox_SEO_AI::apply_post_meta( $post_id, $meta, true );
		}
		return $meta;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function analyze( $request ) {
		$overrides = array();
		foreach ( array( 'title', 'description', 'focus_keyword' ) as $field ) {
			if ( null !== $request[ $field ] ) {
				$overrides[ $field ] = sanitize_text_field( (string) $request[ $field ] );
			}
		}
		return Sox_SEO_Analyzer::analyze( (int) $request['post_id'], $overrides ? $overrides : null );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function links( $request ) {
		$post_id = (int) $request['post_id'];
		$links   = Sox_SEO_AI::link_suggestions( $post_id );
		if ( is_wp_error( $links ) ) {
			return $links;
		}
		return array(
			'links'     => $links,
			'can_apply' => true,
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function links_apply( $request ) {
		$links = array();
		foreach ( (array) $request['links'] as $link ) {
			if ( is_array( $link ) && isset( $link['target_id'], $link['anchor_text'] ) ) {
				$links[] = array(
					'target_id'   => (int) $link['target_id'],
					'anchor_text' => sanitize_text_field( (string) $link['anchor_text'] ),
				);
			}
		}
		$inserted = Sox_SEO_Links::apply( (int) $request['post_id'], $links );
		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}
		return array( 'inserted' => $inserted );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function audit( $request ) {
		return Sox_SEO_AI::audit( (int) $request['post_id'] );
	}

	/**
	 * Detect FAQs on the page, or clear the saved ones.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function faq( $request ) {
		$post_id = (int) $request['post_id'];
		if ( $request['clear'] ) {
			delete_post_meta( $post_id, '_sox_seo_faq' );
			return array( 'faqs' => array() );
		}
		$faqs = Sox_SEO_AI::faqs( $post_id );
		return is_wp_error( $faqs ) ? $faqs : array( 'faqs' => $faqs );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function product_copy( $request ) {
		return Sox_SEO_AI::product_copy( (int) $request['post_id'] );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function product_copy_apply( $request ) {
		$allowed = array(
			'p'      => array(),
			'h3'     => array(),
			'ul'     => array(),
			'li'     => array(),
			'strong' => array(),
		);
		$result = Sox_SEO_AI::apply_product_copy(
			(int) $request['post_id'],
			sanitize_textarea_field( (string) $request['short_description'] ),
			trim( wp_kses( (string) $request['description'], $allowed ) )
		);
		return is_wp_error( $result ) ? $result : array( 'saved' => true );
	}

	/**
	 * Generate, and optionally save, SEO text in an extra language.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function lang_meta( $request ) {
		$kind = 'term' === $request['kind'] ? 'term' : 'post';
		$id   = (int) $request['id'];
		$lang = sanitize_key( (string) $request['lang'] );
		if ( ! current_user_can( 'term' === $kind ? 'edit_term' : 'edit_post', $id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to edit this.', 'sox-seo' ), array( 'status' => 403 ) );
		}
		return Sox_SEO_AI::lang_meta( $kind, $id, $lang );
	}

	/**
	 * Generate a category introduction, or save one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function term_copy( $request ) {
		$term_id = (int) $request['term_id'];
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to edit this.', 'sox-seo' ), array( 'status' => 403 ) );
		}
		if ( null !== $request['html'] ) {
			$html   = trim( wp_kses( (string) $request['html'], array( 'p' => array(), 'strong' => array() ) ) );
			$result = Sox_SEO_AI::apply_term_copy( $term_id, $html );
			return is_wp_error( $result ) ? $result : array( 'saved' => true );
		}
		$html = Sox_SEO_AI::term_copy( $term_id );
		return is_wp_error( $html ) ? $html : array( 'html' => $html );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function redirects_suggest() {
		$suggestions = Sox_SEO_AI::redirect_suggestions();
		return is_wp_error( $suggestions ) ? $suggestions : array( 'redirects' => $suggestions );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function redirects_apply( $request ) {
		$added = 0;
		foreach ( (array) $request['redirects'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['url'] ) || empty( $item['target_url'] ) ) {
				continue;
			}
			$target = esc_url_raw( (string) $item['target_url'] );
			// Suggestions only ever point at this site.
			if ( wp_parse_url( $target, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				continue;
			}
			if ( true === Sox_SEO_Redirects::add( sanitize_text_field( (string) $item['url'] ), $target ) ) {
				$added++;
			}
		}
		return array( 'added' => $added );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function speed( $request ) {
		$result = Sox_SEO_Speed::test( (string) $request['url'] );
		return is_wp_error( $result ) ? new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) ) : $result;
	}

	/**
	 * Save edited rows from the bulk editor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function bulk_edit( $request ) {
		$saved = 0;
		foreach ( (array) $request['rows'] as $row ) {
			$id = is_array( $row ) && isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! Sox_SEO_Meta::is_supported( 'post' ) ) {
				continue;
			}
			foreach ( array( 'title', 'focus_keyword' ) as $field ) {
				if ( isset( $row[ $field ] ) ) {
					Sox_SEO_Meta::set_post( $id, $field, sanitize_text_field( (string) $row[ $field ] ) );
				}
			}
			if ( isset( $row['description'] ) ) {
				Sox_SEO_Meta::set_post( $id, 'description', sanitize_textarea_field( (string) $row['description'] ) );
			}
			foreach ( (array) ( isset( $row['lang'] ) ? $row['lang'] : array() ) as $lang => $fields ) {
				foreach ( array( 'title', 'description' ) as $field ) {
					if ( isset( $fields[ $field ] ) ) {
						Sox_SEO_Lang::set( 'post', $id, $field, sanitize_key( $lang ), sanitize_textarea_field( (string) $fields[ $field ] ) );
					}
				}
			}
			$saved++;
		}
		return array( 'saved' => $saved );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function link_audit( $request ) {
		return Sox_SEO_Link_Audit::step( (bool) $request['restart'] );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function term_meta( $request ) {
		$term_id = (int) $request['term_id'];
		$meta    = Sox_SEO_AI::term_meta( $term_id );
		if ( ! is_wp_error( $meta ) && $request['apply'] && Sox_SEO_Meta::is_supported( 'term' ) ) {
			Sox_SEO_AI::apply_term_meta( $term_id, $meta, true );
		}
		return $meta;
	}

	/**
	 * Process one item of a bulk run. The browser calls this in a loop, so a
	 * run of any size never hits a server time limit, and closing the tab
	 * stops it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function bulk( $request ) {
		$task      = in_array( $request['task'], array( 'alt', 'product_copy', 'lang_meta', 'term_copy' ), true ) ? $request['task'] : 'meta';
		$post_type = sanitize_key( (string) $request['post_type'] );
		$exclude   = array_map( 'absint', (array) $request['exclude'] );
		if ( 'lang_meta' === $task || 'term_copy' === $task ) {
			return self::bulk_extra( $task, sanitize_key( (string) $request['lang'] ), $exclude );
		}

		if ( 'meta' === $task ) {
			if ( ! Sox_SEO_Meta::is_supported( 'post' ) ) {
				/* translators: %s: SEO plugin name */
				return new WP_Error( 'sox_seo_unsupported', sprintf( __( 'Writing meta into %s is not supported.', 'sox-seo' ), Sox_SEO_Meta::provider_label() ), array( 'status' => 400 ) );
			}
			$types = ( $post_type && in_array( $post_type, Sox_SEO_Meta::post_types(), true ) ) ? array( $post_type ) : array();
			$ids   = Sox_SEO_Automation::missing_meta( 1, $exclude, $types );
		} elseif ( 'product_copy' === $task ) {
			$ids = Sox_SEO_Automation::products_without_copy( 1, $exclude );
		} else {
			$ids = Sox_SEO_Images::missing_alt( 1, $exclude );
		}

		if ( ! $ids ) {
			return array(
				'done'      => true,
				'remaining' => 0,
			);
		}

		$id     = (int) $ids[0];
		if ( 'meta' === $task ) {
			$result = Sox_SEO_Automation::generate_meta( $id );
		} elseif ( 'product_copy' === $task ) {
			$result = Sox_SEO_Automation::generate_product_copy( $id );
		} else {
			$result = Sox_SEO_Images::generate( $id );
		}
		$error  = is_wp_error( $result ) ? $result->get_error_message() : '';

		// A missing or rejected key will fail for every item: stop the run.
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'sox_seo_no_key', 'sox_seo_api_401', 'sox_seo_api_403' ), true ) ) {
			return new WP_Error( $result->get_error_code(), $error, array( 'status' => 400 ) );
		}

		if ( 'meta' === $task ) {
			$output = is_array( $result ) ? $result['title'] . ' — ' . $result['description'] : '';
		} else {
			$output = is_string( $result ) ? $result : '';
		}
		if ( '' === $error && '' === trim( $output, " \u{2014}" ) ) {
			$error = __( 'Claude returned nothing usable for this item.', 'sox-seo' );
		}

		return array(
			'done'      => false,
			'id'        => $id,
			'title'     => wp_strip_all_tags( get_the_title( $id ) ),
			'edit_link' => get_edit_post_link( $id, 'raw' ),
			'output'    => $output,
			'error'     => $error,
			'remaining' => Sox_SEO_Automation::remaining( $task, $post_type ),
		);
	}

	/**
	 * Bulk steps for second-language meta and category introductions.
	 *
	 * @param string $task    lang_meta|term_copy.
	 * @param string $lang    Language slug, for lang_meta.
	 * @param int[]  $exclude IDs that failed earlier in this run.
	 * @return array|WP_Error
	 */
	private static function bulk_extra( $task, $lang, array $exclude ) {
		if ( 'lang_meta' === $task ) {
			$query = Sox_SEO_Lang::missing_query( $lang, 1, $exclude );
			$ids   = $query->posts;
		} else {
			$ids = Sox_SEO_Automation::terms_without_copy( 1, $exclude );
		}
		if ( ! $ids ) {
			return array(
				'done'      => true,
				'remaining' => 0,
			);
		}
		$id = (int) $ids[0];

		if ( 'lang_meta' === $task ) {
			$result = Sox_SEO_AI::lang_meta( 'post', $id, $lang );
			if ( ! is_wp_error( $result ) && '' !== $result['description'] ) {
				Sox_SEO_Lang::set( 'post', $id, 'title', $lang, $result['title'] );
				Sox_SEO_Lang::set( 'post', $id, 'description', $lang, $result['description'] );
			}
			$output    = is_wp_error( $result ) ? '' : $result['title'] . ' — ' . $result['description'];
			$title     = wp_strip_all_tags( get_the_title( $id ) );
			$edit      = get_edit_post_link( $id, 'raw' );
			$remaining = Sox_SEO_Lang::missing_query( $lang, 1 )->found_posts;
		} else {
			$term   = get_term( $id );
			$result = Sox_SEO_AI::term_copy( $id );
			if ( ! is_wp_error( $result ) ) {
				$saved  = Sox_SEO_AI::apply_term_copy( $id, $result );
				$result = is_wp_error( $saved ) ? $saved : $result;
			}
			$output    = is_wp_error( $result ) ? '' : wp_trim_words( wp_strip_all_tags( $result ), 30 );
			$title     = $term && ! is_wp_error( $term ) ? $term->name : '#' . $id;
			$edit      = $term && ! is_wp_error( $term ) ? get_edit_term_link( $term ) : '';
			$remaining = count( Sox_SEO_Automation::terms_without_copy( 500 ) );
		}

		$error = is_wp_error( $result ) ? $result->get_error_message() : '';
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'sox_seo_no_key', 'sox_seo_api_401', 'sox_seo_api_403', 'sox_seo_lang' ), true ) ) {
			return new WP_Error( $result->get_error_code(), $error, array( 'status' => 400 ) );
		}
		if ( '' === $error && '' === $output ) {
			$error = __( 'Claude returned nothing usable for this item.', 'sox-seo' );
		}
		return array(
			'done'      => false,
			'id'        => $id,
			'title'     => $title,
			'edit_link' => $edit,
			'output'    => $output,
			'error'     => $error,
			'remaining' => (int) $remaining,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function test() {
		$result = Sox_SEO_Anthropic::test();
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return array(
			'ok'    => true,
			'model' => Sox_SEO_Options::get( 'model' ),
		);
	}
}
