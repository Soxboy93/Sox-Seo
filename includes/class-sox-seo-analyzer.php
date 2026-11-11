<?php
/**
 * On-page SEO analysis. Runs locally, no API calls.
 *
 * @package Sox_SEO
 */

defined( 'ABSPATH' ) || exit;

class Sox_SEO_Analyzer {

	/**
	 * Score a post against the standard on-page checks.
	 *
	 * @param int        $post_id   Post ID.
	 * @param array|null $overrides Unsaved values from the editor: title, description, focus_keyword.
	 * @return array {score:int, checks: array<{status:string,label:string}>}
	 */
	public static function analyze( $post_id, $overrides = null ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'score'  => 0,
				'checks' => array(),
			);
		}

		$get = static function ( $field ) use ( $post_id, $overrides ) {
			if ( is_array( $overrides ) && isset( $overrides[ $field ] ) ) {
				return trim( (string) $overrides[ $field ] );
			}
			return Sox_SEO_Meta::get_post( $post_id, $field );
		};

		$title       = $get( 'title' );
		$description = $get( 'description' );
		$keyword     = $get( 'focus_keyword' );
		// Rank Math stores several comma-separated keywords; the first is primary.
		$keyword = trim( (string) strtok( $keyword, ',' ) );

		$html  = $post->post_content;
		$text  = Sox_SEO_AI::plain_content( $post );
		$words = self::word_count( $text );

		$checks = array();
		$add    = static function ( $status, $label, $weight = 1 ) use ( &$checks ) {
			$checks[] = array(
				'status' => $status,
				'label'  => $label,
				'weight' => $weight,
			);
		};

		// Title.
		$len = mb_strlen( $title );
		if ( 0 === $len ) {
			$add( 'bad', __( 'No SEO title set. The post title is used as-is.', 'sox-seo' ), 2 );
		} elseif ( $len > 60 ) {
			/* translators: %d: character count */
			$add( 'ok', sprintf( __( 'SEO title is %d characters; Google usually cuts titles after about 60.', 'sox-seo' ), $len ), 2 );
		} elseif ( $len < 25 ) {
			/* translators: %d: character count */
			$add( 'ok', sprintf( __( 'SEO title is only %d characters. There is room to say more.', 'sox-seo' ), $len ), 2 );
		} else {
			$add( 'good', __( 'SEO title length is good.', 'sox-seo' ), 2 );
		}

		// Description.
		$len = mb_strlen( $description );
		if ( 0 === $len ) {
			$add( 'bad', __( 'No meta description. Google will pick its own snippet.', 'sox-seo' ), 2 );
		} elseif ( $len > 160 ) {
			/* translators: %d: character count */
			$add( 'ok', sprintf( __( 'Meta description is %d characters and will be cut off after about 160.', 'sox-seo' ), $len ), 2 );
		} elseif ( $len < 100 ) {
			/* translators: %d: character count */
			$add( 'ok', sprintf( __( 'Meta description is only %d characters. Aim for 120 to 155.', 'sox-seo' ), $len ), 2 );
		} else {
			$add( 'good', __( 'Meta description length is good.', 'sox-seo' ), 2 );
		}

		// Focus keyword placement.
		if ( '' === $keyword ) {
			$add( 'bad', __( 'No focus keyword set.', 'sox-seo' ), 2 );
		} else {
			$effective_title = '' !== $title ? $title : $post->post_title;
			$add(
				self::contains( $effective_title, $keyword ) ? 'good' : 'bad',
				self::contains( $effective_title, $keyword ) ? __( 'Focus keyword appears in the SEO title.', 'sox-seo' ) : __( 'Focus keyword is missing from the SEO title.', 'sox-seo' ),
				2
			);
			$add(
				self::contains( $description, $keyword ) ? 'good' : 'ok',
				self::contains( $description, $keyword ) ? __( 'Focus keyword appears in the meta description.', 'sox-seo' ) : __( 'Focus keyword is missing from the meta description.', 'sox-seo' )
			);

			$slug = str_replace( '-', ' ', rawurldecode( $post->post_name ) );
			$add(
				self::contains( $slug, $keyword ) ? 'good' : 'ok',
				self::contains( $slug, $keyword ) ? __( 'Focus keyword appears in the URL.', 'sox-seo' ) : __( 'Focus keyword is not in the URL.', 'sox-seo' )
			);

			$intro = mb_substr( $text, 0, 600 );
			$add(
				self::contains( $intro, $keyword ) ? 'good' : 'ok',
				self::contains( $intro, $keyword ) ? __( 'Focus keyword appears near the start of the content.', 'sox-seo' ) : __( 'Focus keyword does not appear near the start of the content.', 'sox-seo' )
			);

			if ( $words >= 100 ) {
				$count   = self::occurrences( $text, $keyword );
				$kw_len  = max( 1, self::word_count( $keyword ) );
				$density = $count * $kw_len / $words * 100;
				if ( 0 === $count ) {
					$add( 'bad', __( 'Focus keyword never appears in the content.', 'sox-seo' ) );
				} elseif ( $density > 3 ) {
					/* translators: %s: density percentage */
					$add( 'ok', sprintf( __( 'Keyword density is %s%%, which reads as stuffing. Use it less.', 'sox-seo' ), number_format_i18n( $density, 1 ) ) );
				} else {
					/* translators: %d: number of times */
					$add( 'good', sprintf( _n( 'Focus keyword appears %d time in the content.', 'Focus keyword appears %d times in the content.', $count, 'sox-seo' ), $count ) );
				}
			}

			if ( preg_match_all( '#<h[2-4][^>]*>(.*?)</h[2-4]>#is', $html, $headings ) ) {
				$found = false;
				foreach ( $headings[1] as $heading ) {
					if ( self::contains( wp_strip_all_tags( $heading ), $keyword ) ) {
						$found = true;
						break;
					}
				}
				$add(
					$found ? 'good' : 'ok',
					$found ? __( 'Focus keyword appears in a subheading.', 'sox-seo' ) : __( 'Focus keyword does not appear in any subheading.', 'sox-seo' )
				);
			}
		}

		// Content length. Products are judged more leniently than articles.
		$minimum = 'product' === $post->post_type ? 100 : 300;
		if ( $words >= $minimum ) {
			/* translators: %d: word count */
			$add( 'good', sprintf( __( 'Content is %d words long.', 'sox-seo' ), $words ) );
		} else {
			/* translators: 1: word count, 2: recommended minimum */
			$add( 'ok', sprintf( __( 'Content is %1$d words. Pages under %2$d words rarely rank on their own.', 'sox-seo' ), $words, $minimum ) );
		}

		// Images.
		if ( preg_match_all( '#<img\b[^>]*>#i', $html, $images ) ) {
			$missing = 0;
			foreach ( $images[0] as $img ) {
				if ( ! preg_match( '#\balt=("|\')(.*?)\1#is', $img, $alt ) || '' === trim( $alt[2] ) ) {
					$missing++;
				}
			}
			if ( $missing ) {
				/* translators: %d: number of images */
				$add( 'ok', sprintf( _n( '%d image in the content has no alt text.', '%d images in the content have no alt text.', $missing, 'sox-seo' ), $missing ) );
			} else {
				$add( 'good', __( 'All images in the content have alt text.', 'sox-seo' ) );
			}
		} elseif ( ! has_post_thumbnail( $post ) ) {
			$add( 'ok', __( 'No images and no featured image.', 'sox-seo' ) );
		}

		// Links.
		$internal = 0;
		$external = 0;
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( preg_match_all( '#<a\b[^>]*\bhref=("|\')(.*?)\1#i', $html, $links ) ) {
			foreach ( $links[2] as $href ) {
				$link_host = wp_parse_url( $href, PHP_URL_HOST );
				if ( ! $link_host || $link_host === $host ) {
					if ( 0 !== strpos( $href, '#' ) ) {
						$internal++;
					}
				} else {
					$external++;
				}
			}
		}
		$add(
			$internal ? 'good' : 'ok',
			/* translators: %d: number of links */
			$internal ? sprintf( _n( '%d internal link in the content.', '%d internal links in the content.', $internal, 'sox-seo' ), $internal ) : __( 'No internal links in the content. Link to related pages.', 'sox-seo' )
		);

		// Score.
		$total  = 0;
		$earned = 0;
		foreach ( $checks as $check ) {
			$total += $check['weight'];
			if ( 'good' === $check['status'] ) {
				$earned += $check['weight'];
			} elseif ( 'ok' === $check['status'] ) {
				$earned += $check['weight'] / 2;
			}
		}

		return array(
			'score'  => $total ? (int) round( $earned / $total * 100 ) : 0,
			'checks' => $checks,
		);
	}

	/**
	 * Unicode-aware, case-insensitive containment.
	 *
	 * @param string $haystack Text.
	 * @param string $needle   Phrase.
	 * @return bool
	 */
	private static function contains( $haystack, $needle ) {
		return '' !== $needle && false !== mb_stripos( $haystack, $needle );
	}

	/**
	 * @param string $text   Text.
	 * @param string $phrase Phrase.
	 * @return int
	 */
	private static function occurrences( $text, $phrase ) {
		return mb_substr_count( mb_strtolower( $text ), mb_strtolower( $phrase ) );
	}

	/**
	 * Word count that works for non-Latin scripts such as Arabic.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function word_count( $text ) {
		return (int) preg_match_all( '/[\p{L}\p{N}]+/u', $text );
	}
}
